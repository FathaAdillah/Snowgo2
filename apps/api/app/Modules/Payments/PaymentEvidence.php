<?php

namespace App\Modules\Payments;

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;
use Illuminate\Support\Facades\DB;

final class PaymentEvidence implements FileOwnerPolicy
{
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        if ($staff || $collection !== 'payment_proof') {
            return null;
        }
        $query = DB::table('payments')->where(['number' => $reference, 'user_id' => $actor->id])->whereIn('status', ['UNPAID', 'WAITING_CONFIRMATION'])->whereRaw('expires_at > clock_timestamp()');

        return (DB::transactionLevel() ? $query->sharedLock() : $query)->value('id');
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        return $collection === 'payment_proof' && (! $staff || $actor->can(['payments.payment.view', 'payments.proof.view']))
            && DB::table('payments')->where('id', $ownerId)->when(! $staff, fn ($q) => $q->where('user_id', $actor->id))->exists();
    }
}
