<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockLedger;
use App\Support\Http\ApiProblem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->customer = User::factory()->create();
    $this->login = function (User $user) {
        $this->actingAs($user)->withSession([DeviceSessions::SURFACE_KEY => 'CUSTOMER'])->withHeader('Origin', 'http://localhost');
    };
    $this->cart = '/api/v1/cart';
    $this->items = $this->cart.'/items';
    $this->intent = ['sku' => 'ALB', 'qty' => 2, 'term' => 'FULL', 'cart_version' => 0];
    $product = DB::table('products')->insertGetId(['slug' => 'album', 'name' => 'Album', 'status' => 'PUBLISHED', 'published_at' => now()]);
    $unit = DB::table('units')->insertGetId(['code' => 'pcs', 'name' => 'Piece']);
    $this->variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'unit_id' => $unit, 'name' => 'Album', 'sku' => 'ALB',
        'option_key' => 'one', 'weight_g' => 100, 'rts_enabled' => true, 'rts_price_amount' => 2000]);
    $district = DB::table('regions')->insertGetId(['code' => 'ID', 'name' => 'Indonesia', 'kind' => 'COUNTRY']);
    $warehouse = DB::table('warehouses')->insertGetId(['code' => 'JKT', 'name' => 'JKT', 'district_id' => $district, 'address' => 'JKT', 'is_default' => true]);
    DB::table('reason_codes')->insert(['domain' => 'inventory', 'code' => 'COUNT', 'label' => 'Count']);
    app(StockLedger::class)->receive(['warehouse_id' => $warehouse, 'sku' => 'ALB', 'qty' => 8, 'type' => 'INITIAL', 'reason_code' => 'COUNT'], $this->customer->id, 'opening');
    $this->campaign = DB::table('preorder_campaigns')->insertGetId(['number' => 'PO-01', 'name' => 'Album PO', 'slug' => 'album-po', 'status' => 'OPEN',
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'ready_eta_from' => today(), 'ready_eta_to' => today(),
        'ship_eta_from' => today(), 'ship_eta_to' => today(), 'origin' => 'KR', 'transport_mode' => 'AIR', 'payment_scheme' => 'FULL_OR_DEPOSIT', 'terms' => 'Estimated dates']);
    DB::table('preorder_campaign_variants')->insert(['campaign_id' => $this->campaign, 'variant_id' => $this->variant,
        'unit_amount' => 3000, 'deposit_unit_amount' => 1000, 'min_qty' => 2, 'max_qty' => 5, 'quota' => 6, 'confirmed_qty' => 1]);
});

it('requires a verified customer and does not materialize an empty cart on read', function () {
    $this->getJson($this->cart)->assertUnauthorized();
    $this->postJson($this->items, $this->intent)->assertUnauthorized();
    ($this->login)(User::factory()->unverified()->create());
    $this->getJson($this->cart)->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    ($this->login)($this->customer);
    $this->getJson($this->cart)->assertOk()->assertJsonPath('data', ['version' => 0, 'items' => [], 'estimated_total_amount' => 0]);
    $this->getJson($this->cart.'?user_id=1')->assertUnprocessable();
    $this->postJson($this->items, ['user_id' => 1] + $this->intent)->assertUnprocessable();
    $this->postJson($this->items, ['term' => 'INVALID'] + $this->intent)->assertUnprocessable();
    $this->postJson($this->items, ['qty' => 10001] + $this->intent)->assertUnprocessable();
    $this->postJson($this->items, ['sku' => 'bad sku'] + $this->intent)->assertUnprocessable();
    $this->assertDatabaseCount('carts', 0);
});

it('upserts absolute quantities per offer, changes terms and journals only accepted edits without allocating supply', function () {
    ($this->login)($this->customer);
    $first = $this->postJson($this->items, $this->intent)->assertCreated()->assertJsonPath('data.version', 1)->json('data.items.0.id');
    $this->postJson($this->items, ['qty' => 3, 'cart_version' => 1] + $this->intent)->assertCreated()
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $first)->assertJsonPath('data.items.0.qty', 3);
    $po = $this->postJson($this->items, ['campaign_slug' => 'album-po', 'term' => 'DEPOSIT', 'cart_version' => 2] + $this->intent)->assertCreated()
        ->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.1.term', 'DEPOSIT')->assertJsonPath('data.estimated_total_amount', 12000)
        ->assertJsonMissingPath('data.items.1.max_qty')->json('data.items.1.id');
    $this->patchJson("$this->items/$po", ['term' => 'FULL', 'qty' => 2, 'cart_version' => 3])->assertOk()->assertJsonPath('data.items.1.term', 'FULL');
    $this->deleteJson("$this->items/$first?cart_version=3")->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
    $this->deleteJson("$this->items/$first", ['cart_version' => 4])->assertUnprocessable();
    $this->deleteJson("$this->items/$first?cart_version=4")->assertOk()->assertJsonPath('data.version', 5)->assertJsonCount(1, 'data.items');
    $this->assertDatabaseCount('stock_reservations', 0);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseHas('inventory_levels', ['on_hand' => 8, 'available' => 8, 'reserved' => 0]);
    $this->assertDatabaseHas('preorder_campaign_variants', ['quota' => 6, 'reserved_qty' => 0, 'confirmed_qty' => 1]);
    expect(DB::table('outbox_events')->where('event_type', 'CartChanged')->count())->toBe(5)
        ->and(DB::table('audit_logs')->where('action', 'CartChanged')->where('actor_id', $this->customer->id)->count())->toBe(5);
    $this->assertDatabaseHas('outbox_events', ['event_type' => 'CartChanged', 'payload->version' => 5]);
});

