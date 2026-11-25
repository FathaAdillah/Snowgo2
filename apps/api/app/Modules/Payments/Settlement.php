<?php

namespace App\Modules\Payments;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Inventory\StockReservations;
use App\Modules\Invoicing\OrderInvoices;
use App\Modules\Preorders\AllocationLifecycle;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class Settlement
{
    public static function awaitingRecovery(int $order): bool
    {
        return DB::table('invoices as i')->join('payment_allocations as a', 'a.invoice_id', '=', 'i.id')->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where(['i.order_id' => $order, 'i.status' => 'ISSUED'])->whereIn('p.status', [...PaymentFlow::LIVE, 'EXPIRED'])->whereRaw("p.expires_at + interval '7 days' > clock_timestamp()")->exists();
    }

    public static function closeWindows(): void
    {
        foreach (DB::table('orders as o')->where('status', 'EXPIRED')->whereExists(fn ($q) => $q->from('invoices')->whereColumn('order_id', 'o.id')->where('status', 'ISSUED'))->orderBy('id')->lazyById(100) as $order) {
            DB::transaction(function () use ($order) {
                if (DB::table('orders')->where('id', $order->id)->lockForUpdate()->value('status') === 'EXPIRED' && ! self::awaitingRecovery($order->id)) {
                    app(OrderInvoices::class)->voidUnpaid($order->id, null);
                }
            });
        }
    }

    public static function conflict(): ApiProblem
    {
        return new ApiProblem(409, 'TRANSFER_CONFLICT', 'Transfer cannot be captured. Review the bank receipt and reconciliation queue.');
    }

    /** Serialize by statement reference even if the same physical destination has been re-registered. */
    public static function guardReceipt(object $payment, string $ref, ?int $except = null): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['receipt:'.$ref]);
        $prior = DB::table('payments')->where('bank_tx_ref', $ref)->select('id')->union(DB::table('payment_reconciliations')->where('bank_tx_ref', $ref)->where('status', '!=', 'REJECTED')->when($except, fn ($q) => $q->where('id', '!=', $except))->select('payment_id'));
        $destination = self::destination($payment);
        foreach (DB::table('payments')->whereIn('id', $prior)->get() as $used) {
            if ($used->bank_account_id === $payment->bank_account_id || self::destination($used) === $destination) {
                throw self::conflict();
            }
        }
    }

    /** @return array{string,string} */
    private static function destination(object $payment): array
    {
        $snapshot = json_decode(Crypt::decryptString($payment->instruction_snapshot_ciphertext), true, flags: JSON_THROW_ON_ERROR);

        return [mb_strtoupper($snapshot['bank_code']), $snapshot['account_number']];
    }

    public static function capture(string $number, int $actor, string $reference, int $amount, ?object $recovery = null): void
    {
        [$p,$orders] = PaymentFlow::lock($number, null);
        $reference = mb_strtoupper(trim($reference));
        if (! $reference || $amount !== $p->amount) {
            throw self::conflict();
        }
        if ($recovery === null) {
            PaymentFlow::requireClaim($p, $actor);
            if (! DB::table('payment_proofs as pf')->join('stored_files as f', 'f.id', '=', 'pf.file_id')->where(['pf.payment_id' => $p->id, 'f.owner_type' => 'payment', 'f.owner_id' => $p->id, 'f.collection' => 'payment_proof', 'f.status' => 'READY', 'f.visibility' => 'PRIVATE'])->exists()) {
                throw PaymentFlow::invalid();
            }
        } elseif ($recovery->payment_id !== $p->id || $recovery->status !== 'APPROVED' || $recovery->reviewed_by === $actor || $p->status !== 'EXPIRED'
            || DB::scalar("SELECT ?::timestamptz + interval '7 days' <= clock_timestamp()", [$p->expires_at])) {
            throw self::conflict();
        }
        $rows = DB::table('payment_allocations as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')->where('a.payment_id', $p->id)
            ->select('a.id', 'a.amount', 'a.applied_at', 'i.id as invoice_id', 'i.status', 'i.total_amount', 'i.purpose', 'i.order_id', 'i.number')->orderBy('i.id')->get();
        if ($rows->sum('amount') !== $amount || $rows->contains(fn ($r) => $r->applied_at !== null || $r->status !== 'ISSUED' || $r->amount !== $r->total_amount)
            || DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->whereIn('a.invoice_id', $rows->pluck('invoice_id'))->where('p.id', '!=', $p->id)->whereIn('p.status', PaymentFlow::LIVE)->exists()) {
            throw self::conflict();
        }
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->select('id');
        DB::table('preorder_campaign_variants')->whereIn('id', DB::table('preorder_allocations')->whereIn('order_item_id', $items)->select('campaign_variant_id'))->orderBy('id')->lockForUpdate()->get('id');
        DB::table('inventory_levels')->whereIn('id', DB::table('stock_reservations')->whereIn('order_item_id', $items)->select('level_id'))->orderBy('id')->lockForUpdate()->get('id');
        DB::table('bank_accounts')->where('id', $p->bank_account_id)->lockForUpdate()->firstOrFail();
        self::guardReceipt($p, $reference, $recovery?->id);
        foreach ($rows as $r) {
            DB::table('payment_allocations')->where('id', $r->id)->update(['applied_at' => now()]);
            DB::table('invoices')->where('id', $r->invoice_id)->update(['status' => 'PAID', 'paid_at' => now()]);
            app(Journal::class)->transition(Subject::Invoice, $r->invoice_id, 'status', 'ISSUED', 'PAID', "capture:$p->id", $actor);
            app(Journal::class)->audit('InvoicePaid', Subject::Invoice, $r->invoice_id, $actor, ['status' => 'ISSUED'], ['status' => 'PAID', 'amount_idr' => $r->amount]);
            app(Outbox::class)->record('InvoicePaid', 'invoice', $r->invoice_id, ['number' => $r->number]);
        }
        foreach ($orders as $order) {
            if ($order->user_id !== $p->user_id || ! in_array($order->status, $recovery ? ['EXPIRED', 'PENDING_PAYMENT', 'CONFIRMED'] : ['PENDING_PAYMENT', 'CONFIRMED'], true)) {
                throw self::conflict();
            }
            if ($order->status !== 'CONFIRMED') {
                $initial = $rows->where('order_id', $order->id)->whereIn('purpose', ['FULL_PAYMENT', 'DEPOSIT'])->isNotEmpty();
                if (! $initial || ($order->selling_method !== 'REQUEST_ORDER' && ! app(OrderInvoices::class)->checkoutInitialPaid($order->id))) {
                    throw self::conflict();
                }
                if ($order->selling_method === 'READY_TO_SHIP') {
                    if ($recovery) {
                        app(StockReservations::class)->recover($order->id, $p->id);
                    }
                    app(StockReservations::class)->commit($order->id, $p->id);
                }
                if ($order->selling_method === 'PREORDER' && $recovery) {
                    app(AllocationLifecycle::class)->recover($order->id, $p->user_id);
                }
                DB::table('orders')->where('id', $order->id)->update(['status' => 'CONFIRMED', 'fulfillment_status' => $order->selling_method === 'READY_TO_SHIP' ? 'READY_TO_PACK' : 'AWAITING_STOCK']);
                if ($order->selling_method === 'PREORDER') {
                    app(AllocationLifecycle::class)->confirm($order->id);
                }
                app(Journal::class)->transition(Subject::Order, $order->id, 'status', $order->status, 'CONFIRMED', "capture:$p->id", $actor);
            }
            $paymentStatus = app(OrderInvoices::class)->fullyPaid($order) ? 'PAID' : 'PARTIALLY_PAID';
            DB::table('orders')->where('id', $order->id)->update(['payment_status' => $paymentStatus]);
            if ($order->payment_status !== $paymentStatus) {
                app(Journal::class)->transition(Subject::Order, $order->id, 'payment_status', $order->payment_status, $paymentStatus, "capture:$p->id", $actor);
            }
            app(Journal::class)->audit('OrderPaid', Subject::Order, $order->id, $actor, ['status' => $order->payment_status], ['status' => $paymentStatus]);
            app(Outbox::class)->record('OrderPaid', 'order', $order->id, ['number' => $order->number, 'payment_number' => $p->number]);
        }
        PaymentFlow::change($p, 'PAID', $recovery ? 'PaymentRecovered' : 'PaymentApproved', $actor, ['received_amount' => $amount, 'bank_tx_ref' => $reference, 'reconciliation_id' => $recovery?->id, 'verified_at' => now(), 'verified_by' => $actor, 'claim_by' => null, 'claim_until' => null]);
        PaymentFlow::clearHolds($p);
    }
}
