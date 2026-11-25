<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockReservations;
use App\Modules\Invoicing\InitialInvoices;
use App\Modules\Orders\CheckoutOrders;
use App\Modules\Orders\CompleteDeliveredOrders;
use App\Modules\Shipping\FakeTrackingProvider;
use App\Modules\Shipping\Packages;
use App\Modules\Shipping\PollTracking;
use App\Support\Http\ApiProblem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);
beforeEach(function () {
    $this->prepareCheckout();
    $this->seed(PermissionSeeder::class);
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->staff->assignRole('admin');
    $this->staffSession = fn () => $this->actingAs($this->staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $this->staff->staff_session_version, 'staff.started' => now()->timestamp, 'staff.seen' => now()->timestamp]);
    $this->write = fn ($url, $body, $key = null) => $this->postJson('/api/v1/'.$url, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $this->plan = fn () => ['order_number' => DB::table('orders')->sole()->number, 'warehouse_id' => DB::table('warehouses')->sole()->id, 'service_code' => 'REG', 'items' => [['order_item_id' => DB::table('order_items')->sole()->public_id, 'qty' => 1]]];
    $this->pack = function ($n, $o) {
        foreach (['PACK_START', 'PACK_FINISH', 'READY_FOR_DISPATCH'] as $a) {
            ($this->write)("admin/orders/$o/packing-events", ['action' => $a, 'shipment_number' => $n])->assertOk();
        }
    };
});
it('dispatches one RTS unit once after packing and isolates buyer shipment detail', function () {
    DB::table('shipping_rates')->increment('base_amount');
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$body] = ($this->finalQuote)($this->body);
    ($this->confirm)($body)->assertCreated();
    $o = DB::table('orders')->sole();
    ($this->staffSession)();
    $plan = ($this->plan)();
    ($this->write)('admin/shipments', $plan)->assertConflict();
    DB::transaction(function () use ($o) {
        app(StockReservations::class)->commit($o->id, 1);
        DB::table('orders')->where('id', $o->id)->update(['status' => 'CONFIRMED', 'payment_status' => 'PAID']);
        DB::table('invoices')->update(['status' => 'PAID']);
    });
    $n = ($this->write)('admin/shipments', $plan, 'one')->assertOk()->json('data.number');
    ($this->write)('admin/shipments', $plan, 'one')->assertOk()->assertJsonPath('data.number', $n);
    $secondShare = ($this->write)('admin/shipments', $plan)->assertOk()->json('data.shipping_amount');
    $orderFreight = DB::table('invoices')->sole()->shipping_amount;
    expect($secondShare)->toBe(intdiv($orderFreight, 2))
        ->and(DB::table('shipments')->where('number', $n)->value('shipping_amount'))->toBe($orderFreight - $secondShare);
    $route = "admin/shipments/$n/dispatches";
    $d = ['service_code' => 'REG', 'tracking_number' => ' abc '];
    ($this->write)($route, $d)->assertConflict();
    ($this->pack)($n, $o->number);
    ($this->write)($route, $d, 'dispatch')->assertOk()->assertJsonPath('data.tracking_number', 'ABC');
    ($this->write)($route, $d, 'dispatch')->assertOk();
    ($this->write)($route, $d)->assertConflict();
    expect(DB::table('inventory_movements')->where('type', 'SALE')->sum('on_hand_delta'))->toEqual(-1);
    $this->assertDatabaseHas('orders', ['id' => $o->id, 'fulfillment_status' => 'PARTIALLY_SHIPPED']);
    ($this->login)(User::factory()->create());
    $this->getJson("/api/v1/shipments/$n")->assertNotFound();
    ($this->login)($this->buyer);
    $this->getJson("/api/v1/shipments/$n")->assertOk()->assertJsonPath('data.tracking.0.status', 'PICKED_UP');
});
it('ships only received deposit units covered by a paid balance and the paid package quote', function () {
    DB::transaction(function () {
        $g = ['method' => 'REQUEST_ORDER', 'term' => 'DEPOSIT', 'subtotal_amount' => 300, 'total_amount' => 300, 'shipping_amount' => 0, 'tax_amount' => 0, 'discount_amount' => 0, 'due_now_amount' => 99, 'initial_tax' => 0, 'campaign_terms' => null, 'lines' => [['product_snapshot' => 'Box', 'term' => 'DEPOSIT', 'qty' => 3, 'unit_amount' => 100, 'initial_unit_amount' => 33, 'initial_line_amount' => 99, 'tax_amount' => 0, 'initial_tax' => 0, 'received_qty' => 1, 'weight_g' => 600]]];
        $addr = ['district_id' => $this->district, 'line1' => 'Street', 'recipient' => 'Buyer', 'phone' => '090', 'postal_code' => '12345', 'region_snapshot' => []];
        $due = now()->addDay()->toISOString();
        $o = app(CheckoutOrders::class)->create(null, $this->buyer->id, $g, $addr, $due, null);
        app(InitialInvoices::class)->issue($o['id'], $this->buyer->id, $g, $o['lines'], $due, 'quotation:test');
        DB::table('invoices')->update(['status' => 'PAID']);
        DB::table('orders')->update(['status' => 'CONFIRMED', 'payment_status' => 'PARTIALLY_PAID']);
    });
    ($this->staffSession)();
    $p = ($this->plan)();
    $o = $p['order_number'];
    $worker = competingShipmentWorker($p, $this->staff->id);
    $results = Concurrency::driver('process')->run([$worker, $worker]);
    $n = DB::table('shipments')->sole()->number;
    expect($results)->toEqualCanonicalizing([$n, 409]);
    ($this->write)('admin/shipments', $p)->assertConflict();
    ($this->pack)($n, $o);
    $q = ($this->write)('admin/shipping-quotes', ['order_number' => $o, 'shipment_number' => $n])->assertOk()->json('data.0.id');
    $c = ($this->write)("admin/orders/$o/charge-consents", ['type' => 'DOMESTIC_SHIPPING', 'expires_at' => now()->addHours(5)->toISOString(), 'quote_id' => $q])->assertOk()->json('data.id');
    ($this->login)($this->buyer);
    ($this->write)("orders/$o/charge-consents/$c/decisions", ['decision' => 'ACCEPT'])->assertOk();
    ($this->staffSession)();
    $route = "admin/shipments/$n/dispatches";
    $d = ['service_code' => 'REG', 'tracking_number' => 'PO1'];
    ($this->write)($route, $d)->assertConflict();
    DB::table('invoices')->where('purpose', 'ADDITIONAL_CHARGE')->update(['status' => 'PAID']);
    ($this->write)($route, $d)->assertConflict();
    ($this->write)("admin/orders/$o/invoices", ['purpose' => 'BALANCE', 'due_at' => now()->addDay()->toISOString()])->assertOk();
    DB::table('invoices')->where('purpose', 'BALANCE')->update(['status' => 'PAID']);
    ($this->write)($route, $d)->assertOk();
    expect(DB::table('inventory_movements')->where('type', 'SALE')->count())->toBe(0);
});

