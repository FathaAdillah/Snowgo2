<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Payments\InvoicePaymentGuard;
use Illuminate\Support\Facades\DB;

final class OrderInvoices
{
    /** The caller holds the order lock; payment of an unrelated follow-up must not confirm stock. */
    public function checkoutInitialPaid(int $order): bool
    {
        return DB::scalar(<<<'SQL'
            WITH RECURSIVE revisions AS (
                SELECT id, order_id, purpose, status FROM invoices WHERE order_id=? AND source_ref='checkout:initial'
                UNION
                SELECT i.id, i.order_id, i.purpose, i.status FROM invoices i JOIN revisions r
                    ON i.replaces_invoice_id=r.id AND i.order_id=r.order_id AND i.purpose=r.purpose
            )
            SELECT EXISTS(SELECT FROM revisions WHERE status='PAID')
            SQL, [$order]);
    }

    public function voidUnpaid(int $order, ?int $actor): bool
    {
        $invoices = DB::table('invoices')->where('order_id', $order)->orderBy('id')->lockForUpdate()->get();
        if ($invoices->isEmpty() || $invoices->contains(fn ($v) => ! in_array($v->status, ['DRAFT', 'ISSUED', 'VOID'], true) || InvoicePaymentGuard::applied($v->id))) {
            return false;
        }
        foreach ($invoices as $invoice) {
            if ($invoice->status === 'VOID') {
                continue;
            }
            InvoicePaymentGuard::voidIntents($invoice->id, $actor);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'VOID']);
            app(Journal::class)->transition(Subject::Invoice, $invoice->id, 'status', $invoice->status, 'VOID', "order-void:$order", $actor);
            app(Journal::class)->audit('InvoiceVoided', Subject::Invoice, $invoice->id, $actor, ['status' => $invoice->status], ['status' => 'VOID']);
            app(Outbox::class)->record('InvoiceVoided', 'invoice', $invoice->id, ['number' => $invoice->number]);
        }

        return true;
    }

    /** Called under the order lock. Unreceived, non-cancelled units still owe their balance. */
    public function fullyPaid(object $order): bool
    {
        if (! $this->settled($order->id)) {
            return false;
        }
        if ($order->term !== 'DEPOSIT' || ! DB::table('order_items')->where('order_id', $order->id)->where('cancelled_qty', '>', 0)->exists()) {
            return DB::table('invoices')->where(['order_id' => $order->id, 'status' => 'PAID'])->where('purpose', '!=', 'ADDITIONAL_CHARGE')->sum('total_amount') >= $order->total_amount;
        }

        return ! DB::table('order_items as i')->where(['i.order_id' => $order->id, 'i.term' => 'DEPOSIT'])->whereRaw("i.qty - i.cancelled_qty > coalesce((select sum(l.covered_qty) from invoice_items l join invoices v on v.id=l.invoice_id where l.order_item_id=i.id and v.status='PAID' and v.purpose='BALANCE'),0)")->exists();
    }

    public function settled(int $order): bool
    {
        $states = DB::table('invoices')->where('order_id', $order)->orderBy('id')->lockForUpdate()->pluck('status');

        return $states->contains('PAID') && $states->diff(['PAID', 'VOID'])->isEmpty();
    }
}
