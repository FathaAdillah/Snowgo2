<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Inventory\StockReservations;
use App\Modules\Payments\InvoicePaymentGuard;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceLifecycle
{
    /** Run inside the idempotent mutation. Lock order before invoice just as settlement does.
     * @param array<string,mixed> $input */
    public static function apply(string $action, string $number, int $actor, array $input): string
    {
        $orders = DB::table('orders');
        if ($action === 'issue') {
            $orders->where('number', $number);
        } else {
            $orders->where('id', DB::table('invoices')->select('order_id')->where('number', $number));
        }
        $order = $orders->lockForUpdate()->first() ?? abort(404);
        if ($action === 'issue' && $input['purpose'] === 'BALANCE' && ! isset($input['replaces_invoice_number'])) {
            if (DB::table('order_transition_holds')->where('order_id', $order->id)->exists() || app(StockReservations::class)->underProofReview($order->id)) {
                throw new ApiProblem(409, 'TRANSFER_CONFLICT', 'Resolve the outstanding review first.');
            }
            return BalanceInvoices::issue($order, $actor, $input['due_at']);
        }
        if ($action === 'issue' && ! isset($input['replaces_invoice_number'])) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'A previously approved invoice source is required.');
        }
        $invoice = DB::table('invoices')->where(['order_id' => $order->id,
            'number' => $input['replaces_invoice_number'] ?? $number])->lockForUpdate()->first() ?? abort(404);
        if (! in_array($order->status, ['PENDING_PAYMENT', 'CONFIRMED'], true) || InvoicePaymentGuard::applied($invoice->id)) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'The order or invoice is no longer eligible.');
        }
        if (DB::table('order_transition_holds')->where('order_id', $order->id)->exists() || app(StockReservations::class)->underProofReview($order->id)) {
            throw new ApiProblem(409, 'TRANSFER_CONFLICT', 'Resolve the outstanding review first.');
        }
        $reason = null;
        if (isset($input['reason_code'])) {
            $reason = DB::table('reason_codes')->where(['domain' => 'invoicing', 'active' => true, 'code' => $input['reason_code']])->sharedLock()->value('id');
            if ($reason === null) {
                throw ValidationException::withMessages(['reason_code' => 'An active invoicing reason is required.']);
            }
        }
        if ($action === 'issue') {
            if ($invoice->purpose === 'BALANCE') {
                if ($input['purpose'] !== 'BALANCE' || $invoice->status !== 'VOID' || DB::table('invoices')->where('replaces_invoice_id', $invoice->id)->exists()) {
                    throw BalanceInvoices::invalid();
                }

                return BalanceInvoices::issue($order, $actor, $input['due_at'], $invoice);
            }
            if ($invoice->purpose !== $input['purpose'] || $invoice->status !== 'VOID' || DB::table('invoices')->where('replaces_invoice_id', $invoice->id)->exists()) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Only an unreplaced void invoice of the same purpose can be issued again.');
            }
        } else {
            if ($invoice->status !== 'ISSUED' || ($action === 'revise_due' && ($order->status !== 'CONFIRMED' || now()->lt($invoice->due_at)))) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Invoice is not eligible for this change.');
            }
            InvoicePaymentGuard::voidIntents($invoice->id, $actor);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'VOID']);
            $journal = app(Journal::class);
            $journal->transition(Subject::Invoice, $invoice->id, 'status', 'ISSUED', 'VOID', "invoice-void:$invoice->id", $actor, $reason);
            $journal->audit('InvoiceVoided', Subject::Invoice, $invoice->id, $actor, ['status' => 'ISSUED'], ['status' => 'VOID']);
            app(Outbox::class)->record('InvoiceVoided', 'invoice', $invoice->id, ['number' => $invoice->number]);
        }
        if ($action === 'void') {
            if ($order->payment_status === 'PARTIALLY_PAID' && app(OrderInvoices::class)->fullyPaid($order)) {
                DB::table('orders')->where('id', $order->id)->update(['payment_status'=>'PAID']);
                app(Journal::class)->transition(Subject::Order,$order->id,'payment_status','PARTIALLY_PAID','PAID','void:'.$invoice->number,$actor);
            }
            return $invoice->number;
        }
        $due = Carbon::parse($input['due_at']);
        if ($due->lte(now()) || ($order->status === 'PENDING_PAYMENT' && $due->gt($order->payment_due_at))) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'The new deadline must be in the future and cannot extend an initial payment hold.');
        }
        $lines = DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')
            ->get(['order_item_id', 'line_type', 'description', 'qty', 'unit_amount', 'amount', 'covered_qty', 'coverage_start'])->map(fn ($row) => (array) $row)->values()->all();

        return app(InvoiceIssuer::class)->issue($order->id, $actor, 'revision:'.$invoice->number, $invoice->purpose, $due->toIso8601String(), [
            'currency' => $invoice->currency, 'subtotal_amount' => $invoice->subtotal_amount, 'shipping_amount' => $invoice->shipping_amount,
            'discount_amount' => $invoice->discount_amount, 'tax_amount' => $invoice->tax_amount, 'total_amount' => $invoice->total_amount,
        ], $lines, $invoice->id);
    }
}
