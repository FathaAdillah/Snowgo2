<?php

namespace App\Modules\Refunds;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Inventory\StockReservations;
use App\Modules\Payments\InvoicePaymentGuard;
use Illuminate\Support\Facades\DB;

final class RefundOrderCancellation
{
    public static function check(object $refund): void
    {
        $order = DB::table('orders')->where('id', $refund->order_id)->firstOrFail();
        if ($order->status !== 'CONFIRMED'
            || DB::table('order_items')->where('order_id', $order->id)->where('shipped_qty', '>', 0)->exists()
            || DB::table('order_transition_holds')->where(['order_id' => $order->id, 'kind' => 'PROOF_REVIEW'])->exists()
            || DB::table('refunds')->where(['order_id' => $order->id, 'cancel_order' => true])->where('id', '!=', $refund->id)->whereIn('status', ['PROCESSING', 'FAILED'])->exists()) {
            throw Refunds::invalid();
        }
    }

    public static function apply(object $refund, int $actor): void
    {
        $order = DB::table('orders')->where('id', $refund->order_id)->firstOrFail();
        if ($order->status === 'CANCELLED') {
            return;
        }
        $lines = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($order->status !== 'CONFIRMED' || $lines->sum('shipped_qty') || DB::table('order_transition_holds')->where(['order_id' => $order->id, 'kind' => 'PROOF_REVIEW'])->exists()) {
            throw Refunds::invalid();
        }
        $journal = app(Journal::class);
        foreach (DB::table('invoices')->where('order_id', $order->id)->whereIn('status', ['DRAFT', 'ISSUED'])->orderBy('id')->lockForUpdate()->get() as $invoice) {
            InvoicePaymentGuard::voidIntents($invoice->id, $actor);
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'VOID']);
            $journal->transition(Subject::Invoice, $invoice->id, 'status', $invoice->status, 'VOID', 'refund:'.$refund->id, $actor);
            app(Outbox::class)->record('InvoiceVoided', 'invoice', $invoice->id, ['number' => $invoice->number]);
        }
        foreach ($lines as $line) {
            $hold = DB::table('stock_reservations')->where(['order_item_id' => $line->id, 'status' => 'COMMITTED'])->first();
            if ($hold) {
                app(StockReservations::class)->releaseCommitted($order->id, $hold->id, $hold->qty - $hold->released_qty, $line->id);
            }
            $allocation = DB::table('preorder_allocations')->where('order_item_id', $line->id)->whereIn('status', ['CONFIRMED', 'FAILED'])->lockForUpdate()->first();
            if ($allocation) {
                DB::table('preorder_campaign_variants')->where('id', $allocation->campaign_variant_id)->decrement('confirmed_qty', $allocation->qty);
                DB::table('preorder_allocations')->where('id', $allocation->id)->update(['status' => 'CANCELLED']);
                $journal->transition(Subject::Allocation, $allocation->id, 'status', $allocation->status, 'CANCELLED', 'refund:'.$refund->id, $actor);
            }
            DB::table('order_items')->where('id', $line->id)->update(['cancelled_qty' => $line->qty]);
        }
        foreach (DB::table('shipments')->where(['order_id' => $order->id, 'status' => 'PENDING'])->get() as $shipment) {
            DB::table('shipments')->where('id', $shipment->id)->update(['status' => 'CANCELLED']);
            $journal->transition(Subject::Shipment, $shipment->id, 'status', 'PENDING', 'CANCELLED', 'refund:'.$refund->id, $actor);
            app(Outbox::class)->record('ShipmentCancelled', 'shipment', $shipment->id, ['number' => $shipment->number]);
        }
        DB::table('orders')->where('id', $order->id)->update(['status' => 'CANCELLED']);
        $journal->transition(Subject::Order, $order->id, 'status', $order->status, 'CANCELLED', 'refund:'.$refund->id, $actor);
        $journal->audit('OrderCancelled', Subject::Order, $order->id, $actor, ['status' => $order->status], ['status' => 'CANCELLED']);
        app(Outbox::class)->record('OrderCancelled', 'order', $order->id, ['number' => $order->number]);
    }
}
