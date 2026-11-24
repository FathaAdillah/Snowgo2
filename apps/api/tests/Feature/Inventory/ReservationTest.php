<?php

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockExpiry;
use App\Modules\Inventory\StockLedger;
use App\Modules\Inventory\StockReservations;
use App\Support\Http\ApiProblem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->actor = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->buyer = User::factory()->create()->id;
    $district = DB::table('regions')->insertGetId(['code' => 'ID', 'name' => 'Indonesia', 'kind' => 'COUNTRY']);
    $w = DB::table('warehouses')->insertGetId(['code' => 'JKT', 'name' => 'JKT', 'district_id' => $district, 'address' => 'JKT', 'is_default' => true]);
    $product = DB::table('products')->insertGetId(['slug' => 'album', 'name' => 'Album', 'status' => 'PUBLISHED', 'published_at' => now()]);
    $unit = DB::table('units')->insertGetId(['code' => 'pcs', 'name' => 'Piece']);
    $this->variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'unit_id' => $unit, 'name' => 'Album', 'sku' => 'ALB', 'option_key' => 'one',
        'weight_g' => 100, 'rts_enabled' => true, 'rts_price_amount' => 2000]);
    DB::table('reason_codes')->insert(['domain' => 'inventory', 'code' => 'COUNT', 'label' => 'Count']);
    $this->level = app(StockLedger::class)->receive(['warehouse_id' => $w, 'sku' => 'ALB', 'qty' => 10, 'type' => 'INITIAL', 'reason_code' => 'COUNT'], $this->actor->id, 'opening');
    $this->stock = app(StockReservations::class);
    $this->expiry = app(StockExpiry::class);
    $this->order = function (array $quantities = [5], string $method = 'READY_TO_SHIP') {
        $order = DB::table('orders')->insertGetId(['number' => 'ORD-1', 'user_id' => $this->buyer, 'selling_method' => $method, 'payment_due_at' => now()->addHour()]);
        foreach ($quantities as $qty) {
            DB::table('order_items')->insert(['order_id' => $order, 'variant_id' => $this->variant, 'sku_snapshot' => 'ALB', 'qty' => $qty]);
        }

        return $order;
    };
});

it('reserves atomically per order, commits once and only debits physical units at dispatch', function () {
    $order = ($this->order)([3, 5]);
    [$a, $b] = $this->stock->reserve($order);
    expect($this->stock->reserve($order))->toBe([$a, $b]);
    DB::table('order_items')->where('order_id', $order)->update(['sku_snapshot' => 'DIFFERENT']);
    expect(fn () => $this->stock->reserve($order))->toThrow(ApiProblem::class);
    DB::table('order_items')->where('order_id', $order)->update(['sku_snapshot' => 'ALB']);
    $this->assertDatabaseHas('inventory_levels', ['on_hand' => 10, 'available' => 2, 'reserved' => 8]);
    $this->stock->commit($order, 99);
    $this->stock->commit($order, 99);
    expect(fn () => $this->stock->commit($order, 100))->toThrow(ApiProblem::class);
    $this->assertDatabaseHas('inventory_levels', ['on_hand' => 10, 'available' => 2, 'reserved' => 0, 'committed' => 8]);
    $this->stock->ship($order, $a, 2, 44);
    $this->stock->ship($order, $a, 2, 44);
    expect(fn () => $this->stock->ship($order, $b, 2, 44))->toThrow(ApiProblem::class)
        ->and(fn () => $this->stock->ship($order, $a, 1, 44))->toThrow(ApiProblem::class)
        ->and(fn () => $this->stock->ship($order, $a, 2, 45))->toThrow(ApiProblem::class);
    $this->assertDatabaseHas('stock_reservations', ['id' => $a, 'status' => 'COMMITTED', 'shipped_qty' => 2]);
    $this->stock->releaseCommitted($order, $a, 1, 33);
    $this->stock->releaseCommitted($order, $a, 1, 33);
    $this->stock->releaseCommitted($order, $b, 5, 34);
    $this->assertDatabaseHas('stock_reservations', ['id' => $a, 'status' => 'CONSUMED', 'released_qty' => 1]);
    $this->assertDatabaseHas('stock_reservations', ['id' => $b, 'status' => 'RELEASED', 'shipped_qty' => 0]);
    $this->assertDatabaseHas('inventory_levels', ['on_hand' => 8, 'available' => 8, 'reserved' => 0, 'committed' => 0]);
    expect(DB::table('outbox_events')->where('event_type', 'StockShipped')->count())->toBe(1)
        ->and(DB::table('inventory_movements')->sum('on_hand_delta'))->toEqual(8)
        ->and(DB::table('inventory_movements')->sum('reserved_delta'))->toEqual(0)
        ->and(DB::table('inventory_movements')->sum('committed_delta'))->toEqual(0);
    $this->assertDatabaseCount('stock_expiry_work', 0);
    $this->assertDatabaseHas('audit_logs', ['subject_type' => 'stock_reservation', 'subject_id' => $a, 'action' => 'StockShipped']);
    $this->assertDatabaseHas('status_histories', ['subject_type' => 'stock_reservation', 'subject_id' => $a, 'from_status' => 'COMMITTED', 'to_status' => 'CONSUMED']);
});

