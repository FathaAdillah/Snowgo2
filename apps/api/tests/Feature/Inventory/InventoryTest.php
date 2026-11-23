<?php

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockLedger;
use App\Support\Http\ApiProblem;
use Database\Seeders\InventoryPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['sanctum.stateful' => ['admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->seed(PermissionSeeder::class);
    $this->actor = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->actingAs($this->actor)->withHeader('Origin', 'http://admin.localhost')->withSession([
        DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $this->actor->staff_session_version,
        'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);
    $this->api = fn ($m, $p, $body = [], $key = null) => $this->json($m, '/api/v1/admin/'.$p, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $parent = null;
    foreach (['ID' => 'COUNTRY', '11' => 'PROVINCE', '11.01' => 'CITY', '11.01.01' => 'DISTRICT'] as $code => $kind) {
        $parent = DB::table('regions')->insertGetId(['code' => $code, 'name' => $kind, 'kind' => $kind, 'parent_id' => $parent]);
    }
    $this->warehouse = ['code' => 'JKT', 'name' => 'Utama', 'active' => true, 'is_default' => false, 'district_code' => '11.01.01', 'address' => 'Example'];
    $this->open = fn () => ($this->api)('POST', 'warehouses', $this->warehouse)->assertCreated()->json('data.id');
    $product = DB::table('products')->insertGetId(['slug' => 'album', 'name' => 'Album', 'status' => 'PUBLISHED', 'published_at' => now()]);
    $cat = DB::table('categories')->insertGetId(['slug' => 'albums', 'name' => 'Albums']);
    DB::table('product_categories')->insert(['category_id' => $cat, 'product_id' => $product]);
    $unit = DB::table('units')->insertGetId(['code' => 'pcs', 'name' => 'Piece']);
    foreach (['ALB-1' => 25000, 'ALB-2' => 35000] as $sku => $price) {
        DB::table('product_variants')->insert(['product_id' => $product, 'unit_id' => $unit, 'name' => $sku, 'sku' => $sku, 'option_key' => $sku, 'weight_g' => 100,
            'rts_enabled' => true, 'rts_price_amount' => $price]);
    }
    DB::table('reason_codes')->insert(['domain' => 'inventory', 'code' => 'COUNT', 'label' => 'Physical count']);
    $this->receipt = ['sku' => 'ALB-2', 'qty' => 10, 'type' => 'INITIAL', 'reason_code' => 'COUNT', 'note' => 'private staff note'];
});

it('protects all inventory routes and separates receipt, adjustment and archive permissions', function () {
    $api = $this->api;
    foreach (['warehouses', 'stock-levels', 'stock-movements', 'stock-adjustments'] as $path) {
        $api('GET', $path)->assertForbidden();
        $api('GET', "$path/1")->assertForbidden();
    }
    foreach (['warehouses', 'stock-receipts', 'stock-adjustments'] as $path) {
        $api('POST', $path)->assertForbidden();
    }
    $api('PATCH', 'warehouses/1')->assertForbidden();
    $this->actor->givePermissionTo('inventory.warehouse.create', 'inventory.warehouse.update');
    $w = ($this->open)();
    $api('PATCH', "warehouses/$w", ['active' => false])->assertForbidden();
    $this->actor->assignRole('warehouse');
    $this->actor->roles()->where('name', 'warehouse')->firstOrFail()->revokePermissionTo('inventory.adjustment.create');
    $this->seed(PermissionSeeder::class);
    $api('POST', 'stock-receipts', ['warehouse_id' => $w] + $this->receipt)->assertOk();
    $api('POST', 'stock-adjustments')->assertForbidden();
    expect(InventoryPermissionsSeeder::initialGrants('product_manager'))->toBe(['inventory.stock.view'])
        ->and(InventoryPermissionsSeeder::initialGrants('warehouse'))->not->toContain('inventory.warehouse.update');
});

it('archives empty warehouses without losing history and changes the single active default explicitly', function () {
    $this->actor->assignRole('inventory_staff');
    $api = $this->api;
    $w = ($this->open)();
    $api('GET', "warehouses/$w")->assertOk()->assertJsonPath('data.is_default', true);
    $api('POST', 'warehouses', ['district_code' => '11'] + $this->warehouse)->assertUnprocessable();
    $api('POST', 'warehouses', $this->warehouse)->assertConflict()->assertJsonPath('code', 'DUPLICATE_RESOURCE');
    $w2 = $api('POST', 'warehouses', ['code' => 'SBY'] + $this->warehouse)->assertCreated()->json('data.id');
    $api('PATCH', "warehouses/$w", ['active' => false])->assertConflict();
    $api('PATCH', "warehouses/$w2", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
    $api('PATCH', "warehouses/$w", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    $api('GET', 'warehouses?active=0&q=jkt')->assertOk()->assertJsonPath('meta.total', 1);
    $api('POST', 'stock-receipts', ['warehouse_id' => $w] + $this->receipt)->assertNotFound();
    $api('POST', 'stock-receipts', ['warehouse_id' => $w2] + $this->receipt)->assertOk();
    $api('PATCH', "warehouses/$w2", ['active' => false])->assertConflict();
});

it('applies physical receipts and signed adjustments once, with atomic audit and immutable source records', function () {
    $this->actor->assignRole('inventory_staff');
    $api = $this->api;
    $input = ['warehouse_id' => ($this->open)()] + $this->receipt;
    DB::table('reason_codes')->insert(['domain' => 'payment', 'code' => 'WRONG', 'label' => 'Unrelated']);
    $api('POST', 'stock-receipts', ['reason_code' => 'WRONG'] + $input)->assertUnprocessable();
    $api('POST', 'stock-receipts', ['qty' => 0] + $input)->assertUnprocessable();
    $api('POST', 'stock-receipts', ['sku' => 'MISSING'] + $input)->assertNotFound();
    $this->assertDatabaseCount('inventory_levels', 0);
    $id = $api('POST', 'stock-receipts', $input, 'stock-open')->assertOk()->assertJsonPath('data.available', 10)->json('data.id');
    $api('POST', 'stock-receipts', $input, 'stock-open')->assertOk()->assertJsonPath('data.available', 10);
    $api('POST', 'stock-receipts', ['qty' => 1] + $input, 'stock-open')->assertUnprocessable();
    $api('POST', 'stock-receipts', $input)->assertConflict()->assertJsonPath('code', 'QUANTITY_CONFLICT');
    $purchase = ['type' => 'PURCHASE', 'qty' => 2] + $input;
    $api('POST', 'stock-receipts', $purchase, 'restock')->assertOk()->assertJsonPath('data.on_hand', 12);
    DB::table('idempotency_keys')->where('key', 'restock')->update(['expires_at' => now()->subMinute()]);
    $api('POST', 'stock-receipts', $purchase, 'restock')->assertConflict();
    $adjust = ['level_id' => $id, 'delta' => -12, 'reason_code' => 'COUNT', 'note' => 'internal'];
    $api('POST', 'stock-adjustments', ['delta' => -13] + $adjust)->assertConflict()->assertJsonPath('code', 'QUANTITY_CONFLICT');
    $api('POST', 'stock-adjustments', ['delta' => 0] + $adjust)->assertUnprocessable();
    $adjustment = $api('POST', 'stock-adjustments', $adjust, 'count')->assertCreated()->json('data.id');
    $api('POST', 'stock-adjustments', $adjust, 'count')->assertCreated()->assertJsonPath('data.id', $adjustment);
    $api('GET', "stock-levels/$id")->assertOk()->assertJsonPath('data.on_hand', 0);
    $api('GET', 'stock-levels?low_stock=true&sku=ALB-2&warehouse=JKT')->assertOk()->assertJsonPath('meta.total', 1);
    $api('GET', 'stock-levels?low_stock=false')->assertOk()->assertJsonPath('meta.total', 0);
    $api('GET', 'stock-adjustments?status=APPLIED')->assertOk()->assertJsonPath('meta.total', 1);
    $api('GET', "stock-adjustments/$adjustment")->assertOk()->assertJsonPath('data.delta', -12)->assertJsonMissingPath('data.note');
    $page = $api('GET', "stock-movements?level_id=$id&per_page=1")->assertOk()->assertJsonPath('data.0.type', 'ADJUSTMENT_OUT');
    $cursor = urlencode($page->json('meta.next_cursor'));
    $api('GET', "stock-movements?per_page=1&cursor=$cursor")->assertOk()->assertJsonPath('data.0.type', 'PURCHASE');
    $api('GET', 'stock-movements?cursor=bad')->assertUnprocessable();
    $api('GET', 'stock-movements?from=2025-03-02&to=2025-01-01')->assertUnprocessable();
    $api('GET', 'stock-adjustments?status=DRAFT')->assertUnprocessable();
    $movement = $page->json('data.0.id');
    $api('GET', "stock-movements/$movement")->assertOk()->assertJsonPath('data.reason_code', 'COUNT')->assertJsonMissingPath('data.actor_id');
    $this->assertDatabaseCount('inventory_movements', 3);
    $this->assertDatabaseCount('stock_adjustments', 1);
    expect(DB::table('inventory_movements')->sum('on_hand_delta'))->toEqual(0)
        ->and(DB::table('outbox_events')->where('event_type', 'StockReceived')->count())->toBe(2);
    $audit = DB::table('audit_logs')->where('action', 'StockAdjusted')->first();
    expect(json_decode($audit->before)->quantity)->toBe(12)->and(json_decode($audit->after)->quantity)->toBe(0);
    expect(fn () => DB::table('inventory_movements')->where('id', $movement)->update(['note' => 'edited']))->toThrow(QueryException::class);
    expect(fn () => DB::table('inventory_movements')->where('id', $movement)->delete())->toThrow(QueryException::class);
});

it('projects only available default-warehouse units into public RTS prices and keeps internal stock private', function () {
    $this->actor->assignRole('inventory_staff');
    $api = $this->api;
    $w = ($this->open)();
    $w2 = $api('POST', 'warehouses', ['code' => 'OTHER'] + $this->warehouse)->assertCreated()->json('data.id');
    $api('POST', 'stock-receipts', ['warehouse_id' => $w2] + $this->receipt)->assertOk();
    $this->getJson('/api/v1/products/album')->assertOk()->assertJsonPath('data.offers.0.available', false);
    $level = $api('POST', 'stock-receipts', ['warehouse_id' => $w] + $this->receipt)->assertOk()->json('data.id');
    $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.offers.0.available', true)->assertJsonPath('data.0.offers.0.from_amount', 35000);
    $this->getJson('/api/v1/products/album')->assertOk()->assertJsonPath('data.variants.0.offers.0.available', false)
        ->assertJsonPath('data.variants.1.offers.0.available', true)->assertJsonMissingPath('data.variants.1.on_hand');
    // Simulate a level held by the reservation owner (TASK-012), without making physical receipts saleable twice.
    DB::table('inventory_levels')->where('id', $level)->update(['reserved' => 3, 'committed' => 7]);
    $api('POST', 'stock-adjustments', ['level_id' => $level, 'delta' => -1, 'reason_code' => 'COUNT'])->assertConflict();
    $this->getJson('/api/v1/products/album')->assertOk()->assertJsonPath('data.offers.0.available', false);
});

it('keeps stocked SKU units and options attached to the physical goods', function () {
    $this->actor->assignRole('inventory_staff')->givePermissionTo('catalog.variant.update');
    $api = $this->api;
    $variant = DB::table('product_variants')->where('sku', 'ALB-2')->value('id');
    $attribute = DB::table('attributes')->insertGetId(['name' => 'Size', 'code' => 'size']);
    $option = DB::table('attribute_values')->insertGetId(['attribute_id' => $attribute, 'label' => 'Medium', 'code' => 'M']);
    $box = DB::table('units')->insertGetId(['code' => 'box', 'name' => 'Box']);
    $level = $api('POST', 'stock-receipts', ['warehouse_id' => ($this->open)()] + $this->receipt)->assertOk()->json('data.id');
    foreach ([['unit_id' => $box], ['attribute_value_ids' => [$option]], ['sku' => 'BOX-2']] as $edit) {
        $api('PATCH', "variants/$variant", $edit)->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    }
    $api('PATCH', "variants/$variant", ['name' => 'New label'])->assertOk();
    $api('POST', 'stock-adjustments', ['level_id' => $level, 'delta' => -10, 'reason_code' => 'COUNT'])->assertCreated();
    $api('PATCH', "variants/$variant", ['unit_id' => $box, 'attribute_value_ids' => [$option]])->assertOk();
});

it('requires clearing every warehouse before retiring a variant', function () {
    $this->actor->assignRole('inventory_staff')->givePermissionTo('catalog.variant.update', 'catalog.variant.archive');
    $api = $this->api;
    ($this->open)();
    $other = $api('POST', 'warehouses', ['code' => 'SECONDARY'] + $this->warehouse)->assertCreated()->json('data.id');
    $level = $api('POST', 'stock-receipts', ['warehouse_id' => $other] + $this->receipt)->assertOk()->json('data.id');
    $path = 'variants/'.DB::table('product_variants')->where('sku', 'ALB-2')->value('id');
    $api('PATCH', $path, ['active' => false])->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    $api('POST', 'stock-adjustments', ['level_id' => $level, 'delta' => -10, 'reason_code' => 'COUNT'])->assertCreated();
    $api('PATCH', $path, ['active' => false])->assertOk();
    $api('POST', 'stock-receipts', ['warehouse_id' => $other] + $this->receipt)->assertNotFound();
});

function inventoryWriter(array $input, int $actor): Closure
{
    return static function () use ($input, $actor) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected disposable test database.');
        }
        try {
            app(StockLedger::class)->adjust($input, $actor, (string) Str::uuid());

            return 'ok';
        } catch (ApiProblem $e) {
            return $e->errorCode;
        }
    };
}

it('serializes concurrent withdrawals on one level without double spending', function () {
    $this->actor->assignRole('inventory_staff');
    $level = ($this->api)('POST', 'stock-receipts', ['warehouse_id' => ($this->open)()] + $this->receipt)->assertOk()->json('data.id');
    $debit = inventoryWriter(['level_id' => $level, 'delta' => -7, 'reason_code' => 'COUNT'], $this->actor->id);
    expect(Concurrency::driver('process')->run([$debit, $debit]))->toEqualCanonicalizing(['ok', 'QUANTITY_CONFLICT']);
    expect(DB::table('inventory_levels')->value('available'))->toBe(3)
        ->and(DB::table('inventory_movements')->sum('on_hand_delta'))->toEqual(3)
        ->and(DB::table('stock_adjustments')->count())->toBe(1);
});