it('scopes public item IDs to the owner and prevents stale edits across converted carts', function () {
    ($this->login)($this->customer);
    $id = $this->postJson($this->items, $this->intent)->assertCreated()->json('data.items.0.id');
    ($this->login)(User::factory()->create());
    $this->getJson($this->cart)->assertJsonPath('data.items', []);
    $this->patchJson("$this->items/$id", ['qty' => 1, 'cart_version' => 1])->assertNotFound();
    $this->deleteJson("$this->items/$id?cart_version=1")->assertNotFound();
    ($this->login)($this->customer);
    DB::table('carts')->update(['status' => 'CONVERTED']);
    $this->postJson($this->items, $this->intent)->assertCreated()->assertJsonPath('data.version', 2);
    $this->postJson($this->items, ['cart_version' => 1] + $this->intent)->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
});

it('projects current prices without overwriting the original intent and permits removal when unavailable', function () {
    ($this->login)($this->customer);
    $id = $this->postJson($this->items, $this->intent)->assertCreated()->json('data.items.0.id');
    DB::table('product_variants')->update(['rts_price_amount' => 4000]);
    $this->getJson($this->cart)->assertJsonPath('data.version', 1)->assertJsonPath('data.items.0.availability', 'PRICE_CHANGED')
        ->assertJsonPath('data.items.0.display_unit_amount', 4000)->assertJsonPath('data.estimated_total_amount', 8000);
    $this->patchJson("$this->items/$id", ['qty' => 1, 'cart_version' => 1])->assertOk()->assertJsonPath('data.items.0.availability', 'PRICE_CHANGED');
    $this->assertDatabaseHas('cart_items', ['display_unit_amount' => 2000, 'qty' => 1]);
    DB::table('products')->update(['status' => 'DRAFT']);
    $this->getJson($this->cart)->assertJsonPath('data.items.0.availability', 'UNAVAILABLE')->assertJsonPath('data.estimated_total_amount', 0);
    $this->patchJson("$this->items/$id", ['qty' => 2, 'cart_version' => 2])->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    $this->deleteJson("$this->items/$id?cart_version=2")->assertOk()->assertJsonPath('data.items', []);
});

it('rechecks publication, default warehouse supply, campaign match, window, terms and remaining quota', function () {
    ($this->login)($this->customer);
    $reject = fn ($fields) => $this->postJson($this->items, $fields + $this->intent)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    $po = ['campaign_slug' => 'album-po'];
    $other = DB::table('product_variants')->where('id', $this->variant)->first(['product_id', 'unit_id', 'name', 'weight_g']);
    DB::table('product_variants')->insert((array) $other + ['sku' => 'OTHER', 'option_key' => 'two']);
    foreach ([['sku' => 'MISSING'], ['term' => 'DEPOSIT'], ['qty' => 9], ['campaign_slug' => 'missing'], ['sku' => 'OTHER'] + $po, ['qty' => 1] + $po, ['qty' => 6] + $po] as $invalid) {
        $reject($invalid);
    }
    DB::table('product_variants')->update(['active' => false]);
    $reject([]);
    DB::table('product_variants')->update(['active' => true, 'deleted_at' => now()]);
    $reject($po);
    DB::table('product_variants')->update(['deleted_at' => null, 'rts_enabled' => false]);
    $reject([]);
    DB::table('preorder_campaigns')->update(['payment_scheme' => 'DEPOSIT_ONLY']);
    $reject($po);
    DB::table('preorder_campaigns')->update(['payment_scheme' => 'FULL_ONLY']);
    $reject(['term' => 'DEPOSIT'] + $po);
    DB::table('preorder_campaign_variants')->update(['reserved_qty' => 4]);
    $reject($po);
    DB::table('preorder_campaign_variants')->update(['reserved_qty' => 0]);
    DB::table('preorder_campaigns')->update(['status' => 'CLOSED']);
    $reject($po);
    DB::table('preorder_campaigns')->update(['status' => 'OPEN', 'ends_at' => now()->subMinute()]);
    $reject($po);
    $this->assertDatabaseCount('carts', 0);
    $this->assertDatabaseMissing('outbox_events', ['event_type' => 'CartChanged']);
});

it('rolls back the item, version, audit and event together with a failed outer transaction', function () {
    $cart = app(CustomerCart::class);
    $cart->set($this->customer->id, $this->intent);
    expect(fn () => DB::transaction(function () use ($cart) {
        $cart->set($this->customer->id, ['qty' => 5, 'cart_version' => 1] + $this->intent);
        throw new RuntimeException('Abort transaction');
    }))->toThrow(RuntimeException::class, 'Abort transaction');
    $this->assertDatabaseHas('cart_items', ['qty' => 2]);
    $this->assertDatabaseHas('carts', ['version' => 1]);
    expect(DB::table('outbox_events')->where('event_type', 'CartChanged')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'CartChanged')->count())->toBe(1);
});

function cartWriter(int $userId, array $data): Closure
{
    return static function () use ($userId, $data) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected disposable test database.');
        }
        try {
            return app(CustomerCart::class)->set($userId, $data)['version'];
        } catch (ApiProblem $error) {
            return $error->errorCode;
        }
    };
}

it('allows one winner for concurrent first writes and edits from the same version on separate PostgreSQL connections', function () {
    foreach ([0, 1] as $version) {
        $data = ['cart_version' => $version] + $this->intent;
        $results = Concurrency::driver('process')->run([cartWriter($this->customer->id, $data), cartWriter($this->customer->id, ['qty' => 4] + $data)]);
        expect($results)->toEqualCanonicalizing([$version + 1, 'VERSION_CONFLICT']);
    }
    $this->assertDatabaseCount('carts', 1);
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseHas('carts', ['version' => 2]);
    $this->assertDatabaseCount('inventory_movements', 1);
});
