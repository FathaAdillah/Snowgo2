<?php

namespace App\Modules\Preorders;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Invoicing\OrderInvoices;
use App\Modules\Orders\OrderExpiry;
use App\Support\Http\ApiProblem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AllocationLifecycle
{
    public static function recordShortage(int $itemId, int $qty, int $actor, int $reason): void
    {
        $allocation = DB::table('preorder_allocations')->where(['order_item_id' => $itemId, 'status' => 'CONFIRMED'])->lockForUpdate()->firstOrFail();
        $failed = $allocation->failed_qty + $qty;
        $status = $failed === $allocation->qty ? 'FAILED' : 'CONFIRMED';
        DB::table('preorder_allocations')->where('id', $allocation->id)->update(['failed_qty' => $failed, 'status' => $status]);
        if ($status === 'FAILED') {
            app(Journal::class)->transition(Subject::Allocation, $allocation->id, 'status', $allocation->status, $status, 'supplier:FAILED', $actor, $reason);
        }
    }

    /** Settlement calls this in the same transaction as the initial capture. */
    public function confirm(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = DB::table('orders')->where(['id' => $orderId, 'selling_method' => 'PREORDER'])->lockForUpdate()->first() ?? abort(404);
            if (! app(OrderInvoices::class)->checkoutInitialPaid($orderId) || ! in_array($order->status, ['PENDING_PAYMENT', 'CONFIRMED'], true)) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'A paid initial invoice is required.');
            }
            $items = DB::table('order_items')->where('order_id', $orderId)->pluck('id')->all();
            $rows = DB::table('preorder_allocations')->whereIn('order_item_id', $items);
            if ($items && (clone $rows)->where('status', 'CONFIRMED')->count() === count($items)) {
                return;
            }
            if ((clone $rows)->whereRaw('expires_at <= clock_timestamp()')->exists()) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'An expired hold needs payment reconciliation.');
            }
            app(CheckoutAllocations::class)->finishReserved($items, 'CONFIRMED');
            app(Outbox::class)->record('PreorderConfirmed', 'order', $orderId, ['number' => $order->number]);
        });
    }

    private function due(): Builder
    {
        return DB::table('orders as o')->select('o.id')->where(['o.selling_method' => 'PREORDER', 'o.status' => 'PENDING_PAYMENT', 'o.payment_status' => 'UNPAID'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_transition_holds as h')->whereColumn('h.order_id', 'o.id'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('order_items as i')->join('preorder_allocations as a', 'a.order_item_id', '=', 'i.id')
                ->whereColumn('i.order_id', 'o.id')->where('a.status', 'RESERVED')->whereRaw('a.expires_at <= clock_timestamp()'));
    }

    public function expireDue(): int
    {
        $count = 0;
        foreach ($this->due()->lazyById(100, 'o.id', 'id') as $candidate) {
            $count += (int) DB::transaction(function () use ($candidate) {
                $order = DB::table('orders')->where('id', $candidate->id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $order || ! $this->due()->where('o.id', $order->id)->exists() || ! app(OrderExpiry::class)->recordLocked($order)) {
                    return false;
                }
                $items = DB::table('order_items')->where('order_id', $order->id)->pluck('id')->all();
                app(CheckoutAllocations::class)->finishReserved($items, 'EXPIRED');

                return true;
            });
        }

        return $count;
    }
}
