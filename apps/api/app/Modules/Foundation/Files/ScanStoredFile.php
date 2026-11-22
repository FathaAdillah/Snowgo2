<?php

namespace App\Modules\Foundation\Files;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ScanStoredFile implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $fileId, public readonly string $token)
    {
        $this->onQueue('default');
    }

    public function handle(MalwareScanner $scanner): void
    {
        $claim = DB::table('stored_files')->where(['id' => $this->fileId, 'status' => 'SCANNING', 'scan_token' => $this->token]);
        $row = (clone $claim)->first();
        if (! $row) {
            return;
        }
        $disk = Storage::disk('private');
        $input = $disk->readStream($row->object_key);
        if (! is_resource($input)) {
            throw new RuntimeException('Cannot read quarantined object');
        }
        try {
            $bytes = stream_get_contents($input, 20 * 1048576 + 1);
        } finally {
            fclose($input);
        }
        if ($bytes === false) {
            throw new RuntimeException('Quarantine read failed');
        }
        $accepted = strlen($bytes) === $row->size_bytes && hash_equals($row->sha256, hash('sha256', $bytes))
            && $scanner->clean($bytes) && FileRules::validContainer($row->mime, $bytes);
        if ($accepted && str_starts_with($row->mime, 'image/')) {
            $image = @imagecreatefromstring($bytes);
            $accepted = $image !== false;
            if ($accepted && $row->collection === 'catalog_media') {
                ob_start();
                try {
                    $encoded = match ($row->mime) {
                        'image/png' => imagepng($image),
                        'image/jpeg' => imagejpeg($image, quality: 90),
                        'image/webp' => imagewebp($image, quality: 90),
                        default => false,
                    };
                    $bytes = (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $accepted = $encoded && $bytes !== '' && strlen($bytes) <= 8 * 1048576;
            }
        }
        $result = ['status' => $accepted ? 'READY' : 'REJECTED', 'scan_due_at' => null, 'scan_token' => null, 'updated_at' => now()];
        if ($accepted) {
            // Different leases never overwrite each other's bytes. Catalog publication is a separate domain action.
            $key = "ready/{$row->collection}/{$row->public_id}/{$this->token}";
            $disk->put($key, $bytes, ['ContentType' => $row->mime, 'CacheControl' => 'no-store']);
            $result += ['object_key' => $key, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)];
        }
        if ($claim->update($result)) {
            $disk->delete($row->object_key);
        }
    }
}
