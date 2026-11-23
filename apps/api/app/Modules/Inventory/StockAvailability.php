<?php

namespace App\Modules\Inventory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StockAvailability
{
    public static function hasPhysicalStock(int $variantId): bool
    {
        return DB::table('inventory_levels')->where('variant_id', $variantId)->where('on_hand', '>', 0)->exists();
    }

    public static function readyToShip(): Builder
    {
        return DB::table('inventory_levels as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where(['w.active' => true, 'w.is_default' => true])->where('l.available', '>', 0)->select('l.variant_id');
    }
}
