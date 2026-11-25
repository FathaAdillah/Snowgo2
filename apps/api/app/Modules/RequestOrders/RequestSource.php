<?php

namespace App\Modules\RequestOrders;

use Illuminate\Support\Facades\DB;

final class RequestSource
{
    public static function lock(int|string $reference): object
    {
        return DB::table('request_orders')->where(is_int($reference) ? 'id' : 'number', $reference)->lockForUpdate()->first() ?? abort(404);
    }
}
