<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockExpiry;
use App\Modules\Inventory\StockReservations;
use App\Modules\Payments\BankAccounts;
use App\Modules\Payments\PaymentFlow;
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
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$quote] = ($this->finalQuote)($this->body);
    ($this->confirm)($quote)->assertCreated();
    $this->invoice = DB::table('invoices')->sole();
    $this->bank = DB::transaction(fn () => BankAccounts::save(null, ['code' => 'MAIN', 'bank_code' => 'BANK', 'account_number' => '123456789', 'holder_name' => 'Shop', 'active' => true], $this->buyer->id));
    $this->write = fn (string $url, array $body = [], ?string $key = null) => $this->postJson('/api/v1/'.$url, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $this->intent = ['invoice_numbers' => [$this->invoice->number], 'bank_account_code' => 'MAIN'];
    $this->open = fn () => ($this->write)('payments', $this->intent)->assertOk()->json('data.number');
    $this->file = function (string $payment, string $status = 'READY') {
        $file = (string) Str::ulid();
        DB::table('stored_files')->insert(['public_id' => $file, 'owner_type' => 'payment', 'owner_id' => DB::table('payments')->where('number', $payment)->value('id'),
            'collection' => 'payment_proof', 'uploaded_by' => $this->buyer->id, 'status' => $status, 'object_key' => 'proof/'.$file, 'mime' => 'image/png', 'size_bytes' => 100, 'sha256' => str_repeat('a', 64)]);

        return $file;
    };
    $this->finance = function () {
        $this->seed(PermissionSeeder::class);
        config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
        $staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
        $staff->assignRole('finance');
        $this->actingAs($staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $staff->staff_session_version, 'staff.started' => now()->timestamp, 'staff.seen' => now()->timestamp]);

        return $staff;
    };
});

it('freezes encrypted instructions, derives the amount and isolates intents and account details', function () {
    $this->getJson('/api/v1/bank-accounts')->assertOk()->assertJsonMissingPath('data.0.account_number');
    ($this->write)('payments', $this->intent + ['amount' => 1])->assertUnprocessable();
    $result = ($this->write)('payments', $this->intent, 'open')->assertOk()->assertJsonPath('data.amount', $this->invoice->total_amount)->assertJsonPath('data.account_number', '123456789');
    $number = $result->json('data.number');
    DB::transaction(fn () => BankAccounts::save($this->bank->id, ['account_number' => '987654321'], $this->buyer->id));
    expect(DB::table('bank_accounts')->value('account_ref_ciphertext'))->not->toContain('987654321');
    ($this->write)('payments', $this->intent, 'open')->assertOk()->assertJsonPath('data.number', $number);
    ($this->write)('payments', $this->intent)->assertConflict();
    $this->getJson('/api/v1/payments/'.$number)->assertJsonPath('data.account_number', '123456789');
    ($this->login)(User::factory()->create());
    $this->getJson('/api/v1/payments/'.$number)->assertNotFound();
    ($this->write)('payments', $this->intent)->assertNotFound();
    ($this->write)("payments/$number/cancellations")->assertNotFound();
});

