<?php

namespace App\Modules\Catalog;

use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\RecordedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PublishMedia implements EventHandler
{
    public function handle(RecordedEvent $event): void
    {
        // The outbox runs handlers transactionally. Always reconcile current state, not the historical event.
        $product = DB::table('products')->where('id', $event->payload['product_id'])->lockForUpdate()->first();
        if (! $product) {
            return;
        }
        $id = $product->id;
        $rows = DB::table('product_media as m')->where('m.product_id', $id)->join('stored_files as f', 'f.id', '=', 'm.file_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'm.variant_id')->orderBy('m.id')
            ->get(['m.*', 'v.active as variant_active', 'v.deleted_at as variant_deleted', 'f.collection', 'f.object_key', 'f.mime', 'f.status', 'f.owner_id', 'f.owner_type']);
        $disk = Storage::disk('catalog');
        foreach ($rows as $m) {
            $allowed = $product->status === 'PUBLISHED' && ! $product->deleted_at && $m->active && $m->status === 'READY'
                && $m->collection === 'catalog_media' && str_starts_with($m->object_key, 'ready/catalog_media/')
                && ($m->variant_id === null ? ($m->owner_type === 'product' && $m->owner_id === $id)
                    : ($m->owner_type === 'variant' && $m->owner_id === $m->variant_id && $m->variant_active && ! $m->variant_deleted));
            $key = "published/catalog/$id/$m->id";
            if ($allowed && $m->published_key === $key) {
                continue;
            }
            if ($allowed) {
                $stream = Storage::disk('private')->readStream($m->object_key);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Cannot read ready catalog image.');
                }
                try {
                    $disk->put($key, $stream, ['ContentType' => $m->mime, 'CacheControl' => 'no-cache']);
                } finally {
                    fclose($stream);
                }
            } else {
                // Even a failed prior transaction may already have written the object.
                $disk->delete($key);
            }
            DB::table('product_media')->where('id', $m->id)->update(['published_key' => $allowed ? $key : null]);
        }
    }
}
