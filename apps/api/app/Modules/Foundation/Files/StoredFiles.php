<?php

namespace App\Modules\Foundation\Files;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Models\StoredFile;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

final class StoredFiles
{
    public function stage(UploadedFile $upload, string $collection): StagedFile
    {
        $mime = FileRules::inspect($upload, $collection);
        $id = (string) Str::ulid();
        $key = "quarantine/{$collection}/{$id}";
        $stream = fopen($upload->getPathname(), 'rb');
        if (! $stream) {
            throw new RuntimeException('Cannot read uploaded file');
        }
        try {
            Storage::disk('private')->put($key, $stream);
        } finally {
            fclose($stream);
        }

        return new StagedFile($id, $key, $mime, (int) $upload->getSize(), (string) hash_file('sha256', $upload->getPathname()), mb_substr($upload->getClientOriginalName(), 0, 255));
    }

    public function register(StagedFile $file, string $type, int $owner, string $collection, int $actor): StoredFile
    {
        if (DB::transactionLevel() === 0 || ! in_array($type, FileRules::OWNERS[$collection] ?? [], true)) {
            throw new LogicException('A domain transaction and allowed parent collection are required.');
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["stored_files:$type:$owner:$collection"]);
        $this->checkQuota($type, $owner, $collection, $file->mime);
        $id = DB::table('stored_files')->insertGetId([
            'public_id' => $file->publicId, 'owner_type' => $type, 'owner_id' => $owner, 'collection' => $collection,
            'uploaded_by' => $actor, 'object_key' => $file->key, 'mime' => $file->mime, 'size_bytes' => $file->size,
            'sha256' => $file->hash, 'original_name' => $file->name,
        ]);
        app(Outbox::class)->record('FileUploaded', 'stored_file', $id, []);

        return StoredFile::query()->findOrFail($id);
    }

    public function checkQuota(string $type, int $owner, string $collection, string $mime): void
    {
        $active = DB::table('stored_files')->where(['owner_type' => $type, 'owner_id' => $owner, 'collection' => $collection])
            ->whereNotIn('status', ['REJECTED', 'DELETED']);
        if (($collection !== 'catalog_media' && $active->count() >= 5)
            || ($mime === 'video/mp4' && $active->where('mime', 'video/mp4')->exists())) {
            throw new FileProblem(422, 'VALIDATION_FAILED', 'Collection quota reached');
        }
    }

    /** Call from the authorized parent action, inside its transaction before accepting a proof. */
    public function requireReady(string $publicId, string $type, int $owner, string $collection): StoredFile
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Bind a file inside the parent transaction.');
        }
        $row = StoredFile::query()->where(['public_id' => $publicId, 'owner_type' => $type,
            'owner_id' => $owner, 'collection' => $collection])->sharedLock()->first();
        abort_if(! $row || $row->status === 'DELETED', 404);
        if ($row->status !== 'READY') {
            throw new FileProblem(409, 'FILE_NOT_READY', 'File is not ready');
        }

        return $row;
    }

    /** @return array{url:string,expires_at:string} */
    public function download(StoredFile $file, int $actor): array
    {
        return DB::transaction(function () use ($file, $actor) {
            $row = $this->requireReady($file->public_id, $file->owner_type, $file->owner_id, $file->collection);
            abort_unless($row->disk === 'private' && $row->visibility === 'PRIVATE', 404);
            $expires = now()->addMinutes(5);
            $config = config('filesystems.disks.private');
            $config['endpoint'] = config('stored_files.signing_endpoint');
            $url = Storage::build($config)->temporaryUrl($row->object_key, $expires, [
                'ResponseContentDisposition' => 'attachment', 'ResponseCacheControl' => 'no-store',
            ]);
            app(Journal::class)->audit('file.download_issued', Subject::File, $row->id, $actor);

            return ['url' => $url, 'expires_at' => $expires->toISOString()];
        });
    }
}
