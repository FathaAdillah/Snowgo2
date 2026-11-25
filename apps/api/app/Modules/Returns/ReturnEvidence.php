<?php

namespace App\Modules\Returns;

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;
use Illuminate\Support\Facades\DB;

final class ReturnEvidence implements FileOwnerPolicy
{
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        $r = DB::table('returns')->where('number', $reference)->first();
        if (! $r || ! $this->canView($actor, $staff, $r->id, $collection)) {
            return null;
        }
        $intake = in_array($r->status, ['REQUESTED', 'UNDER_REVIEW'], true) && (! $staff || $actor->can('returns.return.review'));
        $inspection = $staff && $actor->can('returns.return.inspect') && in_array($r->status, ['RECEIVED', 'INSPECTING'], true);

        return $intake || $inspection ? $r->id : null;
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        return $collection === 'return_evidence' && (! $staff || $actor->can('returns.return.view'))
            && DB::table('returns as r')->join('orders as o', 'o.id', '=', 'r.order_id')->where('r.id', $ownerId)
                ->when(! $staff, fn ($q) => $q->where('o.user_id', $actor->id))->exists();
    }
}
