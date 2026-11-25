<?php

namespace App\Modules\Refunds;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Files\StoredFiles;
use App\Modules\Invoicing\OrderInvoices;
use App\Modules\Payments\PaymentFlow;
use App\Modules\Payments\Settlement;
use Illuminate\Support\Facades\DB;

final class RefundExecution
{
    /** @param array<string,mixed> $d */
    public static function attempt(object $r, int $actor, array $d): void
    {
        abort_if($actor === $r->reviewed_by, 403);
        $action = $d['action'];
        if (($action === 'START' && $r->status === 'APPROVED') || ($action === 'RETRY' && $r->status === 'FAILED')) {
            if (! ($d['bank_checked'] ?? false)) {
                throw Settlement::conflict();
            }
            if ($r->cancel_order) {
                RefundOrderCancellation::check($r);
            }
            Refunds::change($r, 'PROCESSING', $actor, 'RefundProcessing', ['executor_id' => $actor]);

            return;
        }
        if ($action !== 'FAIL' || $r->status !== 'PROCESSING' || $r->executor_id !== $actor) {
            throw Refunds::invalid();
        }
        Refunds::change($r, 'FAILED', $actor, 'RefundFailed', ['decision_reason_id' => Refunds::reason($d['reason_code'])]);
    }

    /** @param array<string,mixed> $d */
    public static function transfer(object $r, int $actor, array $d): void
    {
        abort_if($actor === $r->reviewed_by || $actor !== $r->executor_id, 403);
        $reference = mb_strtoupper(trim($d['transfer_ref']));
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ["return:$reference"]);
        $prior = DB::table('refund_transfers')->where('transfer_ref', $reference)->first();
        if ($prior || $r->status !== 'PROCESSING' || $reference === '' || DB::table('payment_reconciliations')->where('refund_transfer_ref', $reference)->exists()) {
            throw Settlement::conflict();
        }
        $proof = app(StoredFiles::class)->requireReady($d['proof_file_id'], 'refund', $r->id, 'refund_transfer_proof');
        if ($proof->uploaded_by !== $actor || $proof->visibility !== 'PRIVATE' || array_sum(array_column($d['allocations'], 'amount')) !== $r->approved_amount) {
            throw Settlement::conflict();
        }
        $transfer = DB::table('refund_transfers')->insertGetId(['refund_id' => $r->id, 'transfer_ref' => $reference, 'confirmed_by' => $actor, 'proof_file_id' => $proof->id, 'transferred_at' => $d['transferred_at']]);
        $payments = DB::table('payments')->whereIn('number', array_column($d['allocations'], 'payment_number'))->orderBy('id')->lockForUpdate()->get()->keyBy('number');
        $seen = [];
        foreach ($d['allocations'] as $row) {
            $payment = $payments->get($row['payment_number']) ?? throw Settlement::conflict();
            $allocation = Refunds::captures($r->order_id)->where(['a.payment_id' => $payment->id, 'i.number' => $row['invoice_number']])->first(['a.id', 'a.amount']) ?? throw Settlement::conflict();
            $used = DB::table('refund_transactions')->where('payment_allocation_id', $allocation->id)->sum('amount');
            if (isset($seen[$allocation->id]) || $used + $row['amount'] > $allocation->amount) {
                throw Refunds::limit();
            }
            $seen[$allocation->id] = true;
            DB::table('refund_transactions')->insert(['transfer_id' => $transfer, 'payment_allocation_id' => $allocation->id, 'amount' => $row['amount']]);
        }
        foreach ($payments as $payment) {
            $total = (int) DB::table('refund_transactions as t')->join('payment_allocations as a', 'a.id', '=', 't.payment_allocation_id')->where('a.payment_id', $payment->id)->sum('t.amount');
            $state = $total === $payment->amount ? 'REFUNDED' : 'PARTIALLY_REFUNDED';
            if ($payment->status !== $state) {
                PaymentFlow::change($payment, $state, 'PaymentRefunded', $actor);
            }
        }
        if ($r->cancel_order) {
            RefundOrderCancellation::apply($r, $actor);
        }
        $o = DB::table('orders')->where('id', $r->order_id)->firstOrFail();
        $returned = DB::table('refund_transfers as t')->join('refunds as r', 'r.id', '=', 't.refund_id')->where('r.order_id', $r->order_id)->sum('r.approved_amount');
        $status = ($o->status === 'CANCELLED' || app(OrderInvoices::class)->fullyPaid($o))
            ? ($returned === Refunds::captures($r->order_id)->sum('a.amount') ? 'REFUNDED' : 'PARTIALLY_REFUNDED') : 'PARTIALLY_PAID';
        if ($o->payment_status !== $status) {
            DB::table('orders')->where('id', $o->id)->update(['payment_status' => $status]);
            app(Journal::class)->transition(Subject::Order, $o->id, 'payment_status', $o->payment_status, $status, 'refund:'.$r->id, $actor);
        }
        Refunds::change($r, 'COMPLETED', $actor, 'RefundCompleted', ['completed_at' => now()]);
    }

    /** @param array<string,mixed> $d */
    public static function cancel(object $r, int $actor, array $d): void
    {
        if (! in_array($r->status, ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'FAILED'], true)
            || (in_array($r->status, ['FAILED', 'APPROVED'], true) && (! ($d['bank_checked'] ?? false) || $actor === $r->executor_id))) {
            throw Settlement::conflict();
        }
        Refunds::change($r, 'CANCELLED', $actor, 'RefundCancelled', ['decision_reason_id' => Refunds::reason($d['reason_code']), 'decision_note' => $d['note']]);
    }
}
