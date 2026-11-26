<?php

namespace App\Modules\Content;

use App\Models\User;
use App\Modules\Catalog\ProductDirectory;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ContentEntries
{
    public static function mediaUrl(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }
        $key = ProductDirectory::publicMedia()->where('f.public_id', $file)->value('m.published_key');

        return $key ? Storage::disk('catalog')->url($key) : null;
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    public static function save(User $actor, ?int $id, array $data): array
    {
        $old = $id === null ? null : (DB::table('content_entries')->where('id', $id)->lockForUpdate()->first() ?? abort(404));
        if ($old && (int) $data['version'] !== $old->version) {
            throw new ApiProblem(409, 'STALE_VERSION', 'Muat ulang versi konten terbaru.');
        }
        $status = $data['status'] ?? $old?->status ?? 'DRAFT';
        if ($status === 'PUBLISHED' || $status === 'ARCHIVED') {
            abort_unless($actor->can('content.entry.'.($status === 'PUBLISHED' ? 'publish' : 'archive')), 403);
        }
        if ($old && isset($data['slug']) && $data['slug'] !== $old->slug) {
            throw ValidationException::withMessages(['slug' => 'Slug tersimpan tidak dapat diubah.']);
        }
        if (isset($data['media_id']) && self::mediaUrl($data['media_id']) === null) {
            throw ValidationException::withMessages(['media_id' => 'Pilih gambar katalog yang sudah terbit.']);
        }
        if (isset($data['body'])) {
            // Escape the entire input, then restore only literal formatting elements without attributes.
            $escaped = htmlspecialchars($data['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            $data['body'] = preg_replace('~&lt;(/?(?:p|br|strong|em|ul|ol|li|h2|h3))&gt;~', '<$1>', $escaped);
        }
        $data['status'] = $status;
        $data['version'] = ($old?->version ?? 0) + 1;
        try {
            if ($id === null) {
                $id = DB::table('content_entries')->insertGetId($data);
            } else {
                DB::table('content_entries')->where('id', $id)->update($data);
            }
        } catch (UniqueConstraintViolationException) {
            throw new ApiProblem(409, 'DUPLICATE_RESOURCE', 'Slug sudah digunakan.');
        }
        $saved = (array) DB::table('content_entries')->find($id);
        $publications = DB::table('content_publications')->where('entry_id', $id);
        if ($status === 'ARCHIVED') {
            $publications->delete();
        } else {
            $publications->where('effective_at', '>', now())->delete();
            if ($status === 'PUBLISHED') {
                DB::table('content_publications')->insert(['entry_id' => $id, 'version' => $saved['version'], 'effective_at' => $saved['publish_at'] ?? now(), 'snapshot' => json_encode($saved, JSON_THROW_ON_ERROR)]);
            }
        }
        app(Journal::class)->audit('content.changed', Subject::ContentEntry, $id, $actor->id, $old ? (array) $old : null, $saved);
        app(Outbox::class)->record('ContentChanged', 'content_entry', $id, ['version' => $saved['version'], 'status' => $status]);

        return $saved;
    }
}
