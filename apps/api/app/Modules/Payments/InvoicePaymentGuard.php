<?php

namespace App\Modules\Payments;

use Illuminate\Support\Facades\DB;

final class InvoicePaymentGuard
{
    /** Called with the order and invoice locked; allocations are introduced by the payment module. */
    public static function applied(int $invoice): bool
    {
        return DB::scalar("SELECT to_regclass('payment_allocations') IS NOT NULL") && DB::table('payment_allocations')
            ->where('invoice_id', $invoice)->whereNotNull('applied_at')->exists();
    }
}
