<?php

namespace App\Modules\Orders;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Inventory\StockReservations;
use App\Modules\Invoicing\OrderInvoices;
use App\Modules\Preorders\CheckoutAllocations;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderLifecycle
{
    /** @param array<string,string> $input */
    public function apply(string $number, ?int $owner, int $actor, string $action, array $input): void
    {
        DB::transaction(function () use ($number, $owner, $actor, $action, $input) {
            $order = OrderDirectory::scope($owner)->where('number', $number)->lockForUpdate()->first() ?? abort(404);
            $id = $order->id;
            if (DB::table('order_transition_holds')->where('order_id', $id)->exists()) {
                throw $this->invalid();
            }
            $lines = DB::table('order_items')->where('order_id', $id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw $this->invalid();
            }
            $reason = isset($input['reason_code']) ? DB::table('reason_codes')->where(['domain' => 'orders', 'code' => $input['reason_code'], 'active' => true])
                ->sharedLock()->value('id') ?? throw ValidationException::withMessages(['reason_code' => 'Select an active order reason.']) : null;
            $invoices = app(OrderInvoices::class);
            if ($action === 'CANCEL') {
                if ($order->status !== 'PENDING_PAYMENT' || $order->payment_status !== 'UNPAID' || $order->fulfillment_status !== 'UNFULFILLED'
                    || $lines->contains(fn ($line) => $line->shipped_qty > 0) || app(StockReservations::class)->underProofReview($id)) {
                    throw $this->invalid();
                }
                $invoices->voidUnpaid($id, $actor);
                if ($order->selling_method === 'READY_TO_SHIP') {
                    app(StockReservations::class)->release($id);
                } elseif ($order->selling_method === 'PREORDER') {
                    app(CheckoutAllocations::class)->release($lines->pluck('id')->all());
                }
                DB::table('order_items')->where('order_id', $id)->update(['cancelled_qty' => DB::raw('qty')]);
                $status = 'CANCELLED';
                $event = 'OrderCancelled';
            } elseif ($action === 'COMPLETE') {
                if ($order->status !== 'CONFIRMED' || ! in_array($order->payment_status, ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)
                    || $order->fulfillment_status !== 'DELIVERED' || ! $lines->sum('delivered_qty')
                    || $lines->contains(fn ($line) => $line->delivered_qty !== $line->qty - $line->cancelled_qty) || ! $invoices->settled($id)) {
                    throw $this->invalid();
                }
                $status = 'COMPLETED';
                $event = 'OrderCompleted';
            } else {
                throw $this->invalid();
            }
            DB::table('orders')->where('id', $id)->update(['status' => $status] + ($action === 'COMPLETE' ? ['completed_at' => now()] : []));
            DB::table('order_action_records')->insert(['order_id' => $id, 'action' => $action, 'note' => $input['note'] ?? null, 'reason_code' => $input['reason_code'] ?? null]);
            app(Journal::class)->transition(Subject::Order, $id, 'status', $order->status, $status, "order:$action", $actor, $reason);
            app(Journal::class)->audit($event, Subject::Order, $id, $actor, ['status' => $order->status], ['status' => $status]);
            app(Outbox::class)->record($event, 'order', $id, ['number' => $number]);
        });
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Order cannot make this transition. Captured funds require refund review.');
    }
}
