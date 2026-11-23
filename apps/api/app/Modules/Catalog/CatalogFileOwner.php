<?php

namespace App\Modules\Catalog;

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;
use Illuminate\Support\Facades\DB;

class CatalogFileOwner implements FileOwnerPolicy
{
    protected const TABLE = 'products';

    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        if (! $staff || $collection !== 'catalog_media' || ! $actor->can('catalog.media.create')
            || ! preg_match('/^[1-9][0-9]{0,9}\z/', $reference)) {
            return null;
        }

        return DB::table(static::TABLE)->whereNull('deleted_at')->where('id', $reference)->value('id');
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        return $staff && $collection === 'catalog_media' && $actor->can('catalog.media.view')
            && DB::table(static::TABLE)->whereNull('deleted_at')->where('id', $ownerId)->exists();
    }
}
