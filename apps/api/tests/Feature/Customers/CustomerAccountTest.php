<?php

use App\Models\User;
use App\Modules\Customers\Addresses\AddressBook;
use App\Modules\Customers\Addresses\AddressSnapshot;
use App\Modules\Customers\Profiles\CustomerProfiles;
use App\Modules\Identity\Sessions\DeviceSessions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->customer = User::factory()->create();
    $this->other = User::factory()->create();
    app(CustomerProfiles::class)->open($this->customer->id, 'Pembeli');
    app(CustomerProfiles::class)->open($this->other->id, 'Tetangga');
    $this->asCustomer = function (User $user) {
        $this->actingAs($user)->withSession([DeviceSessions::SURFACE_KEY => 'CUSTOMER'])->withHeader('Origin', 'http://localhost');
    };
    $this->api = fn ($method, $path, $body = []) => $this->json($method, '/api/v1/'.$path, $body);
    $parent = null;
    foreach ([['COUNTRY', 'ID', 'Indonesia'], ['PROVINCE', '11', 'Aceh'], ['CITY', '11.01', 'Aceh Selatan'], ['DISTRICT', '11.01.01', 'Bakongan']] as [$kind, $code, $name]) {
        $parent = DB::table('regions')->insertGetId(compact('kind', 'code', 'name') + ['parent_id' => $parent]);
    }
    $this->address = ['recipient' => 'Ayu', 'phone' => '+628123456789', 'district_code' => '11.01.01', 'line1' => 'Jalan Contoh 1', 'postal_code' => '23773'];
});

it('requires a verified customer and keeps profile changes scoped to the signed-in account', function () {
    $api = $this->api;
    foreach (['profile', 'addresses', 'wishlist'] as $path) {
        $api('GET', $path)->assertUnauthorized();
    }
    ($this->asCustomer)(User::factory()->unverified()->create());
    $api('PATCH', 'profile', ['display_name' => 'Updated'])->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    ($this->asCustomer)($this->customer);
    $api('GET', 'profile')->assertOk()->assertJsonPath('data.email', $this->customer->email)->assertJsonMissingPath('data.user_id');
    $api('PATCH', 'profile')->assertUnprocessable();
    $api('PATCH', 'profile', ['user_id' => $this->other->id, 'display_name' => 'Other'])->assertUnprocessable();
    $api('PATCH', 'profile', ['email' => 'change@example.test'])->assertUnprocessable();
    $api('PATCH', 'profile', ['phone' => 'bukan-nomor'])->assertUnprocessable();
    $api('PATCH', 'profile', ['display_name' => 'Nama Baru', 'phone' => $this->address['phone'], 'marketing_opt_in' => true])
        ->assertOk()->assertJsonPath('data.display_name', 'Nama Baru')->assertJsonPath('data.marketing_opt_in', true);
    $api('PATCH', 'profile', ['phone' => null])->assertOk()->assertJsonPath('data.phone', null);
    ($this->asCustomer)($this->other);
    $api('GET', 'profile')->assertOk()->assertJsonPath('data.display_name', 'Tetangga');
    expect(DB::table('outbox_events')->where('event_type', 'ProfileUpdated')->count())->toBe(2);
});

it('keeps one default, archives rather than deletes, and deterministically promotes the oldest remaining address', function () {
    ($this->asCustomer)($this->customer);
    $api = $this->api;
    $first = $api('POST', 'addresses', $this->address + ['is_default' => false])->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
    $second = $api('POST', 'addresses', $this->address)->assertCreated()->assertJsonPath('data.is_default', false)->json('data.id');
    $third = $api('POST', 'addresses', $this->address + ['is_default' => true])->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
    $api('PATCH', "addresses/$third", ['is_default' => false])->assertUnprocessable();
    $api('PATCH', "addresses/$second", ['is_default' => true])->assertOk();
    $api('GET', 'addresses?per_page=1')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $second);
    $api('DELETE', "addresses/$second")->assertOk();
    $api('PATCH', "addresses/$second", ['line1' => 'Archived'])->assertNotFound();
    $api('GET', 'addresses')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first)->assertJsonPath('data.0.is_default', true);
    $api('DELETE', "addresses/$first")->assertOk();
    $api('DELETE', "addresses/$third")->assertOk();
    $api('GET', 'addresses')->assertJsonCount(0, 'data');
    expect(DB::table('addresses')->whereNotNull('deleted_at')->count())->toBe(3);
});

function customerAddressWriter(int $owner, array $data): Closure
{
    return static function () use ($owner, $data) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected disposable test database.');
        }

        return app(AddressBook::class)->save($owner, $data)->id;
    };
}

it('serializes two independent writers creating the first default', function () {
    $owner = $this->customer->id;
    $save = customerAddressWriter($owner, $this->address);
    $ids = Concurrency::driver('process')->run([$save, $save]);
    expect(array_unique($ids))->toHaveCount(2)
        ->and(DB::table('addresses')->where(['user_id' => $owner, 'is_default' => true])->count())->toBe(1);
});

it('validates active districts, rejects owner overrides, and returns 404 for other accounts addresses', function () {
    ($this->asCustomer)($this->customer);
    $api = $this->api;
    $api('POST', 'addresses', ['district_code' => '11.01'] + $this->address)->assertUnprocessable();
    $api('POST', 'addresses', ['user_id' => $this->other->id] + $this->address)->assertUnprocessable();
    $id = $api('POST', 'addresses', $this->address)->assertCreated()->json('data.id');
    ($this->asCustomer)($this->other);
    $api('GET', 'addresses')->assertJsonCount(0, 'data');
    $api('PATCH', "addresses/$id", ['recipient' => 'Other'])->assertNotFound();
    $api('DELETE', "addresses/$id")->assertNotFound();
    expect(fn () => DB::transaction(fn () => app(AddressSnapshot::class)->capture($this->other->id, $id)))->toThrow(NotFoundHttpException::class);
    DB::table('regions')->where('kind', 'DISTRICT')->update(['active' => false]);
    $api('POST', 'addresses', $this->address)->assertUnprocessable();
    expect(fn () => DB::transaction(fn () => app(AddressSnapshot::class)->capture($this->customer->id, $id)))->toThrow(ValidationException::class);
});

