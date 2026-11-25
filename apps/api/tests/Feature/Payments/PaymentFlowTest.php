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
