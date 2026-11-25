<?php

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\RequestOrders\CaseData;
use App\Modules\RequestOrders\RequestCases;
use App\Support\Http\ApiProblem;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->buyer = User::factory()->create();
    $this->staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->staff->assignRole('order_staff');
    $this->customer = fn (User $u) => $this->actingAs($u)->withHeader('Origin', 'http://localhost')->withSession([DeviceSessions::SURFACE_KEY => 'CUSTOMER']);
    $this->operator = fn () => $this->actingAs($this->staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF',
        'staff.version' => $this->staff->staff_session_version, 'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);
    $this->postCase = fn ($url, $data, $key = null) => $this->postJson($url, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $this->api = '/api/v1/request-orders';
    $this->admin = '/api/v1/admin/request-orders';
    $this->input = ['title' => 'Album', 'description' => 'Versi Jepang', 'budget_amount' => 450000, 'target_date' => '2027-05-10',
        'items' => [['name' => 'Album A', 'qty' => 2, 'source_url' => 'https://example.org/album', 'options' => 'Biru', 'notes' => 'Segel']]];
    ($this->customer)($this->buyer);
    $this->submitCase = fn () => ($this->postCase)($this->api, $this->input, 'submit')->assertCreated()->json('data.number');
    DB::table('reason_codes')->insert(['domain' => 'request_orders', 'code' => 'CHANGE', 'label' => 'Berubah']);
});

it('keeps customer discussion separate from staff notes on reads, review and replay', function () {
    $number = ($this->submitCase)();
    expect(($this->submitCase)())->toBe($number);
    $url = "$this->api/$number";
    $staff = "$this->admin/$number";
    $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.qty', 2)->assertJsonMissingPath('data.assigned_to');
    $this->getJson($this->api)->assertOk()->assertJsonMissingPath('data.0.description');
    ($this->postCase)($url.'/messages', ['body' => 'Ada versi lain?'])->assertOk()->assertJsonPath('data.author_label', 'Pelanggan');
    ($this->operator)();
    ($this->postCase)($staff.'/reviews', ['decision' => 'START', 'note' => 'Catatan pembelian internal'], 'start')->assertOk()->assertJsonPath('data.assigned_to', $this->staff->id);
    ($this->postCase)($staff.'/reviews', ['decision' => 'START', 'note' => 'Catatan pembelian internal'], 'start')->assertOk();
    ($this->postCase)($staff.'/messages', ['visibility' => 'CUSTOMER', 'body' => 'Ada versi biru.'])->assertOk();
    ($this->postCase)($staff.'/messages', ['visibility' => 'STAFF', 'body' => 'Catatan tim'])->assertOk();
    ($this->postCase)($staff.'/reviews', ['decision' => 'NEED_INFORMATION'])->assertOk();
    $this->getJson($this->admin.'?status=NEED_INFORMATION')->assertOk()->assertJsonCount(4, 'data.0.messages');
    ($this->customer)($this->buyer);
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonMissing(['visibility' => 'STAFF'])->assertJsonPath('data.messages.1.body', 'Ada versi biru.');
    ($this->postCase)($url.'/cancellations', ['reason_code' => 'CHANGE'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    ($this->postCase)($url.'/messages', ['body' => 'Late'])->assertConflict();
    $this->assertDatabaseCount('request_orders', 1);
    $this->assertDatabaseCount('request_order_items', 1);
    $this->assertDatabaseCount('request_order_messages', 4);
    $this->assertDatabaseCount('status_histories', 4);
    expect(DB::table('outbox_events')->where('event_type', 'RequestOrderSubmitted')->count())->toBe(1);
    expect(DB::table('outbox_events')->whereRaw("payload::text LIKE '%Catatan%'")->exists())->toBeFalse();
});

it('scopes reads, writes and private uploads to their case owner and verified surface', function () {
    Storage::fake('private');
    $n = ($this->submitCase)();
    $url = "$this->api/$n";
    $upload = fn () => $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])->post('/api/v1/files', [
        'owner_type' => 'request_order', 'owner_ref' => $n, 'collection' => 'request_attachment',
        'file' => UploadedFile::fake()->createWithContent('album.pdf', "%PDF-1.4\n%%EOF"),
    ]);
    $file = $upload()->assertAccepted()->json('data.id');
    $this->getJson($url)->assertOk()->assertJsonPath('data.files.0.id', $file)->assertJsonMissingPath('data.files.0.object_key');
    $this->getJson("/api/v1/files/$file/download")->assertConflict();
    ($this->customer)(User::factory()->create());
    $this->getJson($this->api)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url)->assertNotFound();
    ($this->postCase)($url.'/messages', ['body' => 'Hello'])->assertNotFound();
    ($this->postCase)($url.'/cancellations', ['reason_code' => 'CHANGE'])->assertNotFound();
    $this->getJson("/api/v1/files/$file")->assertNotFound();
    $upload()->assertNotFound();
    ($this->operator)();
    $this->getJson("/api/v1/admin/files/$file")->assertOk();
    $this->getJson($url)->assertForbidden();
    $this->staff->syncRoles([])->givePermissionTo('foundation.file.view');
    $this->getJson("/api/v1/admin/files/$file")->assertNotFound();
    $this->staff->syncPermissions(['request_orders.request.view']);
    $this->getJson("$this->admin/$n")->assertOk()->assertJsonCount(0, 'data.files');
    $this->getJson($this->admin)->assertOk()->assertJsonCount(0, 'data.0.files');
    ($this->customer)($this->buyer);
    ($this->postCase)($url.'/cancellations', ['reason_code' => 'CHANGE'])->assertOk();
    $upload()->assertNotFound();
    ($this->customer)(User::factory()->unverified()->create());
    ($this->postCase)($this->api, $this->input)->assertForbidden();
});