function competingShipmentWorker(array $plan, int $actor): Closure
{
    return static function () use ($plan, $actor) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected test database');
        }
        try {
            return DB::transaction(fn () => Packages::plan($plan, $actor));
        } catch (ApiProblem $exception) {
            return $exception->getStatusCode();
        }
    };
}

it('dispatches a received free unit without billing the outstanding units early', function () {
    $order = DB::transaction(function () {
        $line = ['term' => 'DEPOSIT', 'qty' => 1, 'initial_tax' => 0, 'tax_amount' => 0, 'weight_g' => 250];
        $lines = [
            $line + ['product_snapshot' => 'Bonus', 'unit_amount' => 0, 'initial_unit_amount' => 0, 'received_qty' => 1],
            $line + ['product_snapshot' => 'Album', 'unit_amount' => 300, 'initial_unit_amount' => 99, 'received_qty' => 0],
        ];
        $group = ['method' => 'REQUEST_ORDER', 'term' => 'DEPOSIT', 'subtotal_amount' => 300, 'total_amount' => 300, 'shipping_amount' => 0, 'tax_amount' => 0, 'discount_amount' => 0, 'due_now_amount' => 99, 'initial_tax' => 0, 'campaign_terms' => null, 'lines' => $lines];
        $addr = ['district_id' => $this->district, 'line1' => 'Street', 'recipient' => 'Buyer', 'phone' => '090', 'postal_code' => '12345', 'region_snapshot' => []];
        $due = now()->addDay()->toISOString();
        $o = app(CheckoutOrders::class)->create(null, $this->buyer->id, $group, $addr, $due, null);
        app(InitialInvoices::class)->issue($o['id'], $this->buyer->id, $group, $o['lines'], $due, 'quotation:bonus');
        DB::table('invoices')->update(['status' => 'PAID']);
        DB::table('orders')->update(['status' => 'CONFIRMED', 'payment_status' => 'PARTIALLY_PAID']);
        DB::table('shipping_rates')->update(['base_amount' => 0, 'amount_per_kg' => 0]);

        return DB::table('orders')->where('id', $o['id'])->value('number');
    });
    ($this->staffSession)();
    $item = DB::table('order_items')->where('unit_amount', 0)->sole();
    $p = ['order_number' => $order, 'warehouse_id' => DB::table('warehouses')->sole()->id, 'service_code' => 'REG', 'items' => [['order_item_id' => $item->public_id, 'qty' => 1]]];
    $number = ($this->write)('admin/shipments', $p)->assertOk()->json('data.number');
    ($this->pack)($number, $order);
    ($this->write)("admin/shipments/$number/dispatches", ['service_code' => 'REG', 'tracking_number' => 'BONUS'])->assertOk();
    expect(DB::table('invoices')->where('purpose', 'BALANCE')->exists())->toBeFalse();
});
it('guards archive separately from updates and rejects duplicate couriers', function () {
    ($this->staffSession)();
    $d = ['code' => 'xp', 'name' => 'Express', 'active' => true];
    $id = ($this->write)('admin/couriers', $d)->assertOk()->json('data.id');
    ($this->write)('admin/couriers', $d)->assertConflict();
    $this->staff->syncRoles([]);
    $this->staff->givePermissionTo(['shipping.courier.view', 'shipping.courier.manage', 'shipping.courier.update']);
    $this->patchJson('/api/v1/admin/couriers/'.$id, ['active' => false], ['Idempotency-Key' => 'archive'])->assertForbidden();
    $this->getJson('/api/v1/admin/couriers')->assertOk();
    $this->getJson('/api/v1/admin/shipments')->assertForbidden();
});