it('captures fulfillment values in the order transaction, not a live pointer to mutable address or region fields', function () {
    ($this->asCustomer)($this->customer);
    $api = $this->api;
    $id = $api('POST', 'addresses', $this->address)->assertCreated()->json('data.id');
    $capture = fn () => app(AddressSnapshot::class)->capture($this->customer->id, $id);
    expect($capture)->toThrow(LogicException::class);
    $old = DB::transaction($capture);
    $api('PATCH', "addresses/$id", ['line1' => 'Jalan Baru'])->assertOk();
    DB::table('regions')->where('kind', 'DISTRICT')->update(['name' => 'Nama Baru']);
    $new = DB::transaction($capture);
    $api('DELETE', "addresses/$id")->assertOk();
    expect($old['line1'])->toBe('Jalan Contoh 1')->and($old['region_snapshot']['district'])->toBe(['code' => '11.01.01', 'name' => 'Bakongan'])
        ->and($new['line1'])->toBe('Jalan Baru')->and($new['region_snapshot']['district']['name'])->toBe('Nama Baru')
        ->and($new['source_address_id'])->toBe($old['source_address_id']);
    expect(fn () => DB::transaction($capture))->toThrow(NotFoundHttpException::class);
    expect(DB::table('outbox_events')->pluck('payload')->implode(''))->not->toContain('Jalan', $this->address['phone'], 'Ayu');
});

it('upserts only published favorites per account and excludes archived products before pagination', function () {
    ($this->asCustomer)($this->customer);
    $api = $this->api;
    foreach (['kopi' => 'PUBLISHED', 'teh' => 'PUBLISHED', 'draft' => 'DRAFT'] as $slug => $status) {
        DB::table('products')->insert(compact('slug', 'status') + ['name' => ucfirst($slug)]);
    }
    $api('PUT', 'wishlist/unknown')->assertNotFound();
    $api('PUT', 'wishlist/draft')->assertNotFound();
    $api('PUT', 'wishlist/kopi')->assertOk();
    $api('PUT', 'wishlist/kopi')->assertOk();
    $api('PUT', 'wishlist/teh')->assertOk();
    DB::table('products')->where('slug', 'teh')->update(['status' => 'ARCHIVED']);
    $api('GET', 'wishlist?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'kopi')->assertJsonMissingPath('data.0.id');
    ($this->asCustomer)($this->other);
    $api('GET', 'wishlist')->assertJsonCount(0, 'data');
    $api('DELETE', 'wishlist/kopi')->assertOk();
    ($this->asCustomer)($this->customer);
    $api('GET', 'wishlist')->assertJsonCount(1, 'data');
    $api('DELETE', 'wishlist/teh')->assertOk();
    $api('DELETE', 'wishlist/teh')->assertOk();
    DB::table('products')->where('slug', 'kopi')->update(['deleted_at' => now()]);
    $api('PUT', 'wishlist/kopi')->assertNotFound();
    $api('GET', 'wishlist')->assertJsonCount(0, 'data');
    $this->assertDatabaseCount('wishlists', 1);
});

it('separates support directory and PII access, audits successful reads without copying PII, and excludes staff', function () {
    ($this->asCustomer)($this->customer);
    $api = $this->api;
    $api('POST', 'addresses', $this->address)->assertCreated();
    $id = $this->customer->id;
    $this->seed(PermissionSeeder::class);
    $staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->actingAs($staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF',
        'staff.version' => $staff->staff_session_version, 'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);
    $api('GET', 'profile')->assertForbidden();
    $api('GET', 'admin/customers')->assertForbidden();
    $staff->givePermissionTo('customers.customer.view');
    $api('GET', 'admin/customers?q=Pembeli')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.address_count', 1);
    $api('GET', "admin/customers/$id")->assertOk()->assertJsonMissingPath('data.phone');
    $api('GET', 'admin/customers/'.$staff->id)->assertNotFound();
    $api('GET', "admin/customers/$id/addresses")->assertForbidden();
    $staff->givePermissionTo('customers.customer.pii_view');
    $api('GET', "admin/customers/$id/addresses")->assertOk()->assertJsonPath('data.0.recipient', 'Ayu');
    $cursor = $api('GET', "admin/customers/$id/activity?per_page=1")->assertOk()->assertJsonPath('data.0.kind', 'SUPPORT_ACCESS')
        ->assertJsonMissingPath('data.0.actor_id')->json('meta.next_cursor');
    expect($cursor)->toBeString();
    $api('GET', "admin/customers/$id/activity?cursor=".urlencode($cursor))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    $api('GET', "admin/customers/$id/activity?cursor=oops")->assertUnprocessable();
    $api('GET', "admin/customers/$id/activity?from=2020-02-02&to=2020-02-01")->assertUnprocessable();
    $this->assertDatabaseHas('audit_logs', ['action' => 'CustomerAddressAccessed', 'actor_id' => $staff->id, 'subject_id' => $id, 'before' => null, 'after' => null]);
    $this->assertDatabaseCount('audit_logs', 3);
});
