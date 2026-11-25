<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Foundation\Files\ScanQueue;
use App\Modules\Foundation\Outbox\ConsumeEvent;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Invoicing\InvoiceIssuer;
use App\Modules\Invoicing\InvoiceLifecycle;
use App\Modules\Invoicing\IssuedNotice;
use App\Modules\Invoicing\RenderInvoicePdf;
use App\Support\Http\ApiProblem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);

beforeEach(function () {
    $this->prepareCheckout();
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$quote] = ($this->finalQuote)($this->body);
    $this->order = ($this->confirm)($quote)->assertCreated()->json('data.order_numbers.0');
    $this->invoice = DB::table('invoices')->firstOrFail();
    $this->url = '/api/v1/invoices/'.$this->invoice->number;
    $this->admin = '/api/v1/admin/invoices/'.$this->invoice->number;
    $this->staffLogin = function (bool $finance = true) {
        $this->seed(PermissionSeeder::class);
        config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
        $staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
        if ($finance) {
            $staff->assignRole('finance');
        }
        $this->actingAs($staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $staff->staff_session_version, 'staff.started' => now()->timestamp, 'staff.seen' => now()->timestamp]);

        return $staff;
    };
    $this->postAction = fn (string $url, array $body = [], ?string $key = null) => $this->postJson($url, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    DB::table('reason_codes')->insert(['domain' => 'invoicing', 'code' => 'FIX', 'label' => 'Correction']);
});

it('keeps invoice reads and private downloads scoped to the verified owner', function () {
    $this->getJson('/api/v1/invoices?order='.$this->order)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.items.0.description', 'Album')->assertJsonPath('data.pdf_ready', false);
    $this->getJson($this->url.'/pdf')->assertConflict()->assertJsonPath('code', 'FILE_NOT_READY');
    ($this->login)(User::factory()->create());
    $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson($this->url)->assertNotFound();
    $this->getJson($this->url.'/pdf')->assertNotFound();
});

it('protects issued header and lines even for direct database writes', function () {
    $item = (array) DB::table('invoice_items')->firstOrFail();
    unset($item['id']);
    expect(fn () => DB::table('invoices')->update(['due_at' => now()->addYear()]))->toThrow(QueryException::class);
    expect(fn () => DB::table('invoices')->update(['status' => 'DRAFT']))->toThrow(QueryException::class);
    expect(fn () => DB::table('invoice_items')->insert($item))->toThrow(QueryException::class);
    expect(fn () => DB::table('invoice_items')->update(['unit_amount' => 0]))->toThrow(QueryException::class);
    expect(fn () => DB::table('invoice_items')->delete())->toThrow(QueryException::class);
});

it('separates read, manage, download and per-command permissions including replay', function () {
    $staff = ($this->staffLogin)(false);
    $this->getJson($this->admin)->assertForbidden();
    $staff->givePermissionTo('invoicing.invoice.view', 'invoicing.invoice.manage');
    $this->getJson($this->admin)->assertOk();
    $this->getJson($this->admin.'/pdf')->assertForbidden();
    foreach (['voids', 'due-date-revisions', 'pdf-requests'] as $path) {
        ($this->postAction)($this->admin.'/'.$path)->assertForbidden();
    }
    ($this->postAction)("/api/v1/admin/orders/$this->order/invoices")->assertForbidden();
    $staff->givePermissionTo('invoicing.invoice.void');
    $void = fn () => ($this->postAction)($this->admin.'/voids', ['reason_code' => 'FIX'], 'void');
    $void()->assertOk()->assertJsonPath('data.status', 'VOID');
    $void()->assertOk();
    $staff->revokePermissionTo('invoicing.invoice.manage');
    $void()->assertForbidden();
    expect(DB::table('outbox_events')->where('event_type', 'InvoiceVoided')->count())->toBe(1);
});

