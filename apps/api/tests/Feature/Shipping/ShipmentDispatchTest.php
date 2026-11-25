<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockReservations;
use App\Modules\Invoicing\InitialInvoices;
use App\Modules\Orders\CheckoutOrders;
use App\Modules\Shipping\Packages;
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
