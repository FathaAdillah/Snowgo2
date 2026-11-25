<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Preorders\AllocationLifecycle;
use App\Modules\Preorders\CampaignWindow;
use App\Support\Http\ApiProblem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);

beforeEach(function () {
    $this->prepareCheckout();
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->admin = '/api/v1/admin/preorder-campaigns';
    $this->asStaff = function (string $role) {
        $this->seed(PermissionSeeder::class);
        $staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
        $staff->assignRole($role);
        $this->actingAs($staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF',
            'staff.version' => $staff->staff_session_version, 'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);

        return $staff;
    };
    $this->input = ['slug' => 'winter', 'name' => 'Winter', 'starts_at' => now()->subHour()->toISOString(), 'ends_at' => now()->addDay()->toISOString(),
        'origin' => 'KR', 'transport_mode' => 'AIR', 'ready_eta_from' => '2027-01-01', 'ready_eta_to' => '2027-01-05',
        'ship_eta_from' => '2027-01-06', 'ship_eta_to' => '2027-01-10', 'terms' => 'Batch 1', 'payment_scheme' => 'FULL_OR_DEPOSIT',
        'variants' => [['sku' => 'A1', 'unit_amount' => 12000, 'deposit_unit_amount' => 3000, 'min_qty' => 1, 'max_qty' => 3, 'quota' => 10]]];
    $this->place = function () {
        ($this->login)($this->buyer);
        app(CustomerCart::class)->set($this->buyer->id, $this->po);
        [$intent] = ($this->finalQuote)($this->body);

        return ($this->confirm)($intent)->assertCreated()->json('data.order_numbers.0');
    };
});

it('requires action grants, replays creation, and hides drafts and staff counters from the public', function () {
    $staff = ($this->asStaff)('product_manager');
    $this->getJson($this->admin)->assertOk();
    $key = ['Idempotency-Key' => 'new'];
    $this->postJson($this->admin, $this->input, $key)->assertForbidden();
    $staff->givePermissionTo('preorders.campaign.manage');
    $this->postJson($this->admin, $this->input, $key)->assertForbidden();
    $staff->givePermissionTo('preorders.campaign.create', 'preorders.campaign.schedule');
    $number = $this->postJson($this->admin, $this->input, $key)->assertCreated()->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.variants.0.quota', 10)->json('data.number');
    $this->postJson($this->admin, $this->input, $key)->assertCreated()->assertJsonPath('data.number', $number);
    $this->postJson($this->admin, $this->input, ['Idempotency-Key' => 'duplicate'])->assertConflict();
    $this->getJson('/api/v1/preorders/winter')->assertNotFound();
    $this->postJson("$this->admin/$number/actions/SCHEDULE", [], ['Idempotency-Key' => 'schedule'])->assertOk();
    $this->postJson("$this->admin/$number/actions/OPEN", [], ['Idempotency-Key' => 'open'])->assertForbidden();
    $this->getJson('/api/v1/preorders?status=SCHEDULED')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/preorders/winter')->assertOk()->assertJsonPath('data.variants.0.available_qty', 10)->assertJsonMissingPath('data.variants.0.confirmed_qty');
    $this->assertDatabaseCount('preorder_campaigns', 2);
    $this->assertDatabaseHas('status_histories', ['subject_type' => 'preorder_campaign', 'to_status' => 'SCHEDULED', 'actor_id' => $staff->id]);
    expect(app(CampaignWindow::class)->advanceDue())->toBe(1);
    $this->getJson('/api/v1/products?method=PO')->assertOk()->assertJsonPath('data.0.slug', 'album');
    $this->getJson('/api/v1/products/album')->assertOk()->assertJsonFragment(['campaign_slug' => 'winter', 'method' => 'PO', 'unit_amount' => 12000, 'available' => true]);
    DB::table('preorder_campaigns')->where('slug', 'winter')->update(['ends_at' => now()->subMinute()]);
    expect(app(CampaignWindow::class)->advanceDue())->toBe(1)->and(app(CampaignWindow::class)->advanceDue())->toBe(0);
    $this->assertDatabaseHas('preorder_campaigns', ['slug' => 'winter', 'status' => 'CLOSED']);
});

it('rejects invalid campaign data and rolls back offers, journal and events', function (array $change, string $field) {
    ($this->asStaff)('admin');
    $this->postJson($this->admin, array_replace_recursive($this->input, $change), ['Idempotency-Key' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('preorder_campaigns', 1);
    $this->assertDatabaseCount('preorder_campaign_variants', 1);
    $this->assertDatabaseMissing('outbox_events', ['event_type' => 'PreorderCreated']);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'PreorderOfferUpdated']);
})->with([
    [['ends_at' => '2020-01-01T00:00:00Z'], 'dates'],
    [['ready_eta_to' => '2026-12-01'], 'dates'],
    [['variants' => [['unit_amount' => 0]]], 'variants.0.unit_amount'],
    [['variants' => [['quota' => -1]]], 'variants.0.quota'],
    [['variants' => [['deposit_unit_amount' => 12000]]], 'variants'],
    [['variants' => [['min_qty' => 4]]], 'variants'],
    [['payment_scheme' => 'FULL_ONLY'], 'variants'],
]);

it('accepts the full campaign slug length in a customer cart without relaxing SKU validation', function () {
    $slug = str_repeat('w', 160);
    DB::table('preorder_campaigns')->where('slug', 'po')->update(['slug' => $slug]);
    ($this->login)($this->buyer);
    $this->postJson('/api/v1/cart/items', ['sku' => str_repeat('A', 81)] + $this->po)->assertUnprocessable()->assertJsonValidationErrors('sku');
    $this->postJson('/api/v1/cart/items', ['campaign_slug' => $slug] + $this->po)->assertCreated()->assertJsonPath('data.items.0.campaign_slug', $slug);
});

it('keeps full payment available when an individual SKU has no optional deposit', function () {
    ($this->asStaff)('admin');
    $variant = (array) DB::table('product_variants')->where('sku', 'A1')->first();
    unset($variant['id']);
    DB::table('product_variants')->insert(['sku' => 'A2', 'option_key' => 'b'] + $variant);
    $input = $this->input;
    $input['variants'][] = ['sku' => 'A2', 'deposit_unit_amount' => null] + $input['variants'][0];
    $number = $this->postJson($this->admin, $input, ['Idempotency-Key' => 'mixed'])->assertCreated()->json('data.number');
    $this->patchJson("$this->admin/$number", ['payment_scheme' => 'DEPOSIT_ONLY'], ['Idempotency-Key' => 'must-have-deposit'])->assertUnprocessable();
    foreach (['SCHEDULE', 'OPEN'] as $action) {
        $this->postJson("$this->admin/$number/actions/$action", [], ['Idempotency-Key' => $action])->assertOk();
    }
    ($this->login)($this->buyer);
    $item = ['campaign_slug' => 'winter', 'sku' => 'A2'] + $this->po;
    $this->postJson('/api/v1/cart/items', $item)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    $this->postJson('/api/v1/cart/items', ['term' => 'FULL'] + $item)->assertCreated();
    $this->postJson('/api/v1/cart/items', ['cart_version' => 1, 'sku' => 'A1'] + $item)->assertCreated();
});

it('keeps quotas above active allocations and emits ETA changes only once', function () {
    ($this->place)();
    ($this->asStaff)('order_staff');
    $url = $this->admin.'/PO-1';
    $this->patchJson($url, ['variants' => [array_merge($this->input['variants'][0], ['quota' => 0])]], ['Idempotency-Key' => 'quota'])->assertConflict()->assertJsonPath('code', 'QUOTA_FULL');
    $this->postJson($url.'/actions/COMPLETE', [], ['Idempotency-Key' => 'early'])->assertConflict();
    $patch = ['ready_eta_to' => '2029-01-20'];
    $key = ['Idempotency-Key' => 'eta'];
    $this->patchJson($url, $patch, $key)->assertOk()->assertJsonPath('data.ready_eta_to', '2029-01-20');
    $this->patchJson($url, $patch, $key)->assertOk();
    expect(DB::table('outbox_events')->where('event_type', 'PreorderEtaUpdated')->count())->toBe(1);
    $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => 1, 'quota' => 1]);
    $this->postJson($url.'/actions/CANCEL', [], ['Idempotency-Key' => 'stop'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->assertDatabaseHas('preorder_allocations', ['status' => 'RELEASED']);
    $this->assertDatabaseHas('invoices', ['status' => 'VOID']);
    $this->getJson('/api/v1/preorders/po')->assertNotFound();
});

it('retains staff notes with their exact transition, including replay with no orders', function () {
    $staff = ($this->asStaff)('order_staff');
    $url = "$this->admin/PO-1/actions/CLOSE";
    $key = ['Idempotency-Key' => 'close'];
    $input = ['note' => 'Internal supplier note'];
    $this->postJson($url, $input, $key)->assertOk();
    $this->postJson($url, $input, $key)->assertOk();
    $history = DB::table('status_histories')->where(['subject_type' => 'preorder_campaign', 'to_status' => 'CLOSED', 'actor_id' => $staff->id])->sole();
    $this->assertDatabaseCount('preorder_transition_notes', 1);
    $this->assertDatabaseHas('preorder_transition_notes', ['history_id' => $history->id] + $input);
    $this->getJson('/api/v1/preorders/po')->assertOk()->assertDontSee('Internal supplier note');
});

it('confirms only paid initial deposits, only once, without releasing historical quota or touching physical stock', function () {
    $number = ($this->place)();
    $order = DB::table('orders')->where('number', $number)->value('id');
    $this->getJson('/api/v1/preorders/po/allocations')->assertOk()->assertJsonPath('data.0.order_number', $number);
    ($this->login)(User::factory()->create());
    $this->getJson('/api/v1/preorders/po/allocations')->assertOk()->assertJsonCount(0, 'data');
    $life = app(AllocationLifecycle::class);
    expect(fn () => $life->confirm($order))->toThrow(ApiProblem::class);
    DB::table('invoices')->where(['order_id' => $order, 'purpose' => 'DEPOSIT'])->update(['status' => 'PAID']);
    $life->confirm($order);
    $life->confirm($order);
    DB::table('preorder_allocations')->update(['expires_at' => now()->subDay()]);
    expect($life->expireDue())->toBe(0);
    $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => 0, 'confirmed_qty' => 1]);
    $this->assertDatabaseHas('preorder_allocations', ['status' => 'CONFIRMED']);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseCount('stock_reservations', 0);
    ($this->asStaff)('admin');
    $this->postJson("$this->admin/PO-1/actions/CANCEL", [], ['Idempotency-Key' => 'no-refund'])->assertConflict();
    $this->assertDatabaseHas('preorder_campaigns', ['status' => 'OPEN']);
    foreach (['CLOSE', 'START_PROCUREMENT'] as $action) {
        $this->postJson("$this->admin/PO-1/actions/$action", [], ['Idempotency-Key' => $action])->assertOk();
    }
    $this->postJson("$this->admin/PO-1/actions/FULFILL", [], ['Idempotency-Key' => 'before-receipt'])->assertConflict();
    $campaign = DB::table('preorder_campaigns')->where('number', 'PO-1')->value('id');
    DB::transaction(fn () => app(CampaignWindow::class)->recordReceipt($campaign));
    $this->postJson("$this->admin/PO-1/actions/FULFILL", [], ['Idempotency-Key' => 'after-receipt'])->assertOk();
    $this->postJson("$this->admin/PO-1/actions/COMPLETE", [], ['Idempotency-Key' => 'before-final'])->assertConflict();
    DB::table('preorder_allocations')->update(['status' => 'FULFILLED']);
    $this->postJson("$this->admin/PO-1/actions/COMPLETE", [], ['Idempotency-Key' => 'after-final'])->assertOk();
});

it('protects proof review, then atomically expires an unpaid order and frees quota exactly once', function () {
    $number = ($this->place)();
    $order = DB::table('orders')->where('number', $number)->value('id');
    DB::table('preorder_allocations')->update(['expires_at' => now()->subMinute()]);
    $hold = ['order_id' => $order, 'kind' => 'PROOF_REVIEW', 'source_ref' => 'test'];
    DB::table('order_transition_holds')->insert($hold);
    $life = app(AllocationLifecycle::class);
    expect($life->expireDue())->toBe(0);
    DB::table('order_transition_holds')->where($hold)->delete();
    expect($life->expireDue())->toBe(1)->and($life->expireDue())->toBe(0);
    $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'EXPIRED']);
    $this->assertDatabaseHas('invoices', ['order_id' => $order, 'status' => 'VOID']);
    $this->assertDatabaseHas('preorder_allocations', ['status' => 'EXPIRED']);
    $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => 0, 'confirmed_qty' => 0]);
    $this->assertDatabaseCount('inventory_movements', 1);
});
