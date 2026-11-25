<?php

namespace App\Modules\Payments;

use App\Models\User;
use App\Modules\Foundation\Files\FileOwnerPolicy;
use Illuminate\Support\Facades\DB;

final class ReconciliationEvidence implements FileOwnerPolicy
{
    /**
     * Create a new class instance.
     */
    public function forUpload(User $actor, bool $staff, string $reference, string $collection): ?int
    {
        if (! $staff || ! ctype_digit($reference) || ! $actor->can(['payments.reconciliation.view', 'payments.payment.recover']) || request()->session()->has(\App\Modules\Identity\Staff\StaffAccess::PASSWORD_ONLY_VERSION) || request()->session()->get('staff.step_up', 0) <= now()->subMinutes(10)->timestamp || $collection !== 'reconciliation_proof') {
            return null;
        }

        return DB::table('payment_reconciliations')->where(['id' => $reference, 'status' => 'APPROVED'])->where('reviewed_by', '!=', $actor->id)->value('id');
    }

    public function canView(User $actor, bool $staff, int $ownerId, string $collection): bool
    {
        return $staff && $collection === 'reconciliation_proof' && $actor->can('payments.reconciliation.view') && DB::table('payment_reconciliations')->where('id', $ownerId)->exists();
    }
}