it('requires ready bound evidence, extends only once and enforces a live reviewer lease', function () {
    $first = ($this->open)();
    $wrong = ($this->file)($first);
    ($this->write)("payments/$first/cancellations")->assertOk();
    $number = ($this->open)();
    $url = "payments/$number";
    ($this->write)("$url/proofs", ['file_id' => $wrong])->assertNotFound();
    $file = ($this->file)($number, 'QUARANTINED');
    ($this->write)("$url/proofs", ['file_id' => $file])->assertConflict();
    expect(DB::table('payment_hold_extensions')->exists())->toBeFalse();
    DB::table('stored_files')->where('public_id', $file)->update(['status' => 'READY']);
    ($this->write)("$url/proofs", ['file_id' => $file], 'submit')->assertOk()->assertJsonPath('data.status', 'WAITING_CONFIRMATION');
    ($this->write)("$url/proofs", ['file_id' => $file], 'submit')->assertOk();
    $this->assertDatabaseCount('payment_hold_extensions', 1);
    ($this->write)("$url/cancellations")->assertConflict();
    ($this->finance)();
    $this->getJson("/api/v1/admin/$url")->assertOk()->assertJsonMissingPath('data.account_number');
    ($this->write)("admin/$url/review-claims", ['action' => 'CLAIM'])->assertOk();
    ($this->finance)();
    ($this->write)("admin/$url/review-claims", ['action' => 'CLAIM'])->assertConflict();
    DB::table('payments')->where('number', $number)->update(['claim_until' => now()->subSecond()]);
    ($this->write)("admin/$url/review-claims", ['action' => 'CLAIM'])->assertOk();
    DB::table('reason_codes')->insert(['code' => 'WRONG', 'label' => 'Wrong reference', 'domain' => 'payments']);
    ($this->write)("admin/$url/decisions", ['decision' => 'REJECT', 'reason_code' => 'WRONG'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    expect(DB::table('order_transition_holds')->exists())->toBeFalse();
    $this->assertDatabaseHas('invoices', ['status' => 'ISSUED']);
});

it('serializes competing intents against one invoice in PostgreSQL', function () {
    $id = $this->buyer->id;
    $number = $this->invoice->number;
    $worker = competingIntentWorker($id, $number);
    $results = Concurrency::driver('process')->run([$worker, $worker]);
    expect($results)->toContain(409)->toContain(DB::table('payments')->sole()->number);
});

function competingIntentWorker(int $id, string $number): Closure
{
    return static function () use ($id, $number) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected test database');
        }
        try {
            return DB::transaction(fn () => PaymentFlow::create($id, [$number], 'MAIN'));
        } catch (ApiProblem $e) {
            return $e->getStatusCode();
        }
    };
}

it('returns a mismatched receipt only with checker approval and private READY transfer evidence', function () {
    $number = ($this->open)();
    $maker = ($this->finance)();
    $url = "admin/payments/$number/reconciliations";
    $input = ['bank_tx_ref' => 'EXTRA', 'received_amount' => 1, 'reason_code' => 'WRONG'];
    DB::table('reason_codes')->insert(['code' => 'WRONG', 'label' => 'Wrong amount', 'domain' => 'payments']);
    ($this->write)($url, $input)->assertForbidden()->assertJsonPath('code', 'STEP_UP_REQUIRED');
    $this->withSession(['staff.step_up' => now()->timestamp]);
    $id = ($this->write)($url, $input)->assertOk()->json('data.id');
    ($this->finance)();
    $this->withSession(['staff.step_up' => now()->timestamp]);
    $case = "admin/payment-reconciliations/$id";
    ($this->write)("$case/reviews", ['decision' => 'APPROVE', 'note' => 'Receipt verified'])->assertOk();
    $this->actingAs($maker)->withSession(['staff.version' => $maker->staff_session_version]);
    ($this->write)("$case/decisions", ['decision' => 'APPLY', 'note' => 'Mismatch'])->assertConflict();
    $file = (string) Str::ulid();
    DB::table('stored_files')->insert(['public_id' => $file, 'owner_type' => 'payment_reconciliation', 'owner_id' => $id, 'collection' => 'reconciliation_proof', 'uploaded_by' => $maker->id, 'status' => 'QUARANTINED', 'object_key' => 'returns/'.$file, 'mime' => 'image/png', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64)]);
    $body = ['decision' => 'RETURN', 'note' => 'Returned', 'refund_transfer_ref' => 'RET1', 'proof_file_id' => $file];
    ($this->write)("$case/decisions", $body)->assertConflict();
    DB::table('stored_files')->where('public_id', $file)->update(['status' => 'READY']);
    ($this->write)("$case/decisions", $body, 'return')->assertOk()->assertJsonPath('data.status', 'RETURNED');
    ($this->write)("$case/decisions", $body, 'return')->assertOk();
    $this->assertDatabaseHas('invoices', ['status' => 'ISSUED']);
    expect(DB::table('payment_allocations')->whereNotNull('applied_at')->count())->toBe(0);
});

