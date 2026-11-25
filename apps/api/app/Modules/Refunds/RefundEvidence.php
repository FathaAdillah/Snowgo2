<?php

namespace App\Modules\Refunds;

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;
use Illuminate\Support\Facades\DB;

final class RefundEvidence implements FileOwnerPolicy
{
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        $r = DB::table('refunds')->where('number', $reference)->first();
        if (! $r || ! $this->canView($actor, $staff, $r->id, $collection)) {
            return null;
        }
        if ($collection === 'refund_transfer_proof') {
            return $actor->can('refunds.refund.execute') && $r->status === 'PROCESSING' && $r->executor_id === $actor->id ? $r->id : null;
        }

        return (! $staff || $actor->can('refunds.refund.review')) && in_array($r->status, ['REQUESTED', 'UNDER_REVIEW'], true) ? $r->id : null;
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        if (! in_array($collection, ['refund_evidence', 'refund_transfer_proof'], true) || (! $staff && $collection === 'refund_transfer_proof')) {
            return false;
        }

        return (! $staff || $actor->can('refunds.refund.view')) && DB::table('refunds as r')->join('orders as o', 'o.id', '=', 'r.order_id')->where('r.id', $ownerId)
            ->when(! $staff, fn ($q) => $q->where('o.user_id', $actor->id))->exists();
    }
}
