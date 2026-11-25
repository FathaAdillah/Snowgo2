<?php

namespace App\Modules\Orders;

use App\Support\Http\ApiProblem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OrderSupply
{
    /** @param array{campaign_id:int}|array{request_order_id:int} $source
     * @param list<string> $refs
     * @return Collection<string,\stdClass> */
    public static function lockLines(array $source, array $refs): Collection
    {
        $requested = DB::table('order_items')->whereIn('public_id', $refs);
        $orders = DB::table('orders')->where($source)->whereIn('id', (clone $requested)->select('order_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items = $requested->whereIn('order_id', $orders->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
        abort_unless($items->count() === count($refs), 404);
        foreach ($orders as $order) {
            $eligible = isset($source['campaign_id'])
                ? $order->selling_method === 'PREORDER' && in_array($order->status, ['PENDING_PAYMENT', 'CONFIRMED'], true)
                : $order->selling_method === 'REQUEST_ORDER' && $order->status === 'CONFIRMED'
                    && DB::table('invoices')->where(['order_id' => $order->id, 'status' => 'PAID'])->whereIn('purpose', ['FULL_PAYMENT', 'DEPOSIT'])->exists();
            if (! $eligible) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Only confirmed supply is eligible.');
            }
        }

        return $items;
    }

    /** Called with the order and item locked by lockLines, in the receipt transaction. */
    public static function receive(object $item, int $received, int $failed, string $receiptRef): void
    {
        DB::table('order_items')->where('id', $item->id)->update(['received_qty' => $item->received_qty + $received, 'cancelled_qty' => $item->cancelled_qty + $failed]);
        if ($failed) {
            DB::table('order_transition_holds')->insert(['order_id' => $item->order_id, 'kind' => 'DISPUTE', 'source_ref' => $receiptRef]);
        }
    }
}
