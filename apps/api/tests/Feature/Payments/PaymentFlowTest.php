<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
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
