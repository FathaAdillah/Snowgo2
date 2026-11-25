<?php

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Orders\OrderExpiry;
use App\Modules\Payments\BankAccounts;
use App\Modules\Payments\PaymentFlow;
use App\Modules\Payments\Settlement;
use App\Modules\RequestOrders\CaseData;
use App\Modules\RequestOrders\QuotationData;
use App\Modules\RequestOrders\QuotationLifecycle;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->buyer = User::factory()->create();
    $this->staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->staff->assignRole('order_staff');
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->operator = fn () => $this->actingAs($this->staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $this->staff->staff_session_version, 'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);
    $this->customer = fn ($u) => $this->actingAs($u)->withHeader('Origin', 'http://localhost')->withSession([DeviceSessions::SURFACE_KEY => 'CUSTOMER']);
    ($this->operator)();
    $this->case = DB::table('request_orders')->insertGetId(['user_id' => $this->buyer->id, 'number' => 'REQ-1', 'title' => 'Case', 'description' => 'Description', 'status' => 'UNDER_REVIEW']);
    $this->item = (string) Str::ulid();
    DB::table('request_order_items')->insert(['request_order_id' => $this->case, 'public_id' => $this->item, 'name' => 'Box', 'qty' => 3]);
    $this->quotes = '/api/v1/admin/request-orders/REQ-1/quotations';
    $this->detail = '/api/v1/request-orders/REQ-1';
    $this->input = ['items' => [['request_item_id' => $this->item, 'label' => 'Set', 'qty' => 3, 'unit_amount' => 500, 'options' => 'Green', 'foreign_currency' => 'JPY', 'foreign_amount' => '13.20', 'exchange_rate' => '4.95']],
        'terms' => 'Goods only', 'term' => 'DEPOSIT', 'deposit_amount' => 1001, 'shipping_estimate_amount' => 250, 'valid_until' => now()->addDay()->toISOString()];
    $this->post = fn ($url, $data = [], $key = null) => $this->postJson($url, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $this->patchQuote = fn ($n, $data) => $this->patchJson("$this->quotes/$n", $data + ['revision' => 1], ['Idempotency-Key' => (string) Str::uuid()]);
    $this->draft = fn () => ($this->post)($this->quotes, $this->input)->assertCreated()->json('data.number');
    $this->dispatch = fn ($q, $revision = 1) => ($this->post)("$this->quotes/$q/dispatches", ['revision' => $revision])->assertOk();
    $this->address = function () {
        $parent = null;
        foreach (['COUNTRY' => 'ID', 'PROVINCE' => '11', 'CITY' => '11.01', 'DISTRICT' => '11.01.01'] as $kind => $code) {
            $parent = DB::table('regions')->insertGetId(['kind' => $kind, 'code' => $code, 'name' => $kind, 'parent_id' => $parent]);
        }

        return $this->checkoutAddress($this->buyer->id, $parent);
    };
});

it('rejects unknown, mismatched or out-of-range draft input without creating a version', function () {
    $other = DB::table('request_orders')->insertGetId(['user_id' => $this->buyer->id, 'number' => 'REQ-2', 'title' => 'Other', 'description' => 'Other']);
    $foreign = (string) Str::ulid();
    DB::table('request_order_items')->insert(['request_order_id' => $other, 'public_id' => $foreign, 'name' => 'Other', 'qty' => 1]);
    foreach ([['total_amount' => 1], ['term' => 'CREDIT'], ['deposit_amount' => 1500], ['term' => 'FULL'], ['items' => []]] as $bad) {
        ($this->post)($this->quotes, $bad + $this->input)->assertUnprocessable();
    }
    foreach ([['request_item_id' => $foreign], ['qty' => 0], ['unit_amount' => -1], ['field' => 'extra'], ['exchange_rate' => '0.00'], ['qty' => 2, 'unit_amount' => 1000000000000]] as $line) {
        ($this->post)($this->quotes, ['items' => [$line + $this->input['items'][0]]] + $this->input)->assertUnprocessable();
    }
    ($this->post)($this->quotes, ['valid_until' => now()->subHour()->toISOString()] + $this->input)->assertConflict()->assertJsonPath('code', 'QUOTE_EXPIRED');
    $this->assertDatabaseEmpty('quotations');
});