it('recovers failed tracking, ignores provider replays and completes only after the last delivery window', function () {
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$body] = ($this->finalQuote)($this->body);
    ($this->confirm)($body)->assertCreated();
    $o = DB::table('orders')->sole();
    DB::transaction(function () use ($o) {
        app(StockReservations::class)->commit($o->id, 1);
        DB::table('orders')->where('id', $o->id)->update(['status' => 'CONFIRMED', 'payment_status' => 'PAID']);
        DB::table('invoices')->update(['status' => 'PAID']);
    });
    ($this->staffSession)();
    $p = ($this->plan)();
    $p['items'][0]['qty'] = 2;
    $n = ($this->write)('admin/shipments', $p)->assertOk()->json('data.number');
    ($this->pack)($n, $o->number);
    ($this->write)("admin/shipments/$n/dispatches", ['service_code' => 'REG', 'tracking_number' => 'ABC'])->assertOk();
    $this->travel(5)->minutes();
    $failedAt = now();
    $track = "admin/shipments/$n/tracking-events";
    ($this->write)($track, ['status' => 'FAILED', 'occurred_at' => $failedAt->toISOString(), 'note' => 'Penerima belum tersedia'])->assertOk()->assertJsonPath('data.status', 'FAILED');
    $this->travel(1)->minutes();
    $events = [
        ['id' => 'old', 'status' => 'delivered', 'at' => $failedAt->copy()->subMinute()->toISOString()],
        ['id' => 'x', 'status' => 'unknown-code', 'at' => now()->toISOString()],
        ['id' => 'retry', 'status' => 'transit', 'at' => now()->toISOString()],
        ['id' => 'retry', 'status' => 'transit', 'at' => now()->toISOString()],
    ];
    $poll = app(PollTracking::class);
    $carrier = DB::table('shipments')->sole()->carrier_id;
    $poll->run(new FakeTrackingProvider(['ABC' => ['events' => [], 'retry_after' => 10]]), $carrier);
    $fake = new FakeTrackingProvider(['ABC' => ['events' => $events]]);
    $poll->run($fake, $carrier);
    expect(DB::table('shipments')->sole()->status)->toBe('FAILED');
    $this->travel(11)->seconds();
    $poll->run($fake, $carrier);
    $poll->run($fake, $carrier);
    expect(DB::table('shipments')->sole()->status)->toBe('IN_TRANSIT')
        ->and(DB::table('tracking_events')->where('source', 'PROVIDER')->count())->toBe(3);
    $delivered = ['status' => 'DELIVERED', 'occurred_at' => now()->toISOString()];
    ($this->write)($track, $delivered, 'delivery')->assertOk();
    ($this->write)($track, $delivered, 'delivery')->assertOk();
    expect(DB::table('order_items')->sole()->delivered_qty)->toBe(2)
        ->and(DB::table('orders')->sole()->fulfillment_status)->toBe('DELIVERED');
    app(CompleteDeliveredOrders::class)->run();
    expect(DB::table('orders')->sole()->status)->toBe('CONFIRMED');
    $this->travel(73)->hours();
    DB::table('order_transition_holds')->insert(['order_id' => $o->id, 'kind' => 'DISPUTE', 'source_ref' => 'test']);
    app(CompleteDeliveredOrders::class)->run();
    expect(DB::table('orders')->sole()->status)->toBe('CONFIRMED');
    DB::table('order_transition_holds')->where('source_ref', 'test')->delete();
    app(CompleteDeliveredOrders::class)->run();
    app(CompleteDeliveredOrders::class)->run();
    expect(DB::table('orders')->sole()->status)->toBe('COMPLETED')
        ->and(DB::table('outbox_events')->where('event_type', 'OrderCompleted')->count())->toBe(1);
});