it('enforces individual review, assignment and visibility grants including cached mutations', function () {
    $n = ($this->submitCase)();
    $url = "$this->admin/$n";
    ($this->operator)();
    $this->staff->syncRoles([]);
    $this->getJson($url)->assertForbidden();
    $this->staff->givePermissionTo('request_orders.request.view');
    $this->getJson($url)->assertOk();
    $start = ['decision' => 'START'];
    ($this->postCase)($url.'/reviews', $start)->assertForbidden();
    $this->staff->givePermissionTo('request_orders.request.review', 'request_orders.request.start', 'request_orders.request.reply');
    ($this->postCase)($url.'/reviews', $start + ['assignee_id' => $this->staff->id])->assertForbidden();
    ($this->postCase)($url.'/reviews', $start + ['note' => 'Journal'])->assertForbidden();
    ($this->postCase)($url.'/reviews', $start, 'start')->assertOk();
    $this->staff->revokePermissionTo('request_orders.request.start');
    ($this->postCase)($url.'/reviews', $start, 'start')->assertForbidden();
    ($this->postCase)($url.'/messages', ['body' => 'Internal', 'visibility' => 'STAFF'])->assertForbidden();
    ($this->postCase)($url.'/messages', ['body' => 'Public', 'visibility' => 'CUSTOMER'])->assertOk();
    $this->assertDatabaseCount('request_order_messages', 1);
});

it('validates item input, assignment and review edges without partial writes', function () {
    foreach ([['qty' => 0], ['source_url' => 'ftp://example.org/a'], ['price' => 20], ['notes' => "invalid\0text"]] as $invalid) {
        $data = $this->input;
        $data['items'][] = $invalid + $data['items'][0];
        ($this->postCase)($this->api, $data)->assertUnprocessable();
    }
    $this->assertDatabaseEmpty('request_orders');
    $n = ($this->submitCase)();
    ($this->postCase)("$this->api/$n/messages", ['body' => 'Text', 'visibility' => 'STAFF'])->assertUnprocessable();
    ($this->operator)();
    $url = "$this->admin/$n/reviews";
    ($this->postCase)($url, ['decision' => 'NEED_INFORMATION'])->assertConflict();
    ($this->postCase)($url, ['decision' => 'START', 'assignee_id' => $this->buyer->id])->assertUnprocessable();
    $inactive = User::factory()->staff()->create(['disabled_at' => now(), 'two_factor_confirmed_at' => now()]);
    ($this->postCase)($url, ['decision' => 'START', 'assignee_id' => $inactive->id])->assertUnprocessable();
    ($this->postCase)($url, ['decision' => 'REJECT', 'reason_code' => 'MISSING', 'note' => 'N'])->assertUnprocessable();
    $this->assertDatabaseEmpty('request_order_messages');
    ($this->postCase)($url, ['decision' => 'REJECT', 'reason_code' => 'CHANGE'])->assertOk();
    ($this->postCase)($url, ['decision' => 'REOPEN'])->assertConflict();
    $this->getJson($this->admin.'?from=2027-05-10&to=2027-05-01')->assertUnprocessable();
});