it('rejects unknown reasons, payment review and applied allocations before voiding', function () {
    ($this->staffLogin)();
    $void = fn (string $code) => ($this->postAction)($this->admin.'/voids', ['reason_code' => $code]);
    $void('INVALID')->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    DB::table('order_transition_holds')->insert(['order_id' => $this->invoice->order_id, 'kind' => 'PROOF_REVIEW', 'source_ref' => 'proof']);
    $void('FIX')->assertConflict()->assertJsonPath('code', 'TRANSFER_CONFLICT');
    DB::table('order_transition_holds')->delete();
    // The payment module will own the permanent allocation table in TASK-022.
    DB::statement('CREATE TEMP TABLE payment_allocations(invoice_id bigint, applied_at timestamptz)');
    try {
        DB::table('payment_allocations')->insert(['invoice_id' => $this->invoice->id, 'applied_at' => now()]);
        $void('FIX')->assertConflict();
    } finally {
        DB::statement('DROP TABLE pg_temp.payment_allocations');
    }
    $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id, 'status' => 'ISSUED']);
});

it('keeps the original amount and lines when only one concurrent replacement can win', function () {
    $staff = ($this->staffLogin)();
    ($this->postAction)($this->admin.'/voids', ['reason_code' => 'FIX'])->assertOk();
    $body = ['purpose' => 'FULL_PAYMENT', 'replaces_invoice_number' => $this->invoice->number, 'due_at' => now()->addMinutes(1)->toIso8601String()];
    $url = "/api/v1/admin/orders/$this->order/invoices";
    ($this->postAction)($url, ['total_amount' => 1] + $body)->assertUnprocessable();
    ($this->postAction)($url, ['due_at' => now()->addYear()->toISOString()] + $body)->assertConflict();
    $barrier = sys_get_temp_dir().'/invoice-'.Str::uuid();
    mkdir($barrier, 0700);
    $worker = invoiceReplacementWorker($barrier, $this->order, $staff->id, $body);
    try {
        $results = Concurrency::driver('process')->run([$worker, $worker]);
    } finally {
        foreach (glob($barrier.'/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
    expect($results)->toContain('INVALID_STATE_TRANSITION');
    $replacement = DB::table('invoices')->where('replaces_invoice_id', $this->invoice->id)->sole();
    expect($results)->toContain($replacement->number);
    $this->getJson('/api/v1/admin/invoices/'.$replacement->number)->assertOk()->assertJsonPath('data.replaces_invoice_number', $this->invoice->number)
        ->assertJsonPath('data.total_amount', $this->invoice->total_amount)->assertJsonPath('data.items.0.qty', 2);
    $this->assertDatabaseCount('invoices', 2);
});

function invoiceReplacementWorker(string $barrier, string $order, int $actor, array $body): Closure
{
    return static function () use ($barrier, $order, $actor, $body) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected test database');
        }
        touch($barrier.'/'.getmypid());
        $end = microtime(true) + 15;
        while (count(glob($barrier.'/*')) < 2) {
            if (microtime(true) > $end) {
                throw new RuntimeException('Second issuer missing');
            }
            usleep(20000);
        }
        try {
            return DB::transaction(fn () => InvoiceLifecycle::apply('issue', $order, $actor, $body));
        } catch (ApiProblem $e) {
            return $e->errorCode;
        }
    };
}

it('reissues an overdue unpaid follow-up without reopening an expired initial order', function () {
    ($this->staffLogin)();
    $body = ['reason_code' => 'FIX', 'due_at' => now()->addDay()->toISOString()];
    ($this->postAction)($this->admin.'/due-date-revisions', $body)->assertConflict();
    DB::table('orders')->update(['status' => 'CONFIRMED']);
    DB::table('invoices')->update(['status' => 'PAID']);
    $issue = fn () => DB::transaction(fn () => app(InvoiceIssuer::class)->issue($this->invoice->order_id, $this->buyer->id, 'balance:received', 'BALANCE', now()->subDay()->toISOString(),
        ['subtotal_amount' => 100, 'shipping_amount' => 0, 'tax_amount' => 0, 'total_amount' => 100],
        [['order_item_id' => DB::table('order_items')->value('id'), 'line_type' => 'PRODUCT', 'description' => 'Album', 'qty' => 1, 'unit_amount' => 100, 'amount' => 100]]));
    $balance = $issue();
    expect($issue())->toBe($balance);
    $this->assertDatabaseCount('invoices', 2);
    $url = "/api/v1/admin/invoices/$balance/due-date-revisions";
    ($this->postAction)($url, ['due_at' => now()->subHour()->toISOString()] + $body)->assertConflict();
    $this->assertDatabaseHas('invoices', ['number' => $balance, 'status' => 'ISSUED']);
    $result = ($this->postAction)($url, $body)->assertOk()->assertJsonPath('data.purpose', 'BALANCE')->assertJsonPath('data.replaces_invoice_number', $balance)->assertJsonPath('data.items.0.amount', 100);
    $this->getJson('/api/v1/admin/invoices/'.$balance)->assertJsonPath('data.status', 'VOID')->assertJsonPath('data.replaced_by_invoice_number', $result->json('data.number'));
    $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id, 'status' => 'PAID']);
});

