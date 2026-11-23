<?php

use App\Models\User;
use App\Modules\Geography\RegionBatch;
use App\Modules\Geography\WilayahSource;
use App\Modules\Identity\Sessions\DeviceSessions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['sanctum.stateful' => ['admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->seed(PermissionSeeder::class);
    $this->staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()]);
    $this->sessionFor = function (User $actor) {
        $this->actingAs($actor)->withSession([DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $actor->staff_session_version,
            'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp]);
    };
    ($this->sessionFor)($this->staff->refresh());
    $this->withHeader('Origin', 'http://admin.localhost');
    $this->api = fn ($method, $path, $input = [], $key = null) => $this->json($method, '/api/v1/admin/'.$path, $input,
        ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
});

it('separates view, create and update and never hard deletes reference data', function ($path, $resource, $input) {
    $request = $this->api;
    $request('GET', $path)->assertForbidden();
    $request('POST', $path, $input)->assertForbidden();
    $this->staff->givePermissionTo("geography.$resource.create");
    $id = $request('POST', $path, $input, 'first')->assertCreated()->json('data.id');
    $request('POST', $path, $input, 'first')->assertCreated()->assertJsonPath('data.id', $id);
    $request('POST', $path, $input)->assertConflict()->assertJsonPath('code', 'DUPLICATE_RESOURCE');
    $request('PATCH', "$path/$id", ['active' => false])->assertForbidden();
    $request('GET', "$path/$id")->assertForbidden();
    $this->staff->givePermissionTo("geography.$resource.view", "geography.$resource.update");
    $request('PATCH', "$path/$id", [])->assertUnprocessable();
    $request('PATCH', "$path/$id", ['unexpected' => true])->assertUnprocessable();
    $request('PATCH', "$path/$id", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
    $request('GET', "$path/$id")->assertOk()->assertJsonPath('data.code', $input['code']);
    $request('GET', "$path?q={$input['code']}&active=0&per_page=1")->assertOk()->assertJsonPath('meta.total', 1);
    $request('GET', "$path?active=true")->assertOk()->assertJsonCount(0, 'data');
    $request('DELETE', "$path/$id")->assertMethodNotAllowed();
    $this->assertDatabaseCount('audit_logs', 2);
    $this->assertDatabaseCount('outbox_events', 2);
})->with([
    ['regions', 'region', ['code' => 'ID', 'kind' => 'COUNTRY', 'name' => 'Indonesia', 'active' => true]],
    ['reason-codes', 'reason', ['domain' => 'orders', 'code' => 'damaged', 'label' => 'Rusak', 'active' => true]],
    ['units', 'unit', ['code' => 'pcs', 'name' => 'Buah', 'active' => true]],
    ['tax-classes', 'tax', ['code' => 'zero', 'name' => 'Zero', 'rate_bps' => 0, 'active' => true]],
]);

it('enforces hierarchy on edits and only exposes active public fields', function () {
    $this->staff->assignRole('super_admin');
    $request = $this->api;
    $country = ['kind' => 'COUNTRY', 'code' => 'ID', 'name' => 'Indonesia', 'active' => true];
    $request('POST', 'regions', ['code' => 'XX'] + $country)->assertUnprocessable();
    $id = $request('POST', 'regions', $country)->assertCreated()->json('data.id');
    $province = ['kind' => 'PROVINCE', 'code' => '11', 'name' => 'Aceh', 'parent_id' => $id, 'active' => true];
    $request('POST', 'regions', ['kind' => 'CITY'] + $province)->assertUnprocessable();
    $aceh = $request('POST', 'regions', $province)->assertCreated()->json('data.id');
    $request('PATCH', "regions/$id", ['active' => false])->assertConflict();
    $request('PATCH', "regions/$aceh", ['parent_id' => $aceh])->assertUnprocessable();
    $request('PATCH', "regions/$aceh", ['code' => 'ID'])->assertConflict();
    $query = '/api/v1/regions?parent=ID&kind=PROVINCE';
    $this->getJson($query)->assertOk()->assertJsonPath('data.0.code', '11')->assertJsonPath('data.0.parent_code', 'ID')
        ->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.parent_id')->assertJsonMissingPath('data.0.active');
    $request('PATCH', "regions/$aceh", ['active' => false])->assertOk();
    $this->getJson($query)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/regions?active=0')->assertUnprocessable();
    $request('POST', 'tax-classes', ['code' => 'X', 'name' => 'Invalid', 'active' => true, 'rate_bps' => 10001])->assertUnprocessable();
});

it('imports only the actors READY csv, atomically, and emits no duplicate audit or event', function () {
    Storage::fake('private');
    $this->staff->givePermissionTo('geography.region.import', 'foundation.file.create');
    $header = "kind,code,name,parent_code,active\n";
    $upload = function ($body) use ($header) {
        return $this->post('/api/v1/admin/files', ['collection' => 'region_import', 'owner_type' => 'staging',
            'owner_ref' => 'regions', 'file' => UploadedFile::fake()->createWithContent('regions.csv', $header.$body)],
            ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])->assertAccepted()->json('data.id');
    };
    $import = fn ($id, $key = null) => ($this->api)('POST', 'region-imports', ['file_id' => $id], $key);
    $ready = fn ($id) => DB::table('stored_files')->where('public_id', $id)->update(['status' => 'READY', 'scan_due_at' => null]);
    $first = $upload("CITY,11.01,Kabupaten Aceh Selatan,11,1\nCOUNTRY,ID,Indonesia,,1\nDISTRICT,11.01.01,Bakongan,11.01,1\nPROVINCE,11,Aceh,ID,1\n");
    $import($first)->assertConflict()->assertJsonPath('code', 'FILE_NOT_READY');
    $ready($first);
    $import($first, 'import-1')->assertAccepted();
    $import($first, 'import-1')->assertAccepted();
    $import($first, 'import-2')->assertAccepted();
    expect(DB::table('outbox_events')->where('event_type', 'RegionImported')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('actor_id', $this->staff->id)->where('action', 'RegionImported')->count())->toBe(1);
    $this->assertDatabaseCount('regions', 4);
    foreach (['DISTRICT,NEW,Bad,11,1', 'CITY,NEW,Bad,MISSING,1', 'CITY,11,Bad,11,1', "CITY,11.01,A,11,1\nCITY,11.01,B,11,1"] as $bad) {
        $csv = $upload("PROVINCE,11,Changed,ID,1\n".$bad."\n");
        $ready($csv);
        $import($csv)->assertUnprocessable();
        $this->assertDatabaseHas('regions', ['code' => '11', 'name' => 'Aceh']);
    }
    $this->staff->revokePermissionTo('geography.region.import');
    $import($first, 'import-1')->assertForbidden();
    $other = User::factory()->staff()->create(['two_factor_confirmed_at' => now()]);
    $other->givePermissionTo('geography.region.import');
    ($this->sessionFor)($other->refresh());
    $import($first)->assertNotFound();
});

it('reads the selected Pages v2 endpoints with dotted IDs, without reactivating archived regions', function () {
    Http::preventStrayRequests();
    $source = WilayahSource::BASE;
    foreach ([
        ['ID', 'provinces', 1, '11', 'Aceh'],
        ['11', 'regencies/11', 2, '11.01', 'Kabupaten Aceh Selatan'],
        ['11.01', 'districts/11.01', 3, '11.01.01', 'Bakongan'],
    ] as [$parent, $path, $level, $id, $name]) {
        Http::fake([$source.$path.'.json' => Http::response(['meta' => ['level' => $level], 'data' => [compact('id', 'name')]])]);
        $this->artisan('geography:sync-wilayah', ['--parent' => $parent])->assertSuccessful();
    }
    DB::table('regions')->where('code', '11.01.01')->update(['active' => false]);
    $this->artisan('geography:sync-wilayah', ['--parent' => '11.01'])->expectsOutput('Changed regions: 0')->assertSuccessful();
    $this->assertDatabaseHas('regions', ['code' => '11.01.01', 'active' => false]);
    expect(fn () => app(WilayahSource::class)->page('http://invalid.test'))->toThrow(UnexpectedValueException::class);
    $this->assertDatabaseCount('regions', 4);
});

it('validates the full snapshot before changing the database', function () {
    $disk = Storage::fake('snapshot');
    $write = fn ($file, $level, $data, $date = '2026-09-07') => $disk->put($file, json_encode([
        'meta' => ['updated_at' => $date, 'level' => $level], 'data' => $data,
    ], JSON_THROW_ON_ERROR));
    $write('stats.json', 0, ['total_provinces' => 1, 'total_regencies' => 1, 'total_districts' => 1]);
    $write('provinces.json', 1, [['id' => '11', 'name' => 'Aceh']]);
    $write('regencies/11.json', 2, [['id' => '11.01', 'name' => 'Kabupaten Aceh Selatan']]);
    $path = 'districts/11.01.json';
    $rows = [['id' => '11.01.01', 'name' => 'Bakongan']];
    $load = fn () => app(WilayahSource::class)->snapshot($disk->path(''))->import(null);
    expect($load)->toThrow(UnexpectedValueException::class);
    $write($path, 3, $rows, '2026-09-08');
    expect($load)->toThrow(UnexpectedValueException::class);
    $write($path, 3, [...$rows, ['id' => '11.01.02', 'name' => 'Unexpected']]);
    expect($load)->toThrow(UnexpectedValueException::class);
    $this->assertDatabaseEmpty('regions');
    $write($path, 3, $rows);
    expect($load())->toBe(4)->and($load())->toBe(0);
});

it('rejects broken CSV quoting without merging rows and preserves quoted names', function () {
    $disk = Storage::fake('csv');
    $header = "kind,code,name,parent_code,active\r\n";
    $disk->put('bad.csv', $header."COUNTRY,ID,\"Indonesia,,1\r\nCOUNTRY,OTHER,other\",,1\r\n");
    expect(fn () => RegionBatch::csv($disk->readStream('bad.csv'))->import(null))->toThrow(ValidationException::class);
    $this->assertDatabaseEmpty('regions');
    $disk->put('good.csv', $header."COUNTRY,ID,\"Indonesia, \"\"ID\"\"\",,1\r\n");
    expect(RegionBatch::csv($disk->readStream('good.csv'))->import(null))->toBe(1);
    $this->assertDatabaseHas('regions', ['name' => 'Indonesia, "ID"']);
});