it('scopes drafts and edit permissions, freezes sent prices and supersedes versions atomically', function () {
    $q = ($this->draft)();
    ($this->customer)($this->buyer);
    $this->getJson($this->detail)->assertOk()->assertJsonCount(0, 'data.quotations');
    ($this->post)("$this->detail/quotations/$q/decisions", ['decision' => 'REJECT'])->assertNotFound();
    ($this->operator)();
    $this->staff->syncRoles([])->givePermissionTo('request_orders.request.view');
    $this->getJson('/api/v1/admin/request-orders/REQ-1')->assertOk()->assertJsonCount(0, 'data.quotations');
    $this->staff->givePermissionTo('request_orders.quotation.view', 'request_orders.quotation.manage');
    ($this->patchQuote)($q, ['terms' => 'Next'])->assertForbidden();
    ($this->post)("$this->quotes/$q/dispatches")->assertForbidden();
    $this->staff->assignRole('order_staff');
    ($this->patchQuote)($q, ['terms' => 'Next'])->assertOk()->assertJsonPath('data.terms', 'Next')->assertJsonPath('data.revision', 2);
    ($this->post)("$this->quotes/$q/dispatches")->assertUnprocessable();
    ($this->post)("$this->quotes/$q/dispatches", ['revision' => 1])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
    ($this->patchQuote)($q, ['terms' => 'Stale'])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
    $this->assertDatabaseHas('quotations', ['number' => $q, 'status' => 'DRAFT', 'terms' => 'Next']);
    $this->assertDatabaseMissing('outbox_events', ['event_type' => 'QuotationSent']);
    ($this->dispatch)($q, 2);
    ($this->patchQuote)($q, ['terms' => 'No'])->assertConflict();
    expect(fn () => DB::table('quotation_items')->update(['label' => 'No']))->toThrow(QueryException::class);
    expect(fn () => DB::table('quotations')->update(['terms' => 'No']))->toThrow(QueryException::class);
    $next = ($this->draft)();
    ($this->dispatch)($next)->assertJsonPath('data.version', 2);
    $this->assertDatabaseHas('quotations', ['number' => $q, 'status' => 'SUPERSEDED']);
    ($this->customer)(User::factory()->create());
    ($this->post)("$this->detail/quotations/$next/decisions", ['decision' => 'REJECT'])->assertNotFound();
    ($this->customer)($this->buyer);
    ($this->post)("$this->detail/quotations/$q/decisions", ['decision' => 'REVISE'])->assertConflict();
    ($this->post)("$this->detail/quotations/$next/decisions", ['decision' => 'REVISE', 'note' => 'Please revise'])->assertOk()->assertJsonPath('data.request_status', 'REVISION_REQUESTED');
    $this->getJson($this->detail)->assertOk()->assertJsonPath('data.messages.0.body', 'Please revise')->assertJsonCount(2, 'data.quotations')->assertJsonMissingPath('data.quotations.0.items.0.foreign_amount');
    ($this->operator)();
    $last = ($this->draft)();
    ($this->dispatch)($last);
    ($this->customer)($this->buyer);
    ($this->post)("$this->detail/quotations/$last/decisions", ['decision' => 'REJECT'])->assertOk()->assertJsonPath('data.request_status', 'UNDER_REVIEW')->assertJsonPath('data.quotation.status', 'REJECTED');
    $this->assertDatabaseEmpty('orders');
});

