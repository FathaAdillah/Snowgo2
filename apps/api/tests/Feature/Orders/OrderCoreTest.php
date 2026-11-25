<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockReservations;
use App\Modules\Orders\OrderLifecycle;
use App\Support\Http\ApiProblem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);

beforeEach(function () {
    $this->prepareCheckout();
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->place = function (string $method = 'RTS') {
        ($this->login)($this->buyer);
        app(CustomerCart::class)->set($this->buyer->id, $method === 'RTS' ? $this->rts : $this->po);
        [$intent] = ($this->finalQuote)($this->body);

        return ($this->confirm)($intent)->assertCreated()->json('data.order_numbers.0');
    };
    $this->asStaff = function () {
        $this->seed(PermissionSeeder::class);
        $staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
        $this->actingAs($staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF',
            'staff.version' => $staff->staff_session_version, 'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);

        return $staff;
    };
    DB::table('reason_codes')->insert(['domain' => 'orders', 'code' => 'BUYER', 'label' => 'Buyer changed mind']);
});

it('serves immutable snapshots and timeline only to the verified owner', function () {
    $this->getJson('/api/v1/orders')->assertUnauthorized();
    ($this->login)(User::factory()->unverified()->create());
    $this->getJson('/api/v1/orders')->assertForbidden();
    $number = ($this->place)();
    $url = '/api/v1/orders/'.$number;
    DB::table('addresses')->update(['line1' => 'New place']);
    DB::table('products')->update(['name' => 'Renamed']);
    DB::table('regions')->where('id', $this->district)->update(['name' => 'New district']);
    $this->getJson('/api/v1/orders?status=PENDING_PAYMENT&method=READY_TO_SHIP')->assertOk()->assertJsonPath('data.0.number', $number)->assertJsonMissingPath('data.0.address');
    $this->getJson('/api/v1/orders?method=PREORDER')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'?include=totals')->assertOk()->assertJsonPath('data.address.line1', 'Old address')->assertJsonPath('data.address.regions.district.name', 'DISTRICT')
        ->assertJsonPath('data.items.0.name', 'Album')->assertJsonPath('data.items.0.sku', 'A1')->assertJsonMissingPath('data.user_id')
        ->assertJsonPath('data.tax_amount', 201)->assertJsonPath('data.history.0.to_status', 'PENDING_PAYMENT')->assertJsonCount(1, 'data.invoice_numbers');
    ($this->login)(User::factory()->create());
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson($url)->assertNotFound();
    $this->postJson($url.'/cancellations', ['reason_code' => 'BUYER'], ['Idempotency-Key' => 'foreign'])->assertNotFound();
    $this->postJson($url.'/completions', [], ['Idempotency-Key' => 'foreign'])->assertNotFound();
});

it('voids invoices and releases only the purchased supply once, with an auditable reason', function (string $method) {
    $number = ($this->place)($method);
    $url = "/api/v1/orders/$number/cancellations";
    $data = ['reason_code' => 'BUYER', 'note' => 'private note'];
    $key = ['Idempotency-Key' => 'cancel'];
    $this->postJson($url, $data)->assertUnprocessable();
    $this->postJson($url, ['reason_code' => 'INITIAL'], $key)->assertUnprocessable();
    $this->postJson($url, $data, $key)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->postJson($url, $data, $key)->assertOk();
    $this->postJson($url, ['reason_code' => 'BUYER'], $key)->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    $this->postJson($url, $data, ['Idempotency-Key' => 'another'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    $this->assertDatabaseHas('invoices', ['status' => 'VOID']);
    $this->assertDatabaseHas('order_items', ['cancelled_qty' => $method === 'RTS' ? 2 : 1]);
    $this->assertDatabaseHas('order_action_records', ['reason_code' => 'BUYER', 'note' => 'private note']);
    $this->assertDatabaseHas('status_histories', ['subject_type' => 'order', 'to_status' => 'CANCELLED', 'actor_id' => $this->buyer->id, 'reason_id' => DB::table('reason_codes')->where('code', 'BUYER')->value('id')]);
    expect(DB::table('outbox_events')->where('event_type', 'OrderCancelled')->count())->toBe(1);
    $event = $method === 'RTS' ? 'StockReleased' : 'PreorderAllocationReleased';
    expect(DB::table('outbox_events')->where('event_type', $event)->count())->toBe(1);
    $this->assertDatabaseHas('inventory_levels', ['on_hand' => 12, 'reserved' => 0]);
    $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => 0]);
    $this->getJson("/api/v1/orders/$number")->assertOk()->assertJsonPath('data.history.1.to_status', 'CANCELLED')->assertJsonMissingPath('data.note');
})->with(['RTS', 'PO']);

