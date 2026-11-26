<?php

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['sanctum.stateful' => ['admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->seed(PermissionSeeder::class);
    $this->actor = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->actingAs($this->actor)->withHeader('Origin', 'http://admin.localhost')->withSession([
        DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $this->actor->staff_session_version,
        'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp,
    ]);
    $this->api = fn ($method, $path, $body = [], $key = null) => $this->json($method, '/api/v1/admin/'.$path, $body,
        ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
});

it('keeps drafts and scheduled changes private and archives the effective page', function () {
    $api = $this->api;
    $body = ['slug' => 'faq', 'title' => 'FAQ', 'type' => 'FAQ', 'body' => '<strong>Toko</strong><button>Pesan</button>'];
    $api('POST', 'content', $body)->assertForbidden();
    $this->actor->assignRole('content_manager');
    $id = $api('POST', 'content', $body)->assertCreated()->json('data.id');
    $this->getJson('/api/v1/content/faq')->assertNotFound();
    $api('PATCH', "content/$id", ['version' => 1, 'media_id' => (string) Str::ulid()])->assertUnprocessable();
    $api('PATCH', "content/$id", ['version' => 1, 'status' => 'PUBLISHED'])->assertOk();
    $live = fn () => $this->getJson('/api/v1/content/faq');
    $live()->assertOk()->assertJsonPath('data.body', '<strong>Toko</strong>&lt;button&gt;Pesan&lt;/button&gt;');
    $api('PATCH', "content/$id", ['version' => 2, 'body' => 'Baru', 'publish_at' => now()->addHour()->toISOString()])->assertOk();
    $live()->assertOk()->assertJsonMissing(['body' => 'Baru']);
    $this->travel(2)->hours();
    $live()->assertOk()->assertJsonPath('data.body', 'Baru');
    $this->withSession(['staff.seen' => now()->timestamp]);
    $api('PATCH', "content/$id", ['version' => 2, 'status' => 'ARCHIVED'])->assertConflict();
    $api('PATCH', "content/$id", ['version' => 3, 'status' => 'ARCHIVED'])->assertOk();
    $live()->assertNotFound();
});
