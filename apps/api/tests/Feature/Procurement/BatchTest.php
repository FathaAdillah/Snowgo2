<?php

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Procurement\BatchLifecycle;
use App\Support\Http\ApiProblem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
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
    $this->staff->assignRole('order_staff');
    $this->actingAs($this->staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF',
        'staff.version' => $this->staff->staff_session_version, 'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);
    $this->api = '/api/v1/admin/procurement-batches';
    $this->postBatch = fn (string $path, array $body, ?string $key = null) => $this->postJson($this->api.$path, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $this->campaign = DB::table('preorder_campaigns')->value('id');
    DB::table('preorder_campaigns')->update(['status' => 'IN_PROCUREMENT']);
    DB::table('preorder_campaign_variants')->update(['quota' => 10, 'confirmed_qty' => 4, 'max_qty' => 10]);
    DB::table('reason_codes')->insert(['domain' => 'procurement', 'code' => 'SHORT', 'label' => 'Kekurangan']);
    $this->newLine = function (int $qty, ?int $request = null, ?int $owner = null) {
        $ref = (string) Str::ulid();
        $order = DB::table('orders')->insertGetId(['number' => 'ORD-'.$ref, 'user_id' => $owner ?? User::factory()->create()->id,
            'selling_method' => $request ? 'REQUEST_ORDER' : 'PREORDER', 'request_order_id' => $request, 'campaign_id' => $request ? null : $this->campaign,
            'status' => 'CONFIRMED', 'payment_status' => 'PARTIALLY_PAID', 'payment_due_at' => now()->addDay()]);
        $line = DB::table('order_items')->insertGetId(['public_id' => $ref, 'order_id' => $order, 'qty' => $qty, 'sku_snapshot' => 'A1']);
        DB::table('invoices')->insert(['number' => 'INV-'.$ref, 'order_id' => $order, 'purpose' => 'DEPOSIT', 'source_ref' => 'checkout:initial', 'status' => 'PAID',
            'subtotal_amount' => 10, 'tax_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 10, 'due_at' => now()]);
        if (! $request) {
            DB::table('preorder_allocations')->insert(['order_item_id' => $line, 'campaign_variant_id' => DB::table('preorder_campaign_variants')->value('id'),
                'qty' => $qty, 'status' => 'CONFIRMED', 'expires_at' => now()]);
        }

        return $ref;
    };
    $this->assign = fn ($ref, int $qty) => ['campaign_number' => 'PO-1', 'items' => [['order_item_id' => $ref, 'qty' => $qty]]];
});

it('records independent buyer receipts, retries and final shortages without touching sellable stock', function () {
    $a = ($this->newLine)(3);
    $b = ($this->newLine)(1);
    $payload = ($this->assign)($a, 3);
    $payload['items'][] = ['order_item_id' => $b, 'qty' => 1];
    $number = ($this->postBatch)('', $payload, 'create')->assertCreated()->json('data.number');
    ($this->postBatch)('', $payload, 'create')->assertCreated()->assertJsonPath('data.number', $number);
    $this->getJson($this->api.'/eligible-items')->assertOk()->assertJsonCount(0, 'data');
    $stage = "/$number/stage-events";
    $receipt = "/$number/receipts";
    ($this->postBatch)($stage, ['stage' => 'SELLER_SHIPPED'])->assertConflict();
    ($this->postBatch)($stage, ['stage' => 'PURCHASED', 'eta_date' => '2027-01-02', 'note' => 'Supplier confirmed'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    ($this->postBatch)("/$number/closures", [])->assertConflict();
    $partial = ['items' => [['order_item_id' => $a, 'received_qty' => 2, 'failed_qty' => 0]]];
    ($this->postBatch)($receipt, $partial, 'receive')->assertOk();
    ($this->postBatch)($receipt, $partial, 'receive')->assertOk();
    $this->assertDatabaseHas('order_items', ['public_id' => $a, 'received_qty' => 2, 'cancelled_qty' => 0]);
    $this->assertDatabaseHas('order_items', ['public_id' => $b, 'received_qty' => 0]);
    expect(DB::table('preorder_campaigns')->value('first_received_at'))->not->toBeNull();
    $fail = ['items' => [['order_item_id' => $a, 'received_qty' => 0, 'failed_qty' => 1], ['order_item_id' => $b, 'received_qty' => 0, 'failed_qty' => 2]]];
    ($this->postBatch)($receipt, $fail)->assertUnprocessable();
    ($this->postBatch)($receipt, $fail + ['reason_code' => 'SHORT'])->assertConflict();
    $this->assertDatabaseCount('procurement_receipt_lines', 1);
    $this->assertDatabaseCount('order_transition_holds', 0);
    $fail['items'][1]['failed_qty'] = 1;
    ($this->postBatch)($receipt, $fail + ['reason_code' => 'SHORT'], 'short')->assertOk();
    ($this->postBatch)($receipt, $fail + ['reason_code' => 'SHORT'], 'short')->assertOk();
    $this->assertDatabaseCount('order_transition_holds', 2);
    expect(DB::table('outbox_events')->where('event_type', 'SupplierShortageReviewRequested')->count())->toBe(2);
    $this->assertDatabaseHas('preorder_allocations', ['qty' => 3, 'failed_qty' => 1, 'status' => 'CONFIRMED']);
    $this->assertDatabaseHas('preorder_allocations', ['qty' => 1, 'failed_qty' => 1, 'status' => 'FAILED']);
    $this->assertDatabaseHas('preorder_campaign_variants', ['confirmed_qty' => 4]);
    $this->assertDatabaseCount('inventory_movements', 1);
    foreach (['SELLER_SHIPPED', 'INTERNATIONAL_TRANSIT', 'AT_DESTINATION_WAREHOUSE', 'RECEIVED'] as $step) {
        ($this->postBatch)($stage, ['stage' => $step])->assertOk();
    }
    ($this->postBatch)("/$number/closures", [])->assertOk()->assertJsonPath('data.status', 'CLOSED')->assertJsonCount(6, 'data.stage_events');
    $this->getJson($this->api.'?status=CLOSED')->assertOk()->assertJsonPath('data.0.number', $number);
});

it('returns only unresolved assignments to the pool when cancelling a failed supplier attempt', function () {
    $id = ($this->newLine)(3);
    $payload = ($this->assign)($id, 3);
    $n = ($this->postBatch)('', $payload)->assertCreated()->json('data.number');
    ($this->postBatch)("/$n/stage-events", ['stage' => 'PURCHASED'])->assertOk();
    ($this->postBatch)("/$n/receipts", ['items' => [['order_item_id' => $id, 'received_qty' => 1, 'failed_qty' => 0]]])->assertOk();
    ($this->postBatch)("/$n/stage-events", ['stage' => 'FAILED'])->assertUnprocessable();
    ($this->postBatch)("/$n/stage-events", ['stage' => 'FAILED', 'note' => 'Supplier cancelled the remainder'])->assertOk();
    ($this->postBatch)("/$n/closures", [])->assertUnprocessable();
    ($this->postBatch)("/$n/closures", ['reason_code' => 'SHORT'])->assertOk()->assertJsonPath('data.closure_reason', 'SHORT');
    $this->getJson($this->api.'/eligible-items?number=PO-1')->assertOk()->assertJsonPath('data.0.available_qty', 2);
    ($this->postBatch)('', $payload)->assertConflict();
    ($this->postBatch)('', ($this->assign)($id, 2))->assertCreated();
    $this->assertDatabaseCount('order_transition_holds', 0);
});

it('permits a pristine rollback but preserves procurement evidence after first use', function () {
    $migration = require app_path('Modules/Procurement/Database/Migrations/2026_11_25_045423_create_procurement_batches.php');
    DB::transaction(function () use ($migration) {
        $migration->down();
        $migration->up();
    });
    $id = ($this->newLine)(1);
    $n = ($this->postBatch)('', ($this->assign)($id, 1))->assertCreated()->json('data.number');
    ($this->postBatch)("/$n/stage-events", ['stage' => 'PURCHASED'])->assertOk();
    ($this->postBatch)("/$n/receipts", ['reason_code' => 'SHORT', 'items' => [['order_item_id' => $id, 'received_qty' => 0, 'failed_qty' => 1]]])->assertOk();
    expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class, 'Cannot roll back recorded procurement batches');
    $this->assertDatabaseCount('procurement_receipt_lines', 1);
    $this->assertDatabaseCount('order_transition_holds', 1);
});

it('restricts request batches to paid items of one converted request and disallows mixed sources', function () {
    $owner = User::factory()->create()->id;
    $request = DB::table('request_orders')->insertGetId(['number' => 'REQ-1', 'user_id' => $owner, 'title' => 'Album', 'description' => 'A', 'status' => 'CONVERTED_TO_ORDER']);
    $ref = ($this->newLine)(1, $request, $owner);
    $po = ($this->newLine)(1);
    $items = [['order_item_id' => $ref, 'qty' => 1]];
    ($this->postBatch)('', ['request_number' => 'REQ-1', 'campaign_number' => 'PO-1', 'items' => $items])->assertUnprocessable();
    ($this->postBatch)('', ['request_number' => 'REQ-1', 'items' => [...$items, ['order_item_id' => $po, 'qty' => 1]]])->assertNotFound();
    DB::table('invoices')->where('number', 'INV-'.$ref)->update(['status' => 'ISSUED']);
    ($this->postBatch)('', ['request_number' => 'REQ-1', 'items' => $items])->assertConflict();
    DB::table('invoices')->where('number', 'INV-'.$ref)->update(['status' => 'PAID']);
    $n = ($this->postBatch)('', ['request_number' => 'REQ-1', 'items' => $items])->assertCreated()->json('data.number');
    ($this->postBatch)("/$n/stage-events", ['stage' => 'PURCHASED'])->assertOk();
    ($this->postBatch)("/$n/receipts", ['reason_code' => 'SHORT', 'items' => [['order_item_id' => $ref, 'received_qty' => 0, 'failed_qty' => 1]]])
        ->assertOk()->assertJsonPath('data.stage', 'FAILED');
    $this->assertDatabaseHas('orders', ['number' => 'ORD-'.$ref, 'status' => 'CONFIRMED']);
    $this->assertDatabaseHas('preorder_campaigns', ['first_received_at' => null]);
});

it('enforces read, manage and individual grants independently on every procurement action', function () {
    $this->staff->syncRoles([]);
    $this->getJson($this->api)->assertForbidden();
    $this->staff->assignRole('warehouse');
    $this->getJson($this->api)->assertOk();
    $this->getJson($this->api.'/eligible-items')->assertForbidden();
    $this->staff->givePermissionTo('procurement.batch.manage');
    foreach (['', '/BAT-1/stage-events', '/BAT-1/receipts', '/BAT-1/closures'] as $path) {
        ($this->postBatch)($path, [])->assertForbidden();
    }
    $this->staff->givePermissionTo('procurement.batch.create');
    $this->getJson($this->api.'/eligible-items')->assertOk();
    $id = ($this->newLine)(1);
    $data = ($this->assign)($id, 1);
    ($this->postBatch)('', $data, 'mine')->assertCreated();
    $this->staff->revokePermissionTo('procurement.batch.create');
    ($this->postBatch)('', $data, 'mine')->assertForbidden();
});

it('rejects unconfirmed units, unknown fields, and reads from a customer session', function () {
    $id = ($this->newLine)(1);
    $data = ($this->assign)($id, 1);
    ($this->postBatch)('', $data + ['status' => 'CLOSED'])->assertUnprocessable();
    $invalid = $data;
    $invalid['items'][0]['received_qty'] = 1;
    ($this->postBatch)('', $invalid)->assertUnprocessable();
    DB::table('preorder_campaigns')->update(['status' => 'CLOSED']);
    ($this->postBatch)('', $data)->assertConflict();
    DB::table('preorder_campaigns')->update(['status' => 'IN_PROCUREMENT']);
    DB::table('preorder_allocations')->update(['status' => 'RESERVED']);
    ($this->postBatch)('', $data)->assertConflict();
    $this->getJson($this->api.'/eligible-items')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->buyer)->withSession([DeviceSessions::SURFACE_KEY => 'CUSTOMER']);
    $this->getJson($this->api)->assertForbidden();
    ($this->postBatch)('', $data)->assertForbidden();
    $this->assertDatabaseCount('procurement_batches', 0);
});

function procurementWorker(array $data, int $actor, string $barrier): Closure
{
    return static function () use ($data, $actor, $barrier) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected disposable test database.');
        }
        touch($barrier.'/'.getmypid());
        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/*')) < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Procurement workers did not reach the barrier.');
            }
            usleep(20000);
        }
        try {
            DB::transaction(fn () => app(BatchLifecycle::class)->create($data, $actor));

            return 'OK';
        } catch (ApiProblem $e) {
            return $e->errorCode;
        }
    };
}

it('assigns the last unit to only one of two independent database transactions', function () {
    $data = ($this->assign)(($this->newLine)(1), 1);
    $barrier = sys_get_temp_dir().'/procurement-'.Str::uuid();
    mkdir($barrier, 0700);
    try {
        $worker = procurementWorker($data, $this->staff->id, $barrier);
        $outcomes = Concurrency::driver('process')->run([$worker, $worker]);
    } finally {
        foreach (glob($barrier.'/*') as $flag) {
            unlink($flag);
        }
        rmdir($barrier);
    }
    expect($outcomes)->toContain('OK', 'QUANTITY_CONFLICT');
    $this->assertDatabaseCount('procurement_batches', 1);
    $this->assertDatabaseCount('procurement_batch_items', 1);
});