it('rejects capture, proof review or committed supply without releasing holds or voiding invoices', function (string $blocked) {
    $number = ($this->place)();
    $order = DB::table('orders')->value('id');
    match ($blocked) {
        'capture' => DB::table('orders')->update(['payment_status' => 'PARTIALLY_PAID']),
        'invoice' => DB::table('invoices')->update(['status' => 'PAID']),
        'proof' => app(StockReservations::class)->extendForProof($order, 10),
        'committed' => app(StockReservations::class)->commit($order, 10),
    };
    $this->postJson("/api/v1/orders/$number/cancellations", ['reason_code' => 'BUYER'], ['Idempotency-Key' => 'blocked'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    $this->assertDatabaseHas('orders', ['status' => 'PENDING_PAYMENT']);
    $this->assertDatabaseHas('invoices', ['status' => $blocked === 'invoice' ? 'PAID' : 'ISSUED']);
    $this->assertDatabaseMissing('outbox_events', ['event_type' => 'InvoiceVoided']);
    $this->assertDatabaseHas('order_items', ['cancelled_qty' => 0]);
    $this->assertDatabaseCount('order_action_records', 0);
})->with(['capture', 'invoice', 'proof', 'committed']);

it('requires final delivery of non-cancelled units, resolved invoices and no dispute for completion', function () {
    $number = ($this->place)();
    $order = DB::table('orders')->value('id');
    $url = "/api/v1/orders/$number/completions";
    $post = fn (string $key) => $this->postJson($url, [], ['Idempotency-Key' => $key]);
    $post('pending')->assertConflict();
    DB::table('orders')->update(['status' => 'CONFIRMED', 'payment_status' => 'PAID', 'fulfillment_status' => 'DELIVERED']);
    DB::table('order_items')->update(['shipped_qty' => 1, 'cancelled_qty' => 1]);
    $post('not-delivered')->assertConflict();
    DB::table('order_items')->update(['delivered_qty' => 1]);
    $post('unresolved-invoice')->assertConflict();
    DB::table('invoices')->update(['status' => 'PAID']);
    DB::table('order_transition_holds')->insert(['order_id' => $order, 'kind' => 'DISPUTE', 'source_ref' => 'case:1']);
    $post('dispute')->assertConflict();
    DB::table('order_transition_holds')->where('order_id', $order)->delete();
    $post('resolved')->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    $post('resolved')->assertOk();
    $post('again')->assertConflict();
    expect(DB::table('orders')->value('completed_at'))->not->toBeNull();
    expect(DB::table('outbox_events')->where('event_type', 'OrderCompleted')->count())->toBe(1);
});

it('separates staff read, PII, cancel and complete permissions even on replay and scopes support activity', function () {
    $number = ($this->place)();
    $staff = ($this->asStaff)();
    $url = '/api/v1/admin/orders';
    $this->getJson($url)->assertForbidden();
    $staff->givePermissionTo('orders.order.view', 'customers.customer.view');
    $this->getJson($url.'?method=READY_TO_SHIP')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.address', null)->assertJsonCount(1, 'data.0.history');
    $this->getJson($url.'?from=2025-02-02&to=2025-01-01')->assertUnprocessable();
    $this->getJson("$url/$number")->assertOk()->assertJsonPath('data.address', null);
    $staff->givePermissionTo('customers.customer.pii_view');
    $this->getJson("$url/$number")->assertOk()->assertJsonPath('data.address.line1', 'Old address');
    $this->assertDatabaseHas('audit_logs', ['action' => 'OrderAddressAccessed', 'subject_type' => 'order', 'actor_id' => $staff->id, 'after' => null]);
    $key = ['Idempotency-Key' => 'staff-action'];
    $cancel = fn () => $this->postJson("$url/$number/actions/CANCEL", [], $key);
    $cancel()->assertForbidden();
    $staff->givePermissionTo('orders.order.manage', 'orders.order.complete');
    $this->postJson("$url/$number/actions/COMPLETE", [], $key)->assertConflict();
    $cancel()->assertForbidden();
    $staff->givePermissionTo('orders.order.cancel');
    $key = ['Idempotency-Key' => 'staff-cancel'];
    $this->postJson("$url/$number/actions/CANCEL", [], $key)->assertOk()->assertJsonPath('data.address', null)->assertJsonPath('data.status', 'CANCELLED');
    $staff->revokePermissionTo('orders.order.cancel');
    $this->postJson("$url/$number/actions/CANCEL", [], $key)->assertForbidden();
    $support = '/api/v1/admin/customers/'.$this->buyer->id;
    $this->getJson($support)->assertOk()->assertJsonPath('data.order_count', 1);
    $this->getJson($support.'/activity')->assertOk()->assertJsonFragment(['kind' => 'ORDER_STATUS', 'reference' => $number, 'status' => 'CANCELLED']);
    $staff->revokePermissionTo('orders.order.view');
    $this->getJson($support.'/activity')->assertOk()->assertJsonMissing(['reference' => $number]);
});

function competingOrderCancellation(string $number, int $owner): Closure
{
    return static function () use ($number, $owner) {
        try {
            app(OrderLifecycle::class)->apply($number, $owner, $owner, 'CANCEL', ['reason_code' => 'BUYER']);

            return 'OK';
        } catch (ApiProblem $e) {
            return $e->errorCode;
        }
    };
}

it('serializes competing cancellations under the same order lock', function () {
    $number = ($this->place)('PO');
    $owner = $this->buyer->id;
    $cancel = competingOrderCancellation($number, $owner);
    $results = Concurrency::driver('process')->run([$cancel, $cancel]);
    expect($results)->toEqualCanonicalizing(['OK', 'INVALID_STATE_TRANSITION']);
    expect(DB::table('outbox_events')->where('event_type', 'PreorderAllocationReleased')->count())->toBe(1);
    $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => 0]);
});
