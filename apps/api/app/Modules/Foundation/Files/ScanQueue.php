<?php

namespace App\Modules\Foundation\Files;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

final class ScanQueue
{
    public function dispatchDue(Dispatcher $bus): int
    {
        // A lost enqueue or a dead worker leaves a lease, not a permanently stuck upload.
        $rows = DB::selectFromWriteConnection(<<<'SQL'
            WITH due AS MATERIALIZED (
                SELECT id FROM stored_files WHERE status IN ('QUARANTINED','SCANNING') AND scan_due_at <= now()
                ORDER BY scan_due_at, id LIMIT 25 FOR UPDATE SKIP LOCKED
            )
            UPDATE stored_files f SET status = 'SCANNING', scan_token = gen_random_uuid(),
                scan_due_at = now() + interval '10 minutes', updated_at = now()
            FROM due WHERE f.id = due.id RETURNING f.id, f.scan_token
            SQL);
        foreach ($rows as $row) {
            $bus->dispatch(new ScanStoredFile($row->id, $row->scan_token));
        }

        return count($rows);
    }

    public function prune(): int
    {
        // Import staging is temporary; release the uploader's five slots even for unusable CSVs.
        DB::table('stored_files')->where(['owner_type' => 'staging', 'collection' => 'region_import'])
            ->where('created_at', '<', now()->subDay())->whereNotIn('status', ['SCANNING', 'DELETED'])
            ->update(['status' => 'DELETED', 'scan_due_at' => null, 'updated_at' => now()]);
        $disk = Storage::disk('private');
        $removed = 0;
        foreach (['quarantine', 'ready'] as $prefix) {
            foreach ($disk->getDriver()->listContents($prefix, true) as $object) {
                if (! $object instanceof FileAttributes || ($object->lastModified() ?? PHP_INT_MAX) > now()->subDay()->timestamp) {
                    continue;
                }
                $referenced = DB::table('stored_files')->where(['disk' => 'private', 'object_key' => $object->path()])
                    ->whereNotIn('status', ['REJECTED', 'DELETED'])->exists();
                if (! $referenced) {
                    $disk->delete($object->path());
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