it('retries a failed PDF and signs only one private, downloadable copy', function () {
    Bus::fake();
    Notification::fake();
    $notice = new ConsumeEvent(DB::table('event_consumptions')->where('handler', 'invoicing.mail')->value('id'));
    $notice->handle();
    $notice->handle();
    Notification::assertSentToTimes($this->buyer, IssuedNotice::class, 1);
    $receipt = DB::table('event_consumptions')->where('handler', 'invoicing.pdf')->value('id');
    (new ConsumeEvent($receipt))->handle();
    $this->artisan('invoices:dispatch-pdfs')->expectsOutput('Invoice PDFs queued: 1')->assertSuccessful();
    $first = Bus::dispatched(RenderInvoicePdf::class)->sole();
    $first->failed(new RuntimeException('Storage unavailable'));
    ($this->staffLogin)();
    $retry = fn () => ($this->postAction)($this->admin.'/pdf-requests', [], 'retry');
    $retry()->assertOk();
    $retry()->assertOk();
    $this->artisan('invoices:dispatch-pdfs')->expectsOutput('Invoice PDFs queued: 1')->assertSuccessful();
    $this->artisan('invoices:dispatch-pdfs')->expectsOutput('Invoice PDFs queued: 0')->assertSuccessful();
    $job = Bus::dispatched(RenderInvoicePdf::class)->last();
    $first->handle();
    DB::beginTransaction();
    try {
        $job->handle();
        $original = DB::table('stored_files')->firstOrFail();
    } finally {
        DB::rollBack();
    }
    try {
        $private = Storage::disk('private');
        $isolated = Storage::fake('private');
        try {
            foreach ([$original->object_key, 'ready/orphan'] as $key) {
                $isolated->put($key, 'old bytes');
                touch($isolated->path($key), now()->subDays(2)->timestamp);
            }
            app(ScanQueue::class)->prune();
            $isolated->assertExists($original->object_key);
            $isolated->assertMissing('ready/orphan');
        } finally {
            Storage::set('private', $private);
        }
        $job->handle();
        $job->handle();
        $this->assertDatabaseCount('stored_files', 1);
        $this->assertDatabaseHas('stored_files', ['object_key' => $original->object_key, 'sha256' => $original->sha256]);
        config(['stored_files.signing_endpoint' => config('filesystems.disks.private.endpoint')]);
        $this->getJson($this->admin.'/pdf')->assertOk();
        $publicId = DB::table('stored_files')->value('public_id');
        $this->getJson("/api/v1/admin/files/$publicId/download")->assertNotFound();
        ($this->login)($this->buyer);
        $this->getJson("/api/v1/files/$publicId/download")->assertNotFound();
        $url = $this->getJson($this->url.'/pdf')->assertOk()->json('data.url');
        expect(Http::get($url)->body())->toStartWith('%PDF-');
        expect(Http::get(strtok($url, '?'))->status())->toBe(403);
        $this->assertDatabaseHas('stored_files', ['owner_type' => 'invoice', 'owner_id' => $this->invoice->id, 'status' => 'READY', 'visibility' => 'PRIVATE']);
    } finally {
        Storage::disk('private')->delete($original->object_key);
    }
});