it('rejects a non-RTS owner and rolls back every line if the last one cannot be held', function () {
    $order = ($this->order)([4, 7], 'PREORDER');
    expect(fn () => $this->stock->reserve($order))->toThrow(ApiProblem::class);
    DB::table('orders')->where('id', $order)->update(['selling_method' => 'READY_TO_SHIP']);
    expect(fn () => $this->stock->reserve($order))->toThrow(ApiProblem::class);
    $this->assertDatabaseCount('stock_reservations', 0);
    $this->assertDatabaseCount('stock_expiry_work', 0);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->assertDatabaseHas('inventory_levels', ['available' => 10]);
    $this->assertDatabaseMissing('outbox_events', ['event_type' => 'StockReserved']);
});

it('extends only the first valid proof and never lets stale expiry release committed or cancelled stock', function () {
    $order = ($this->order)();
    [$id] = $this->stock->reserve($order);
    $original = DB::table('stock_expiry_work')->value('due_at');
    $this->stock->extendForProof($order, 42);
    $extended = DB::table('stock_expiry_work')->value('due_at');
    expect(strtotime($extended))->toBeGreaterThan(strtotime($original));
    $this->stock->extendForProof($order, 42);
    expect(DB::table('stock_expiry_work')->value('due_at'))->toBe($extended)
        ->and(fn () => $this->stock->extendForProof($order, 43))->toThrow(ApiProblem::class)
        ->and($this->expiry->claim())->toBe([]);
    $this->stock->release($order);
    $this->stock->release($order);
    $this->assertDatabaseHas('stock_reservations', ['id' => $id, 'status' => 'RELEASED', 'released_qty' => 5]);
    expect($this->stock->expire($order, (string) Str::uuid()))->toBeFalse()
        ->and(DB::table('inventory_movements')->where('type', 'RESERVATION_RELEASE')->count())->toBe(1);
});

/**
 * Workers share only a bounded start barrier. Each boots its own Laravel process and DB connection.
 */
function stockWorker(string $dir, string $name, array $args): Closure
{
    return static function () use ($dir, $name, $args) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected disposable test database.');
        }
        touch("$dir/$name");
        $timeout = microtime(true) + 15;
        while (count(glob("$dir/*")) < 2) {
            if (microtime(true) > $timeout) {
                throw new RuntimeException('Worker failed to reach the start barrier.');
            }
            usleep(1000);
        }
        $stock = app(StockReservations::class);
        try {
            return DB::transaction(function () use ($args, $stock, $name) {
                switch ($args['command']) {
                    case 'reserve':
                        $order = DB::table('orders')->insertGetId(['number' => $name, 'user_id' => $args['buyer'], 'selling_method' => 'READY_TO_SHIP', 'payment_due_at' => now()->addHour()]);
                        DB::table('order_items')->insert(['order_id' => $order, 'variant_id' => $args['variant'], 'qty' => 10, 'sku_snapshot' => 'ALB']);
                        $stock->reserve($order);
                        break;
                    case 'expire':
                        return $stock->expire($args['order'], $args['token']) ? 'expired' : 'ignored';
                    case 'commit':
                        $stock->commit($args['order'], 35);
                        break;
                    case 'ship':
                        $stock->ship($args['order'], $args['hold'], 5, 37);
                        break;
                    case 'adjust':
                        app(StockLedger::class)->adjust(['level_id' => $args['level'], 'delta' => -5, 'reason_code' => 'COUNT'], $args['actor'], 'adjust');
                        break;
                }

                return 'ok';
            });
        } catch (ApiProblem $e) {
            return $e->errorCode;
        }
    };
}

