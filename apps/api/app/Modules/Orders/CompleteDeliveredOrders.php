<?php

namespace App\Modules\Orders;

use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

final class CompleteDeliveredOrders
{
    public function run(): void
    {
        $hours = max(1, config('aftersales.return_window_hours'), config('aftersales.completion_delay_hours'));
        $orders = DB::table('orders as o')->where(['o.status' => 'CONFIRMED', 'o.fulfillment_status' => 'DELIVERED'])
            ->whereRaw('(SELECT max(delivered_at) FROM shipments WHERE order_id=o.id) <= ?', [now()->subHours($hours)]);
        foreach ($orders->select('o.id', 'o.number')->lazyById(100) as $candidate) {
            DB::transaction(function () use ($candidate, $orders) {
                DB::table('orders')->where('id', $candidate->id)->lockForUpdate()->firstOrFail();
                if ((clone $orders)->where('o.id', $candidate->id)->exists()) {
                    try {
                        app(OrderLifecycle::class)->apply($candidate->number, null, null, 'COMPLETE', []);
                    } catch (ApiProblem $e) {
                        if ($e->errorCode !== 'INVALID_STATE_TRANSITION') {
                            throw $e;
                        }
                    }
                }
            });
        }
    }
}
