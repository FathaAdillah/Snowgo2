<?php

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;
use App\Modules\Foundation\Files\FileProblem;
use App\Modules\Foundation\Files\FileRules;
use App\Modules\Foundation\Files\MalwareScanner;
use App\Modules\Foundation\Files\ScanStoredFile;
use App\Modules\Foundation\Files\StoredFiles;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

final class FixtureFileParent implements FileOwnerPolicy
{
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        return $reference === "P-{$actor->id}" ? $actor->id : null;
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        return $actor->id === $ownerId;
    }
}

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->actingAs($this->actor);
    Gate::define('files.surface.customer', fn (User $user) => true);
    config(['stored_files.owners' => ['payment' => FixtureFileParent::class]]);
    $this->pdf = (new Process(['qpdf', '--empty', '-']))->mustRun()->getOutput();
    $this->upload = function (string $bytes, string $name = 'proof.pdf', string $key = 'u1', string $surface = '') {
        $path = tempnam(sys_get_temp_dir(), 'upload-test');
        file_put_contents($path, $bytes);
        try {
            return $this->withHeaders(['Accept' => 'application/json', 'Idempotency-Key' => $key])
                ->post("/api/v1/{$surface}files", ['owner_type' => 'payment', 'owner_ref' => 'P-'.$this->actor->id,
                    'collection' => 'payment_proof', 'file' => new UploadedFile($path, $name, test: true)]);
        } finally {
            unlink($path);
        }
    };
});

it('quarantines once, rechecks ownership on replay, and refuses unready or cross-owner use', function () {
    $disk = Storage::fake('private');
    $first = ($this->upload)($this->pdf)->assertAccepted()->assertJsonPath('data.status', 'QUARANTINED');
    $id = $first->json('data.id');
    ($this->upload)($this->pdf)->assertAccepted()->assertJsonPath('data.id', $id);
    expect($disk->allFiles('quarantine'))->toHaveCount(1);
    $this->assertDatabaseCount('outbox_events', 1);
    $this->getJson("/api/v1/files/$id/download")->assertConflict()->assertJsonPath('code', 'FILE_NOT_READY');
    expect(fn () => DB::transaction(fn () => app(StoredFiles::class)->requireReady($id, 'payment', $this->actor->id, 'payment_proof')))->toThrow(FileProblem::class);
    config(['stored_files.owners' => []]);
    ($this->upload)($this->pdf)->assertNotFound();
    config(['stored_files.owners.payment' => FixtureFileParent::class]);
    $this->actingAs(User::factory()->create());
    $this->getJson("/api/v1/files/$id")->assertNotFound();
    $this->getJson("/api/v1/files/$id/download")->assertNotFound();
    ($this->upload)($this->pdf)->assertNotFound();
    $this->assertDatabaseEmpty('audit_logs');
});

it('requires both staff session and action permission even when a parent is accessible', function () {
    Storage::fake('private');
    $this->withHeaders(['Accept' => 'application/json', 'X-Request-Id' => 'customer-import-check'])
        ->post('/api/v1/files', ['owner_type' => 'staging', 'owner_ref' => 'regions', 'collection' => 'region_import',
            'file' => UploadedFile::fake()->createWithContent('regions.csv', "code,name\n1,Bali\n")])
        ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN')->assertJsonPath('meta.request_id', 'customer-import-check');
    ($this->upload)($this->pdf, surface: 'admin/')->assertForbidden();
    Gate::define('files.surface.staff', fn (User $user) => true);
    ($this->upload)($this->pdf, surface: 'admin/')->assertForbidden();
    Gate::define('foundation.file.create', fn (User $user) => true);
    $id = ($this->upload)($this->pdf, surface: 'admin/')->assertAccepted()->json('data.id');
    $this->getJson("/api/v1/admin/files/$id")->assertForbidden();
    Gate::define('foundation.file.view', fn (User $user) => true);
    $this->getJson("/api/v1/admin/files/$id")->assertOk();
});

it('rejects mismatched content, extensions and oversize documents before writing objects', function () {
    $disk = Storage::fake('private');
    ($this->upload)('not a PDF')->assertUnsupportedMediaType()->assertJsonPath('code', 'UPLOAD_REJECTED');
    ($this->upload)($this->pdf, 'different.png')->assertUnsupportedMediaType();
    ($this->upload)($this->pdf.str_repeat('x', 10 * 1048576))->assertStatus(413);
    expect($disk->allFiles())->toBeEmpty();
    $this->assertDatabaseEmpty('stored_files');
});