it('converts owned active addresses only and replays without duplicating invoice, journal or events', function () {
    $q = ($this->draft)();
    ($this->dispatch)($q);
    $address = ($this->address)();
    $foreign = $this->checkoutAddress(User::factory()->create()->id, DB::table('addresses')->value('district_id'));
    ($this->customer)($this->buyer);
    $url = "$this->detail/quotations/$q/decisions";
    ($this->post)($url, ['decision' => 'ACCEPT'])->assertUnprocessable();
    $data = ['decision' => 'ACCEPT', 'address_id' => $address, 'note' => 'Yes'];
    ($this->post)($url, ['address_id' => $foreign] + $data)->assertNotFound();
    DB::table('regions')->where('kind', 'DISTRICT')->update(['active' => false]);
    ($this->post)($url, $data)->assertUnprocessable();
    DB::table('regions')->where('kind', 'DISTRICT')->update(['active' => true]);
    $this->assertDatabaseEmpty('orders');
    $this->assertDatabaseEmpty('request_order_messages');
    $result = ($this->post)($url, $data, 'accept')->assertOk()->assertJsonPath('data.request_status', 'CONVERTED_TO_ORDER')->json('data');
    $counts = fn () => array_map(fn ($t) => DB::table($t)->count(), ['audit_logs', 'status_histories', 'outbox_events', 'request_order_messages']);
    $before = $counts();
    foreach (['accept', 'new'] as $key) {
        expect(($this->post)($url, $data, $key)->assertOk()->json('data'))->toEqual($result);
    }
    ($this->post)($url, ['note' => 'Changed'] + $data, 'accept')->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    expect($counts())->toEqual($before);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseHas('orders', ['selling_method' => 'REQUEST_ORDER', 'request_order_id' => $this->case, 'total_amount' => 1500, 'shipping_amount' => 0, 'number' => $result['order_number']]);
    $this->assertDatabaseHas('invoices', ['number' => $result['invoice_number'], 'purpose' => 'DEPOSIT', 'total_amount' => 1001]);
    $this->assertDatabaseHas('invoice_items', ['qty' => 2, 'unit_amount' => 334, 'amount' => 668]);
    $this->assertDatabaseHas('invoice_items', ['qty' => 1, 'unit_amount' => 333, 'amount' => 333]);
    DB::table('addresses')->where('public_id', $address)->update(['line1' => 'New address']);
    $this->assertDatabaseHas('order_addresses', ['line1' => 'Old address']);
    expect(fn () => DB::table('invoice_items')->update(['amount' => 1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('invoices')->update(['source_ref' => 'other']))->toThrow(QueryException::class);
    expect(fn () => DB::table('order_items')->update(['quotation_options' => 'Blue']))->toThrow(QueryException::class);
    $migration = require app_path('Modules/Orders/Database/Migrations/2026_11_25_085108_link_order_items_to_quotations.php');
    expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class, 'Cannot discard quotation conversions');
    $this->assertDatabaseEmpty('carts');
});

it('expires a converted request order but preserves its invoice through the late transfer window', function () {
    $quote = ($this->draft)();
    ($this->dispatch)($quote);
    ($this->customer)($this->buyer);
    $conversion = ($this->post)("$this->detail/quotations/$quote/decisions", ['decision' => 'ACCEPT', 'address_id' => ($this->address)()])->assertOk()->json('data');
    DB::transaction(fn () => BankAccounts::save(null, ['code' => 'MAIN', 'bank_code' => 'BANK', 'account_number' => '123456789', 'holder_name' => 'Shop', 'active' => true], $this->staff->id));
    $payment = DB::transaction(fn () => PaymentFlow::create($this->buyer->id, [$conversion['invoice_number']], 'MAIN'));
    $order = DB::table('orders')->where('number', $conversion['order_number']);
    $invoice = ['number' => $conversion['invoice_number']];
    app(OrderExpiry::class)->expireRequestOrders();
    $this->assertDatabaseHas('orders', ['number' => $conversion['order_number'], 'status' => 'PENDING_PAYMENT']);

    $order->update(['payment_due_at' => now()->subHour()]);
    DB::table('payments')->where('number', $payment)->update(['expires_at' => now()->subHour()]);
    PaymentFlow::expireDue();
    app(OrderExpiry::class)->expireRequestOrders();
    Settlement::closeWindows();
    $this->assertDatabaseHas('orders', ['number' => $conversion['order_number'], 'status' => 'EXPIRED']);
    $this->assertDatabaseHas('invoices', $invoice + ['status' => 'ISSUED']);

    DB::table('payments')->where('number', $payment)->update(['expires_at' => now()->subDays(8)]);
    Settlement::closeWindows();
    Settlement::closeWindows();
    $this->assertDatabaseHas('invoices', $invoice + ['status' => 'VOID']);
    expect(DB::table('outbox_events')->where('event_type', 'InvoiceVoided')->count())->toBe(1);
});

it('rejects a time-expired sent quotation before and after scheduler cleanup', function () {
    $q = ($this->draft)();
    // A previously dispatched quotation that expired between scheduler ticks.
    DB::table('quotations')->where('number', $q)->update(['status' => 'SENT', 'sent_at' => now()->subDay(), 'valid_until' => now()->subMinute()]);
    DB::table('request_orders')->where('id', $this->case)->update(['status' => 'QUOTED']);
    ($this->customer)($this->buyer);
    $accept = fn () => ($this->post)("$this->detail/quotations/$q/decisions", ['decision' => 'ACCEPT', 'address_id' => (string) Str::ulid()])->assertConflict()->assertJsonPath('code', 'QUOTE_EXPIRED');
    $accept();
    expect(QuotationLifecycle::expireDue())->toBe(1);
    expect(QuotationLifecycle::expireDue())->toBe(0);
    $accept();
    $this->assertDatabaseHas('request_orders', ['status' => 'EXPIRED']);
    $this->assertDatabaseEmpty('orders');
});

it('requires an active withdrawal reason and keeps unsent withdrawn drafts private', function () {
    $q = ($this->draft)();
    $withdraw = fn ($q, $reason) => ($this->post)("$this->quotes/$q/withdrawals", ['reason_code' => $reason]);
    $withdraw($q, 'NO')->assertUnprocessable();
    DB::table('reason_codes')->insert(['domain' => 'request_orders', 'code' => 'CHANGE', 'label' => 'Changed']);
    $withdraw($q, 'CHANGE')->assertOk();
    ($this->customer)($this->buyer);
    $this->getJson($this->detail)->assertOk()->assertJsonCount(0, 'data.quotations');
    ($this->operator)();
    $q = ($this->draft)();
    ($this->dispatch)($q);
    $withdraw($q, 'CHANGE')->assertOk();
    $this->assertDatabaseHas('request_orders', ['status' => 'UNDER_REVIEW']);
    $this->assertDatabaseHas('status_histories', ['to_status' => 'WITHDRAWN', 'reason_id' => DB::table('reason_codes')->value('id')]);
});

it('rolls back snapshots, decision note and events on invoice failure and allows the same intent to retry', function () {
    $number = ($this->draft)();
    ($this->dispatch)($number);
    $data = ['decision' => 'ACCEPT', 'address_id' => ($this->address)(), 'note' => 'Approved'];
    ($this->customer)($this->buyer);
    $url = "$this->detail/quotations/$number/decisions";
    DB::statement("ALTER TABLE invoices ADD CONSTRAINT simulate_quote_issue_failure CHECK(purpose != 'DEPOSIT')");
    try {
        ($this->post)($url, $data, 'retry')->assertServerError();
        foreach (['orders', 'order_items', 'order_addresses', 'invoices', 'request_order_messages'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertDatabaseHas('quotations', ['status' => 'SENT']);
        $this->assertDatabaseHas('request_orders', ['status' => 'QUOTED']);
        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'OrderCreated']);
    } finally {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT simulate_quote_issue_failure');
    }
    ($this->post)($url, $data, 'retry')->assertOk();
});

it('serializes two independent acceptances into one full invoice and one order', function () {
    $this->input = ['term' => 'FULL', 'deposit_amount' => null] + $this->input;
    $q = ($this->draft)();
    ($this->dispatch)($q);
    $owner = $this->buyer->id;
    $address = ($this->address)();
    $barrier = sys_get_temp_dir().'/accept-'.Str::uuid();
    mkdir($barrier, 0700);
    $worker = quotationAcceptanceWorker($barrier, $owner, $address, $q);
    try {
        $results = Concurrency::driver('process')->run([$worker, $worker]);
    } finally {
        foreach (glob($barrier.'/*') as $flag) {
            unlink($flag);
        }
        rmdir($barrier);
    }
    expect($results[0])->toBe($results[1]);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseHas('invoices', ['purpose' => 'FULL_PAYMENT', 'total_amount' => 1500]);
    expect(DB::table('outbox_events')->where('event_type', 'QuotationAccepted')->count())->toBe(1);
});

function quotationAcceptanceWorker(string $barrier, int $owner, string $address, string $q): Closure
{
    return static function () use ($barrier, $owner, $address, $q) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected test database.');
        }
        touch($barrier.'/'.getmypid());
        $limit = microtime(true) + 20;
        while (count(glob($barrier.'/*')) < 2) {
            if (microtime(true) > $limit) {
                throw new RuntimeException('Second buyer process missing.');
            }
            usleep(20000);
        }

        return DB::transaction(function () use ($owner, $address, $q) {
            $case = CaseData::locked('REQ-1', $owner);

            return QuotationLifecycle::decide($case, QuotationData::locked($case->id, $q), $owner, ['decision' => 'ACCEPT', 'address_id' => $address])['order_number'];
        });
    };
}