it('posts an exact bank receipt once and never deducts physical stock on capture', function () {
    $number = ($this->open)();
    ($this->write)("payments/$number/proofs", ['file_id' => ($this->file)($number)])->assertOk();
    ($this->finance)();
    ($this->write)("admin/payments/$number/review-claims", ['action' => 'CLAIM'])->assertOk();
    $url = "admin/payments/$number/decisions";
    $body = ['decision' => 'APPROVE', 'bank_tx_ref' => 'bank-ref', 'received_amount' => $this->invoice->total_amount];
    ($this->write)($url, ['received_amount' => 1] + $body)->assertConflict();
    $before = DB::table('inventory_levels')->sum('on_hand');
    ($this->write)($url, $body, 'capture')->assertOk()->assertJsonPath('data.status', 'PAID');
    ($this->write)($url, $body, 'capture')->assertOk();
    $this->assertDatabaseHas('orders', ['status' => 'CONFIRMED', 'payment_status' => 'PAID']);
    $this->assertDatabaseHas('invoices', ['status' => 'PAID']);
    expect(DB::table('payment_allocations')->whereNotNull('applied_at')->count())->toBe(1)
        ->and(DB::table('inventory_levels')->sum('on_hand'))->toBe($before);
    $this->assertDatabaseHas('stock_reservations', ['status' => 'COMMITTED']);
});

it('requires a separate checker before recovering a late exact receipt', function () {
    $number = ($this->open)();
    DB::table('payments')->update(['expires_at' => now()->subHour()]);
    PaymentFlow::expireDue();
    DB::table('stock_expiry_work')->update(['due_at' => now()->subHour()]);
    app(StockExpiry::class)->run(app(StockReservations::class));
    $this->assertDatabaseHas('orders', ['status' => 'EXPIRED']);
    $maker = ($this->finance)();
    $this->withSession(['staff.step_up' => now()->timestamp]);
    DB::table('reason_codes')->insert(['code' => 'LATE', 'label' => 'Late', 'domain' => 'payments']);
    $input = ['bank_tx_ref' => 'LATE-1', 'received_amount' => $this->invoice->total_amount, 'reason_code' => 'LATE'];
    $id = ($this->write)("admin/payments/$number/reconciliations", $input)->assertOk()->json('data.id');
    $url = "admin/payment-reconciliations/$id";
    $review = ['decision' => 'APPROVE', 'note' => 'Matched bank'];
    $apply = ['decision' => 'APPLY', 'note' => 'Recover'];
    ($this->write)("$url/reviews", $review)->assertForbidden();
    ($this->finance)();
    $this->withSession(['staff.step_up' => now()->timestamp]);
    ($this->write)("$url/reviews", $review)->assertOk();
    ($this->write)("$url/decisions", $apply)->assertForbidden();
    $this->actingAs($maker)->withSession(['staff.version' => $maker->staff_session_version]);
    ($this->write)("$url/decisions", $apply)->assertOk()->assertJsonPath('data.status', 'APPLIED');
    ($this->write)("admin/payments/$number/reconciliations", $input)->assertConflict();
    $this->assertDatabaseHas('payments', ['number' => $number, 'status' => 'PAID', 'reconciliation_id' => $id]);
});

