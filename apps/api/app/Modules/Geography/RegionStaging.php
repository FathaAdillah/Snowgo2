<?php

namespace App\Modules\Geography;

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;

final class RegionStaging implements FileOwnerPolicy
{
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        return $reference === 'regions' && $this->canView($actor, $staff, $actor->id, $collection) ? $actor->id : null;
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        return $staff && $collection === 'region_import' && $ownerId === $actor->id && $actor->can('geography.region.import');
    }
}