it('reserves quota per parent and collection, without staging a sixth private file', function () {
    $disk = Storage::fake('private');
    for ($i = 0; $i < 5; $i++) {
        ($this->upload)($this->pdf, key: "k$i")->assertAccepted();
    }
    ($this->upload)($this->pdf, key: 'extra')->assertUnprocessable();
    expect($disk->allFiles())->toHaveCount(5);
});

it('keeps a scanner outage private, reclaims the lease and rejects detected content', function () {
    $disk = Storage::fake('private');
    Bus::fake();
    $id = ($this->upload)($this->pdf)->assertAccepted()->json('data.id');
    $this->artisan('files:dispatch-scans')->assertSuccessful();
    $job = Bus::dispatched(ScanStoredFile::class)->first();
    $offline = Mockery::mock(MalwareScanner::class);
    $offline->shouldReceive('clean')->once()->andThrow(new RuntimeException('offline'));
    expect(fn () => $job->handle($offline))->toThrow(RuntimeException::class);
    $this->getJson("/api/v1/files/$id/download")->assertConflict();
    $this->artisan('files:dispatch-scans')->assertSuccessful();
    Bus::assertDispatchedTimes(ScanStoredFile::class, 1);
    DB::table('stored_files')->update(['scan_due_at' => now()->subMinute()]);
    $this->artisan('files:dispatch-scans')->assertSuccessful();
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturn(false);
    $job->handle($scanner);
    Bus::dispatched(ScanStoredFile::class)->last()->handle($scanner);
    $this->getJson("/api/v1/files/$id")->assertOk()->assertJsonPath('data.status', 'REJECTED');
    $this->getJson("/api/v1/files/$id/download")->assertConflict();
    expect($disk->allFiles())->toBeEmpty();
});

it('re-encodes clean catalog images but never publishes an unpublished product', function () {
    $private = Storage::fake('private');
    $catalog = Storage::fake('catalog');
    Bus::fake();
    $upload = UploadedFile::fake()->image('product.png', 40, 40);
    file_put_contents($upload->getPathname(), 'unneeded trailing metadata', FILE_APPEND);
    $staged = app(StoredFiles::class)->stage($upload, 'catalog_media');
    $row = DB::transaction(fn () => app(StoredFiles::class)->register($staged, 'product', 1, 'catalog_media', $this->actor->id));
    $this->artisan('files:dispatch-scans')->assertSuccessful();
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('clean')->once()->andReturn(true);
    Bus::dispatched(ScanStoredFile::class)->first()->handle($scanner);
    $ready = DB::transaction(fn () => app(StoredFiles::class)->requireReady($row->public_id, 'product', 1, 'catalog_media'));
    expect($private->get($ready->object_key))->not->toContain('unneeded trailing metadata')
        ->and($catalog->allFiles())->toBeEmpty();
});

it('removes only stale unreferenced objects in managed prefixes', function () {
    $disk = Storage::fake('private');
    ($this->upload)($this->pdf)->assertAccepted();
    foreach (['quarantine/old', 'ready/new', 'outside/test'] as $key) {
        $disk->put($key, 'fixture');
    }
    foreach (['quarantine/old', 'outside/test', DB::table('stored_files')->value('object_key')] as $key) {
        touch($disk->path($key), now()->subDays(2)->timestamp);
    }
    $this->artisan('files:prune')->assertSuccessful();
    $disk->assertMissing('quarantine/old');
    $disk->assertExists(['ready/new', 'outside/test', DB::table('stored_files')->value('object_key')]);
});

