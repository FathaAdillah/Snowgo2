<?php

namespace App\Modules\Orders;

use App\Support\Http\ApiProblem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StockOwner
{
    public static function lock(int $id): object
    {
        $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
        if ($order->selling_method !== 'READY_TO_SHIP') {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Only ready-to-ship orders own stock.');
        }

        return $order;
    }

    public static function lines(): Builder
    {
        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->select('i.id', 'i.order_id', 'i.variant_id', 'i.sku_snapshot', 'i.qty', 'o.number');
    }
}
