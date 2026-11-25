<?php

namespace App\Modules\Orders;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Invoicing\OrderInvoices;
use App\Modules\Payments\Settlement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OrderExpiry
{
    /** Called with the order lock and verified evidence that its hold or payment deadline expired. */
    public function recordLocked(object $order): bool
    {
        if ($order->status !== 'PENDING_PAYMENT' || $order->payment_status !== 'UNPAID'
            || (($order->checkout_id !== null || $order->request_order_id !== null) && ! Settlement::awaitingRecovery($order->id) && ! app(OrderInvoices::class)->voidUnpaid($order->id, null))) {
            return false;
        }
        DB::table('orders')->where('id', $order->id)->update(['status' => 'EXPIRED']);
        $journal = app(Journal::class);
        $supply = match ($order->selling_method) {
            'PREORDER' => 'quota',
            'REQUEST_ORDER' => 'payment',
            default => 'stock',
        };
        $journal->transition(Subject::Order, $order->id, 'status', 'PENDING_PAYMENT', 'EXPIRED', "$supply-expired:$order->id");
        $journal->audit('OrderExpired', Subject::Order, $order->id, null, ['status' => 'PENDING_PAYMENT'], ['status' => 'EXPIRED']);
        app(Outbox::class)->record('OrderExpired', 'order', $order->id, ['number' => $order->number]);

        return true;
    }

    public function expireRequestOrders(): void
    {
        $due = DB::table('orders as o')->whereNotNull('o.request_order_id')
            ->where(['o.status' => 'PENDING_PAYMENT', 'o.payment_status' => 'UNPAID'])
            ->whereRaw('coalesce((select expires_at from payment_hold_extensions where order_id=o.id),o.payment_due_at)<=clock_timestamp()')
            ->whereNotExists(fn ($q) => $q->from('order_transition_holds')->whereColumn('order_id', 'o.id'));
        foreach ((clone $due)->select('o.id')->lazyById(100) as $candidate) {
            DB::transaction(function () use ($due, $candidate) {
                $order = DB::table('orders')->where('id', $candidate->id)->lockForUpdate()->firstOrFail();
                if ((clone $due)->where('o.id', $order->id)->exists()) {
                    $this->recordLocked($order);
                }
            });
        }
    }

    public function reconcileReleased(): int
    {
        $count = 0;
        foreach ($this->releasedCheckouts()->lazyById(100) as $candidate) {
            $count += (int) DB::transaction(function () use ($candidate) {
                $order = StockOwner::lock($candidate->id);

                return $this->releasedCheckouts()->where('o.id', $order->id)->exists() && $this->recordLocked($order);
            });
        }

        return $count;
    }

    private function releasedCheckouts(): Builder
    {
        return DB::table('orders as o')->select('o.id')->whereNotNull('o.checkout_id')
            ->where(['o.selling_method' => 'READY_TO_SHIP', 'o.status' => 'PENDING_PAYMENT', 'o.payment_status' => 'UNPAID'])
            ->whereRaw('EXISTS (SELECT 1 FROM order_items i WHERE i.order_id=o.id)')
            ->whereRaw("NOT EXISTS (SELECT 1 FROM order_items i LEFT JOIN stock_reservations r ON r.order_item_id=i.id
                WHERE i.order_id=o.id GROUP BY i.id HAVING count(r.id)=0 OR NOT bool_and(r.status='EXPIRED') OR sum(r.released_qty)<>i.qty)");
    }
}