function raceStock(array $a, array $b): array
{
    $dir = sys_get_temp_dir().'/rts-'.Str::uuid();
    mkdir($dir, 0700);
    try {
        return Concurrency::driver('process')->run([stockWorker($dir, 'first', $a), stockWorker($dir, 'second', $b)]);
    } finally {
        foreach (glob("$dir/*") as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

it('allows exactly one checkout to reserve the last units across two database connections', function () {
    $args = ['command' => 'reserve', 'buyer' => $this->buyer, 'variant' => $this->variant];
    expect(raceStock($args, ['buyer' => User::factory()->create()->id] + $args))->toEqualCanonicalizing(['ok', 'OUT_OF_STOCK']);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('stock_reservations', 1);
    $this->assertDatabaseHas('inventory_levels', ['available' => 0, 'reserved' => 10, 'on_hand' => 10]);
});

it('recovers abandoned expiry claims and fences a racing capture at the database deadline', function () {
    $order = ($this->order)();
    [$id] = $this->stock->reserve($order);
    DB::table('stock_expiry_work')->update(['due_at' => now()->subMinute()]);
    DB::table('stock_reservations')->update(['expires_at' => now()->subMinute()]);
    [$old] = $this->expiry->claim();
    expect($this->expiry->claim())->toBe([]);
    DB::table('stock_expiry_work')->update(['lease_until' => now()->subSecond()]);
    [$new] = $this->expiry->claim();
    expect($this->stock->expire($order, $old->lease_token))->toBeFalse()
        ->and(raceStock(['command' => 'expire', 'order' => $order, 'token' => $new->lease_token], ['command' => 'commit', 'order' => $order]))
        ->toEqualCanonicalizing(['expired', 'INVALID_STATE_TRANSITION'])
        ->and($this->expiry->run($this->stock))->toBe(0);
    $this->assertDatabaseHas('stock_reservations', ['id' => $id, 'status' => 'EXPIRED']);
    $this->assertDatabaseHas('inventory_levels', ['available' => 10, 'reserved' => 0]);
});

it('protects committed units from timeout and keeps ledger totals during concurrent adjustment and dispatch', function () {
    $order = ($this->order)();
    [$hold] = $this->stock->reserve($order);
    $this->stock->commit($order, 35);
    expect($this->stock->expire($order, (string) Str::uuid()))->toBeFalse()
        ->and(raceStock(['command' => 'ship', 'order' => $order, 'hold' => $hold],
            ['command' => 'adjust', 'level' => $this->level, 'actor' => $this->actor->id]))->toEqual(['ok', 'ok']);
    $this->assertDatabaseHas('inventory_levels', ['on_hand' => 0, 'available' => 0, 'committed' => 0]);
    expect(DB::table('inventory_movements')->sum('on_hand_delta'))->toEqual(0);
});

it('requires the reservation read grant for list and detail, without exposing mutation commands', function () {
    config(['sanctum.stateful' => ['admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->seed(PermissionSeeder::class);
    $this->actingAs($this->actor)->withHeader('Origin', 'http://admin.localhost')->withSession([
        DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $this->actor->staff_session_version,
        'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);
    [$id] = $this->stock->reserve(($this->order)());
    $base = '/api/v1/admin/stock-reservations';
    $this->getJson($base)->assertForbidden();
    $this->getJson("$base/$id")->assertForbidden();
    $this->actor->assignRole('order_staff');
    $this->getJson("$base?order=ORD-1&status=ACTIVE&per_page=1")->assertOk()->assertJsonPath('data.0.sku', 'ALB')->assertJsonPath('meta.total', 1);
    $this->getJson("$base?status=COMMITTED")->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("$base?expires_before=2000-01-01")->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("$base/$id")->assertOk()->assertJsonPath('data.qty', 5)->assertJsonMissingPath('data.lease_token');
    $this->getJson("$base/99999")->assertNotFound();
    $this->getJson("$base?status=NOT_A_STATUS")->assertUnprocessable();
    $this->postJson($base)->assertMethodNotAllowed();
    $this->getJson('/api/v1/admin/stock-movements')->assertForbidden();
});