it('scans with clamd and issues an audited five-minute MinIO download only for the authorized ready file', function () {
    $realBus = Bus::getFacadeRoot();
    Bus::fake();
    $endpoint = config('filesystems.disks.private.endpoint');
    config(['stored_files.signing_endpoint' => $endpoint]);
    $id = ($this->upload)($this->pdf)->assertAccepted()->json('data.id');
    try {
        $this->artisan('files:dispatch-scans')->assertSuccessful();
        $queue = 'stored-file-test-'.Str::uuid();
        Queue::connection('redis')->pushOn($queue, Bus::dispatched(ScanStoredFile::class)->first());
        Bus::swap($realBus);
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();
        $row = DB::table('stored_files')->where('public_id', $id)->first();
        expect($row->status)->toBe('READY');
        $this->assertDatabaseCount('stored_files', 1);
        $response = $this->getJson("/api/v1/files/$id/download")->assertOk();
        $url = $response->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        expect($params['X-Amz-Expires'])->toBeIn(['299', '300'])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
        $get = Http::get($url);
        expect($get->status())->toBe(200)->and($get->body())->toBe($this->pdf)
            ->and($get->header('Content-Disposition'))->toBe('attachment')
            ->and($get->header('Cache-Control'))->toBe('no-store');
        $this->assertDatabaseHas('audit_logs', ['action' => 'file.download_issued', 'subject_id' => $row->id, 'actor_id' => $this->actor->id]);
        expect(Http::get(strtok($url, '?'))->status())->toBe(403);
        expect(Http::head($url)->status())->toBe(403);
        $client = Storage::disk('private')->getClient();
        $cmd = $client->getCommand('GetObject', ['Bucket' => config('filesystems.disks.private.bucket'), 'Key' => $row->object_key]);
        $expired = $client->createPresignedRequest($cmd, now()->subHour(), ['start_time' => now()->subMinutes(65)])->getUri();
        expect(Http::get((string) $expired)->status())->toBe(403);
        $this->actingAs(User::factory()->create());
        $this->getJson("/api/v1/files/$id/download")->assertNotFound();
    } finally {
        Storage::disk('private')->delete(DB::table('stored_files')->where('public_id', $id)->value('object_key'));
    }
})->group('integration');

it('allows only published catalog objects to be read anonymously, not bucket listings', function () {
    $disk = Storage::disk('catalog');
    $base = config('filesystems.disks.catalog.endpoint').'/'.config('filesystems.disks.catalog.bucket');
    $id = (string) Str::ulid();
    try {
        foreach (['published', 'drafts'] as $prefix) {
            $disk->put("$prefix/$id", 'test');
        }
        expect(Http::get("$base/published/$id")->status())->toBe(200)
            ->and(Http::get("$base/drafts/$id")->status())->toBe(403)
            ->and(Http::get("$base?list-type=2")->status())->toBe(403);
    } finally {
        $disk->delete(["published/$id", "drafts/$id"]);
    }
})->group('integration');

it('rejects the inert EICAR antivirus fixture from quarantine and accepts a clean replacement', function () {
    $fixture = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    Storage::fake('private');
    Bus::fake();
    config(['stored_files.owners.staging' => FixtureFileParent::class]);
    Gate::define('files.surface.staff', fn (User $user) => true);
    Gate::define('foundation.file.create', fn (User $user) => true);
    Gate::define('foundation.file.view', fn (User $user) => true);
    foreach (['REJECTED' => $fixture, 'READY' => "code,name\n1,Bali\n"] as $status => $bytes) {
        $result = $this->withHeaders(['Accept' => 'application/json', 'Idempotency-Key' => "import-$status"])
            ->post('/api/v1/admin/files', ['owner_type' => 'staging', 'owner_ref' => 'P-'.$this->actor->id,
                'collection' => 'region_import', 'file' => UploadedFile::fake()->createWithContent('regions.csv', $bytes)]);
        $id = $result->assertAccepted()->json('data.id');
        $this->artisan('files:dispatch-scans')->assertSuccessful();
        Bus::dispatched(ScanStoredFile::class)->last()->handle(app(MalwareScanner::class));
        $this->getJson("/api/v1/admin/files/$id")->assertOk()->assertJsonPath('data.status', $status);
    }
})->group('integration');

it('validates PDF and MP4 structure, and limits return evidence to one active video', function () {
    expect(FileRules::validContainer('application/pdf', "%PDF-1.4\n%%EOF"))->toBeFalse()
        ->and(FileRules::validContainer('video/mp4', "\0\0\0\x18ftypisom"))->toBeFalse();
    $video = (new Process(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=size=7680x4320', '-frames:v', '1',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '1',
        '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1']))->mustRun()->getOutput();
    expect(FileRules::validContainer('video/mp4', $video))->toBeTrue();
    $metadataOnly = substr($video, 0, strpos($video, 'moof') - 4);
    expect(FileRules::validContainer('video/mp4', $metadataOnly))->toBeFalse();
    Storage::fake('private');
    $files = app(StoredFiles::class);
    $file = $files->stage(UploadedFile::fake()->createWithContent('return.mp4', $video), 'return_evidence');
    DB::transaction(fn () => $files->register($file, 'return', 1, 'return_evidence', $this->actor->id));
    expect(fn () => $files->checkQuota('return', 1, 'return_evidence', 'video/mp4'))->toThrow(FileProblem::class);
});
