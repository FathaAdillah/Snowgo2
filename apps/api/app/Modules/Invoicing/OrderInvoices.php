<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

final class OrderInvoices
{
    public function voidUnpaid(int $order, ?int $actor): bool
    {
        $invoices = DB::table('invoices')->where('order_id', $order)->orderBy('id')->lockForUpdate()->get();
        if ($invoices->isEmpty() || $invoices->contains(fn ($v) => ! in_array($v->status, ['DRAFT', 'ISSUED', 'VOID'], true))) {
            return false;
        }
        foreach ($invoices as $invoice) {
            if ($invoice->status === 'VOID') {
                continue;
            }
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'VOID']);
            app(Journal::class)->transition(Subject::Invoice, $invoice->id, 'status', $invoice->status, 'VOID', "order-void:$order", $actor);
            app(Journal::class)->audit('InvoiceVoided', Subject::Invoice, $invoice->id, $actor, ['status' => $invoice->status], ['status' => 'VOID']);
            app(Outbox::class)->record('InvoiceVoided', 'invoice', $invoice->id, ['number' => $invoice->number]);
        }

        return true;
    }

    public function settled(int $order): bool
    {
        $states = DB::table('invoices')->where('order_id', $order)->orderBy('id')->lockForUpdate()->pluck('status');

        return $states->contains('PAID') && $states->diff(['PAID', 'VOID'])->isEmpty();
    }
}