it('holds refund caps, separates approval from transfer and replays a confirmed refund once', function () {
    $payment = ($this->open)();
    ($this->write)("payments/$payment/proofs", ['file_id' => ($this->file)($payment)])->assertOk();
    ($this->finance)();
    ($this->write)("admin/payments/$payment/review-claims", ['action' => 'CLAIM'])->assertOk();
    $amount = $this->invoice->total_amount;
    ($this->write)("admin/payments/$payment/decisions", ['decision' => 'APPROVE', 'received_amount' => $amount, 'bank_tx_ref' => 'RFD-IN'])->assertOk();
    DB::table('reason_codes')->insert(['domain' => 'refunds', 'code' => 'CANCEL', 'label' => 'Cancel order']);
    ($this->login)($this->buyer);
    $request = ['order_number' => DB::table('orders')->sole()->number, 'kind' => 'FULL', 'reason_code' => 'CANCEL', 'requested_amount' => $amount, 'cancel_order' => true];
    $refund = ($this->write)('refunds', $request, 'refund')->assertCreated()->json('data.number');
    ($this->write)('refunds', $request, 'refund')->assertCreated()->assertJsonPath('data.number', $refund);
    $other = ($this->write)('refunds', $request)->assertCreated()->json('data.number');
    $case = "admin/refunds/$refund";
    ($this->finance)();
    ($this->write)("$case/reviews", ['decision' => 'START', 'note' => 'Review'])->assertForbidden()->assertJsonPath('code', 'STEP_UP_REQUIRED');
    $this->withSession(['staff.step_up' => now()->timestamp]);
    foreach ([$refund, $other] as $number) {
        ($this->write)("admin/refunds/$number/reviews", ['decision' => 'START', 'note' => 'Review'])->assertOk();
    }
    $approve = ['decision' => 'APPROVE', 'approved_amount' => $amount, 'note' => 'Matched'];
    ($this->write)("$case/reviews", $approve)->assertOk();
    ($this->write)("admin/refunds/$other/reviews", $approve)->assertConflict();
    $start = ['action' => 'START', 'bank_checked' => true];
    ($this->write)("$case/execution-attempts", $start)->assertForbidden();
    $executor = ($this->finance)();
    ($this->write)("$case/execution-attempts", $start)->assertOk();
    ($this->write)("$case/execution-attempts", ['action' => 'FAIL', 'reason_code' => 'CANCEL'])->assertOk();
    ($this->write)("admin/refunds/$other/reviews", $approve)->assertConflict();
    ($this->write)("$case/execution-attempts", ['action' => 'RETRY', 'bank_checked' => true])->assertOk();
    $proof = (string) Str::ulid();
    DB::table('stored_files')->insert(['public_id' => $proof, 'owner_type' => 'refund', 'owner_id' => DB::table('refunds')->where('number', $refund)->value('id'),
        'collection' => 'refund_transfer_proof', 'uploaded_by' => $executor->id, 'status' => 'QUARANTINED', 'object_key' => 'refund/'.$proof, 'mime' => 'image/png', 'size_bytes' => 10, 'sha256' => str_repeat('c', 64)]);
    $transfer = ['transfer_ref' => 'bank-out', 'proof_file_id' => $proof, 'transferred_at' => now()->subSecond()->toISOString(),
        'allocations' => [['payment_number' => $payment, 'invoice_number' => $this->invoice->number, 'amount' => $amount]]];
    ($this->write)("$case/transfers", $transfer)->assertConflict();
    DB::table('stored_files')->where('public_id', $proof)->update(['status' => 'READY']);
    ($this->write)("$case/transfers", $transfer, 'out')->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    ($this->write)("$case/transfers", $transfer, 'out')->assertOk();
    ($this->write)("$case/transfers", $transfer)->assertConflict();
    $this->assertDatabaseCount('refund_transactions', 1);
    $this->assertDatabaseHas('payments', ['number' => $payment, 'status' => 'REFUNDED']);
    $this->assertDatabaseHas('orders', ['status' => 'CANCELLED', 'payment_status' => 'REFUNDED']);
    $this->assertDatabaseHas('stock_reservations', ['status' => 'RELEASED']);
    $date = now('Asia/Jakarta')->toDateString();
    $report = "/api/v1/admin/transaction-reports?from=$date&to=$date";
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.net_amount', 0)->assertJsonPath('data.paid_amount', $amount)->assertJsonPath('data.refund_amount', $amount);
    $this->getJson($report)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.bank_tx_ref');
    $this->getJson($report.'&method=preorder')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/admin/dashboard?from=2026-01-01&to=2026-06-30')->assertUnprocessable();
    $executor->syncRoles(['warehouse']);
    $this->getJson('/api/v1/admin/dashboard')->assertOk();
    $this->getJson($report)->assertForbidden();
});