it('preserves operational history and accepts an empty rollback and reapply', function () {
    $migration = require app_path('Modules/RequestOrders/Database/Migrations/2026_11_25_075730_create_request_case_threads.php');
    DB::transaction(function () use ($migration) {
        $lines = require app_path('Modules/Orders/Database/Migrations/2026_11_25_085108_link_order_items_to_quotations.php');
        $quotes = require app_path('Modules/RequestOrders/Database/Migrations/2026_11_25_085107_create_quotations.php');
        $lines->down();
        $quotes->down();
        $migration->down();
        $migration->up();
        $quotes->up();
        $lines->up();
    });
    $number = ($this->submitCase)();
    $url = "$this->api/$number/messages";
    foreach ([1, 2] as $_) {
        ($this->postCase)($url, ['body' => 'Repeat'], 'reply')->assertOk();
    }
    expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class, 'Cannot discard recorded');
    expect(fn () => DB::table('request_order_messages')->update(['body' => 'Changed']))->toThrow(QueryException::class);
    expect(fn () => DB::table('request_order_messages')->delete())->toThrow(QueryException::class);
    $this->assertDatabaseCount('request_order_messages', 1);
    $this->assertDatabaseHas('request_order_messages', ['body' => 'Repeat']);
    expect(DB::table('outbox_events')->where('event_type', 'RequestMessageAdded')->count())->toBe(1);
    foreach ([1, 2] as $_) {
        ($this->postCase)("$this->api/$number/cancellations", ['reason_code' => 'CHANGE'], 'cancel')->assertOk();
    }
    $this->assertDatabaseCount('status_histories', 2);
});

it('limits customer submissions per account without blocking discussion, cancellation or other buyers', function () {
    $number = ($this->submitCase)();
    for ($i = 1; $i < 5; $i++) {
        ($this->submitCase)();
    }
    ($this->postCase)($this->api, $this->input, 'extra')->assertTooManyRequests()->assertJsonPath('code', 'THROTTLED')->assertHeader('Retry-After');
    $this->getJson($this->api)->assertOk();
    $this->assertDatabaseCount('request_orders', 1);
    ($this->postCase)("$this->api/$number/messages", ['body' => 'Hello'])->assertOk();
    ($this->postCase)("$this->api/$number/cancellations", ['reason_code' => 'CHANGE'])->assertOk();
    ($this->customer)(User::factory()->create());
    ($this->submitCase)();
    $this->assertDatabaseCount('request_orders', 2);
});

function requestReviewWorker(string $number, int $actor, string $barrier): Closure
{
    return static function () use ($number, $actor, $barrier) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected disposable test database.');
        }
        touch($barrier.'/'.getmypid());
        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/*')) < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Review workers did not reach the barrier.');
            }
            usleep(20000);
        }
        try {
            DB::transaction(fn () => RequestCases::review(CaseData::locked($number, null), $actor, ['decision' => 'START']));

            return $actor;
        } catch (ApiProblem $e) {
            return $e->errorCode;
        }
    };
}

it('assigns only to provisioned reviewers without requiring the target to have a live session', function () {
    $number = ($this->submitCase)();
    ($this->operator)();
    $url = "$this->admin/$number/reviews";
    $target = User::factory()->staff()->create(['two_factor_confirmed_at' => now(), 'staff_session_version' => 23]);
    $start = ['decision' => 'START', 'assignee_id' => $target->id];
    ($this->postCase)($url, $start)->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    $target->givePermissionTo('request_orders.request.view');
    ($this->postCase)($url, $start)->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    $this->assertDatabaseHas('request_orders', ['number' => $number, 'status' => 'SUBMITTED', 'assigned_to' => null]);
    $target->assignRole('order_staff');
    ($this->postCase)($url, $start)->assertOk()->assertJsonPath('data.assigned_to', $target->id);
    $super = User::factory()->staff()->create(['two_factor_confirmed_at' => now()]);
    $super->assignRole('super_admin');
    ($this->postCase)($url, ['decision' => 'NEED_INFORMATION', 'assignee_id' => $super->id])->assertOk()->assertJsonPath('data.assigned_to', $super->id);
});

it('lets only one staff member claim a submitted case', function () {
    $number = ($this->submitCase)();
    $other = User::factory()->staff()->create(['two_factor_confirmed_at' => now()]);
    $barrier = sys_get_temp_dir().'/request-review-'.Str::uuid();
    mkdir($barrier, 0700);
    try {
        $outcomes = Concurrency::driver('process')->run([
            requestReviewWorker($number, $this->staff->id, $barrier), requestReviewWorker($number, $other->id, $barrier),
        ]);
    } finally {
        foreach (glob($barrier.'/*') as $flag) {
            unlink($flag);
        }
        rmdir($barrier);
    }
    expect($outcomes)->toContain('INVALID_STATE_TRANSITION', DB::table('request_orders')->where('number', $number)->value('assigned_to'));
    $this->assertDatabaseCount('status_histories', 2);
    expect(DB::table('outbox_events')->where('event_type', 'RequestOrderReviewed')->count())->toBe(1);
});
