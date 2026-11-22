<?php

namespace App\Modules\Foundation\Files;

use App\Models\User;

interface FileOwnerPolicy
{
    /** Resolve the external reference only when this actor can attach to this parent on this surface. */
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int;

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool;
}
