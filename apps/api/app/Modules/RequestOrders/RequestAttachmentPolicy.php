<?php

namespace App\Modules\RequestOrders;

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;
use Illuminate\Support\Facades\DB;

final class RequestAttachmentPolicy implements FileOwnerPolicy
{
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        if ($staff || $collection !== 'request_attachment') {
            return null;
        }
        $q = CaseData::scope($actor->id)->where('number', $reference)->whereIn('status', RequestCases::DISCUSS);

        return (DB::transactionLevel() ? $q->sharedLock() : $q)->value('id');
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        return $collection === 'request_attachment' && (! $staff || $actor->can('request_orders.request.view'))
            && CaseData::scope($staff ? null : $actor->id)->where('id', $ownerId)->exists();
    }
}
