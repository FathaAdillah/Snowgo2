<?php

use App\Models\User;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Foundation\Idempotency\RequestFingerprint;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->actingAs($this->actor);
    $this->withHeader('Idempotency-Key', 'operation-1');
    Route::post('api/v1/fixture/{number}', function (Request $request, IdempotentMutation $guard, string $number) {
        return $guard->run($request, function () use ($request) {
            if (config('fixture.deny')) {
                throw new AuthorizationException;
            }

            return 'user:'.$request->user()->id;
        }, function () use ($request, $number) {
            $request->user()->update(['name' => 'Changed']);
            $id = app(Outbox::class)->record('NameChanged', 'user', $request->user()->id, []);
            if ($status = config('fixture.error')) {
                return ApiResponse::error('FIXTURE_ERROR', 'No change', $status);
            }

            return ApiResponse::success(['event' => $id, 'number' => $number], status: 201);
        });
    });
});

it('replays the encrypted result only for the same authorized scope and canonical request', function () {
    $url = '/api/v1/fixture/1?x=1&y=2';
    $first = $this->postJson($url, ['a' => 1, 'b' => 2])->assertCreated()->json('data');
    $this->postJson('/api/v1/fixture/1?y=2&x=1', ['b' => 2, 'a' => 1])->assertCreated()->assertJsonPath('data', $first);
    $this->assertDatabaseCount('outbox_events', 1);
    $row = DB::table('idempotency_keys')->first();
    expect($row->response_ciphertext)->not->toContain('"number"')
        ->and(now()->addHours(47)->lt($row->expires_at))->toBeTrue();
    foreach (['/api/v1/fixture/2?x=1&y=2', '/api/v1/fixture/1?x=2&y=2'] as $other) {
        $this->postJson($other, ['a' => 1, 'b' => 2])->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }
    $this->postJson($url, ['a' => 2])->assertUnprocessable();
    config(['fixture.deny' => true]);
    $this->postJson($url, ['a' => 1, 'b' => 2])->assertForbidden();
    config(['fixture.deny' => false]);
    $this->actingAs(User::factory()->create())->postJson($url, ['a' => 2])->assertCreated();
    $this->assertDatabaseCount('outbox_events', 2);
});

it('rejects invalid keys before performing work', function (string $key) {
    $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/fixture/1')->assertUnprocessable();
    $this->assertDatabaseEmpty('idempotency_keys');
})->with(['', 'has space', str_repeat('a', 129)]);

it('rolls back partial error effects and retries transient but not terminal outcomes', function (int $status) {
    config(['fixture.error' => $status]);
    $this->postJson('/api/v1/fixture/1')->assertStatus($status);
    $this->assertDatabaseEmpty('outbox_events');
    expect($this->actor->fresh()->name)->not->toBe('Changed');
    config(['fixture.error' => false]);
    $this->postJson('/api/v1/fixture/1')->assertStatus($status === 422 ? 422 : 201);
})->with([422, 503, 429]);

it('does not take a live claim or a locked stale claim; recovers it after the lock is released', function () {
    $url = '/api/v1/fixture/1';
    $this->postJson($url)->assertCreated();
    $claim = DB::table('idempotency_keys');
    $claim->update(['status' => 'PROCESSING', 'lease_until' => now()->addMinute(), 'lease_token' => fake()->uuid(),
        'response_ciphertext' => null, 'http_status' => null]);
    $this->postJson($url)->assertConflict()->assertJsonPath('code', 'REQUEST_IN_PROGRESS');
    $claim->update(['lease_until' => now()->subSecond()]);
    config(['database.connections.other' => config('database.connections.pgsql')]);
    $other = DB::connection('other');
    $other->beginTransaction();
    try {
        $other->table('idempotency_keys')->lockForUpdate()->first();
        DB::statement("SET statement_timeout = '2s'");
        $this->postJson($url)->assertConflict();
        $this->postJson($url, ['changed' => true])->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    } finally {
        $other->rollBack();
        DB::statement('RESET statement_timeout');
    }
    $this->postJson($url)->assertCreated();
    $this->assertDatabaseCount('idempotency_keys', 1);
});

it('allows a new mutation after the replay window expires', function () {
    $this->postJson('/api/v1/fixture/1')->assertCreated();
    $this->travel(49)->hours();
    $this->postJson('/api/v1/fixture/1', ['changed' => true])->assertCreated();
    $this->assertDatabaseCount('outbox_events', 2);
    $this->assertDatabaseCount('idempotency_keys', 1);
});

it('fingerprints file contents and distinguishes JSON arrays from objects', function () {
    $fileHash = fn ($body) => RequestFingerprint::hash(Request::create('/upload', 'POST', files: [
        'file' => UploadedFile::fake()->createWithContent('proof.txt', $body),
    ]));
    $jsonHash = fn ($body) => RequestFingerprint::hash(Request::create('/post', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body));
    expect($fileHash('one'))->not->toBe($fileHash('two'))
        ->and($jsonHash('{}'))->not->toBe($jsonHash('[]'))
        ->and($jsonHash('[1,2]'))->not->toBe($jsonHash('[2,1]'));
});