it('isolates store settings and audit access, and refuses stale configuration updates', function () {
    $staff = ($this->finance)();
    $base = '/api/v1/admin/settings';
    $url = $base.'/returns.window_hours';
    $this->getJson($base)->assertForbidden();
    $staff->syncRoles(['admin']);
    $r = $this->getJson($url)->assertOk()->json('data');
    $write = fn (int $value) => $this->putJson($url, ['value' => $value, 'revision' => $r['revision']], ['Idempotency-Key' => (string) Str::uuid()]);
    $write(999)->assertUnprocessable();
    $write(96)->assertOk()->assertJsonPath('data.value', 96);
    $write(72)->assertConflict();
    $this->getJson('/api/v1/admin/audit-logs?action=SettingChanged&per_page=1')->assertOk()->assertJsonPath('data.0.actor_id', $staff->id)->assertJsonMissingPath('data.0.ip');
    $this->assertDatabaseHas('audit_logs', ['action' => 'AuditLogViewed', 'actor_id' => $staff->id]);
    $this->getJson('/api/v1/admin/audit-logs?cursor=invalid')->assertUnprocessable();
    $staff->syncRoles(['warehouse']);
    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    $this->getJson($url)->assertForbidden();
});

it('serializes cancellation-bearing bank attempts before an executor sends funds', function () {
    $o = DB::table('orders')->sole();
    DB::table('orders')->where('id', $o->id)->update(['status' => 'CONFIRMED']);
    $reason = DB::table('reason_codes')->insertGetId(['domain' => 'refunds', 'code' => 'CANCEL', 'label' => 'Cancel order']);
    $reviewer = ($this->finance)();
    ($this->finance)();
    $this->withSession(['staff.step_up' => now()->timestamp]);
    foreach (['FIRST', 'SECOND'] as $number) {
        DB::table('refunds')->insert(['number' => $number, 'order_id' => $o->id, 'requested_by' => $this->buyer->id, 'reason_id' => $reason, 'kind' => 'PARTIAL',
            'requested_amount' => 10, 'approved_amount' => 10, 'cancel_order' => true, 'status' => 'APPROVED', 'reviewed_by' => $reviewer->id]);
    }
    $start = ['action' => 'START', 'bank_checked' => true];
    ($this->write)('admin/refunds/FIRST/execution-attempts', $start)->assertOk();
    ($this->write)('admin/refunds/SECOND/execution-attempts', $start)->assertConflict();
    ($this->write)('admin/refunds/FIRST/execution-attempts', ['action' => 'FAIL', 'reason_code' => 'CANCEL'])->assertOk();
    ($this->write)('admin/refunds/SECOND/execution-attempts', $start)->assertConflict();
    ($this->login)($this->buyer);
    ($this->write)('payments', $this->intent)->assertConflict()->assertJsonPath('code', 'INVOICE_NOT_PAYABLE');
    $this->assertDatabaseCount('payments', 0);
});

it('uses the review timeout without shortening the original order and stock deadlines', function () {
    DB::table('settings')->where('key', 'payment.verification_hold_hours')->update(['value' => 1]);
    $original = now()->addHours(3)->toISOString();
    DB::table('orders')->update(['payment_due_at' => $original]);
    DB::table('stock_expiry_work')->update(['due_at' => $original]);
    DB::table('stock_reservations')->update(['expires_at' => $original]);
    $number = ($this->open)();
    ($this->write)("payments/$number/proofs", ['file_id' => ($this->file)($number)])->assertOk();
    expect(strtotime(DB::table('payment_hold_extensions')->value('expires_at')))->toBe(strtotime($original))
        ->and(strtotime(DB::table('stock_expiry_work')->value('due_at')))->toBe(strtotime($original))
        ->and(strtotime(DB::table('payments')->value('expires_at')))->toBeLessThan(strtotime($original));
});
