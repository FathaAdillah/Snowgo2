<?php

namespace App\Modules\Payments;

use Illuminate\Support\Facades\DB;

final class InvoicePaymentGuard
{
    /** The caller holds the invoice lock; intents spanning other invoices terminate together. */
    public static function voidIntents(int $invoice, ?int $actor): void
    {
        foreach (DB::table('payments')->whereIn('id', DB::table('payment_allocations')->where('invoice_id', $invoice)->select('payment_id'))->where('status', 'UNPAID')->orderBy('id')->lockForUpdate()->get() as $payment) {
            PaymentFlow::change($payment, 'CANCELLED', 'PaymentInvoiceVoided', $actor);
        }
    }

    /** Called with the order and invoice locked; allocations are introduced by the payment module. */
    public static function applied(int $invoice): bool
    {
        return DB::scalar("SELECT to_regclass('payment_allocations') IS NOT NULL") && DB::table('payment_allocations')
            ->where('invoice_id', $invoice)->whereNotNull('applied_at')->exists();
    }
}