it('limits claims to delivered shipment units, restocks once and waits for a delivered replacement', function () {
    DB::table('reason_codes')->insert(['code' => 'DAMAGED', 'domain' => 'returns', 'label' => 'Damaged item']);
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$body] = ($this->finalQuote)($this->body);
    ($this->confirm)($body)->assertCreated();
    $o = DB::table('orders')->sole();
    DB::transaction(function () use ($o) {
        app(StockReservations::class)->commit($o->id, 1);
        DB::table('orders')->update(['status' => 'CONFIRMED', 'payment_status' => 'PAID']);
        DB::table('invoices')->update(['status' => 'PAID']);
    });
    ($this->staffSession)();
    $plan = ($this->plan)();
    $plan['items'][0]['qty'] = 2;
    $n = ($this->write)('admin/shipments', $plan)->assertOk()->json('data.number');
    ($this->pack)($n, $o->number);
    ($this->write)("admin/shipments/$n/dispatches", ['service_code' => 'REG', 'tracking_number' => 'ORIGINAL'])->assertOk();
    $this->travel(1)->minutes();
    ($this->write)("admin/shipments/$n/tracking-events", ['status' => 'DELIVERED', 'occurred_at' => now()->toISOString()])->assertOk();
    ($this->login)($this->buyer);
    $item = DB::table('shipment_items')->sole()->public_id;
    $claim = ['order_number' => $o->number, 'shipment_number' => $n, 'reason_code' => 'DAMAGED', 'items' => [['shipment_item_id' => $item, 'qty' => 1]]];
    $number = ($this->write)('returns', $claim)->assertCreated()->json('data.number');
    $secondNumber = ($this->write)('returns', $claim)->assertCreated()->json('data.number');
    ($this->write)('returns', $claim)->assertConflict()->assertJsonPath('code', 'QUANTITY_CONFLICT');
    $this->travel(49)->hours();
    ($this->login)($this->buyer);
    ($this->write)('returns', $claim)->assertConflict()->assertJsonPath('code', 'RETURN_WINDOW_CLOSED');
    ($this->login)(User::factory()->create());
    $this->getJson("/api/v1/returns/$number")->assertNotFound();
    ($this->staffSession)();
    $case = "admin/returns/$number";
    ($this->write)("$case/reviews", ['decision' => 'START'])->assertOk();
    ($this->write)("$case/reviews", ['decision' => 'APPROVE'])->assertConflict();
    foreach (['a', 'b'] as $digest) {
        $ref = (string) Str::ulid();
        DB::table('stored_files')->insert(['public_id' => $ref, 'owner_type' => 'return', 'owner_id' => DB::table('returns')->where('number', $number)->sole()->id, 'collection' => 'return_evidence', 'uploaded_by' => $this->buyer->id, 'status' => 'READY', 'object_key' => 'return/'.$ref, 'mime' => 'image/png', 'size_bytes' => 10, 'sha256' => str_repeat($digest, 64)]);
    }
    ($this->write)("$case/reviews", ['decision' => 'APPROVE'])->assertOk();
    ($this->write)("$case/reviews", ['decision' => 'GIVE_INSTRUCTIONS', 'instructions' => 'Return to warehouse within 7 days'])->assertOk();
    ($this->login)($this->buyer);
    ($this->write)("returns/$number/dispatches", ['tracking_number' => 'RET'])->assertOk();
    ($this->staffSession)();
    ($this->write)("$case/receipts", [])->assertForbidden();
    $this->staff->givePermissionTo('returns.return.inspect');
    ($this->write)("$case/receipts", [])->assertOk();
    $inspect = ['result' => 'ACCEPT', 'note' => 'Sealed and intact', 'items' => [['shipment_item_id' => $item, 'accepted_qty' => 1, 'disposition' => 'RESTOCK']]];
    ($this->write)("$case/inspections", $inspect, 'inspect')->assertOk();
    ($this->write)("$case/inspections", $inspect, 'inspect')->assertOk();
    ($this->write)("$case/inspections", $inspect)->assertConflict();
    expect(DB::table('inventory_movements')->where('type', 'RETURN')->sum('on_hand_delta'))->toEqual(1);
    // A declined financial resolution still permits CS to arrange a replacement.
    $return = DB::table('returns')->where('number', $number)->sole();
    DB::table('returns')->where('id', $return->id)->update(['resolution' => 'REFUND']);
    $refundId = DB::table('refunds')->insertGetId(['number' => 'RFD-DECLINED', 'return_id' => $return->id, 'order_id' => $o->id,
        'requested_by' => $this->staff->id, 'reason_id' => $return->reason_id, 'kind' => 'ITEM', 'requested_amount' => 1, 'cancel_order' => false, 'status' => 'UNDER_REVIEW']);
    ($this->write)("$case/resolutions", ['resolution' => 'REPLACEMENT', 'note' => 'Exchange'])->assertConflict();
    DB::table('refunds')->where('id', $refundId)->update(['status' => 'REJECTED']);
    $decision = ['resolution' => 'REPLACEMENT', 'note' => 'Exchange'];
    $replacement = ($this->write)("$case/resolutions", $decision)->assertOk()->assertJsonPath('data.status', 'ACCEPTED')->json('data.replacement_number');
    ($this->write)("$case/resolutions", $decision)->assertOk()->assertJsonPath('data.replacement_number', $replacement);
    ($this->pack)($replacement, $o->number);
    $dispatch = fn ($shipment, $tracking) => ($this->write)("admin/shipments/$shipment/dispatches", ['service_code' => 'REG', 'tracking_number' => $tracking]);
    $dispatch($replacement, 'REPL')->assertConflict();
    $secondCase = "admin/returns/$secondNumber";
    // Start with a second independently accepted claim on the same delivery.
    $secondReturn = DB::table('returns')->where('number', $secondNumber)->sole();
    DB::table('returns')->where('id', $secondReturn->id)->update(['status' => 'ACCEPTED']);
    DB::table('return_items')->where('return_id', $secondReturn->id)->update(['accepted_qty' => 1, 'disposition' => 'NO_RECEIPT']);
    $secondPackage = ($this->write)("$secondCase/resolutions", $decision)->assertOk()->json('data.replacement_number');
    ($this->pack)($secondPackage, $o->number);
    DB::table('order_transition_holds')->insert(['order_id' => $o->id, 'kind' => 'PROOF_REVIEW', 'source_ref' => 'another-payment']);
    $dispatch($replacement, 'REPL')->assertConflict();
    DB::table('order_transition_holds')->where('source_ref', 'another-payment')->delete();
    $dispatch($secondPackage, 'REPL2')->assertOk();
    ($this->write)("admin/shipments/$replacement/dispatches", ['service_code' => 'REG', 'tracking_number' => 'REPL'])->assertOk();
    $this->travel(1)->minutes();
    ($this->write)("admin/shipments/$replacement/tracking-events", ['status' => 'DELIVERED', 'occurred_at' => now()->toISOString()])->assertOk();
    ($this->write)("$case/resolutions", $decision)->assertOk()->assertJsonPath('data.status', 'RESOLVED');
    ($this->write)("admin/shipments/$secondPackage/tracking-events", ['status' => 'DELIVERED', 'occurred_at' => now()->toISOString()])->assertOk();
    ($this->write)("$secondCase/resolutions", $decision)->assertOk()->assertJsonPath('data.status', 'RESOLVED');
    expect(DB::table('order_items')->sole()->delivered_qty)->toBe(2)
        ->and(DB::table('inventory_movements')->where('type', 'REPLACEMENT')->sum('on_hand_delta'))->toEqual(-2)
        ->and(DB::table('order_transition_holds')->exists())->toBeFalse();
});
