<?php

namespace App\Modules\Shipping;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PackageRates
{
    /** @return Collection<int,\stdClass> */
    public static function available(object $order, object $warehouse, int $grams, bool $lookup): Collection
    {
        $dest = DB::table('order_addresses')->where('order_id', $order->id)->value('district_id');
        $rates = ProviderFreight::offers($warehouse->district_id, $dest, $grams, $lookup);
        $source = 'API_CO_ID';
        if ($rates->isEmpty()) {
            $source = 'MANUAL';
            $kg = intdiv($grams + 999, 1000);
            $rates = DB::table('shipping_rates as r')->join('courier_services as s', 's.id', '=', 'r.service_id')->join('couriers as c', 'c.id', '=', 's.courier_id')
                ->where(['r.origin_region_id' => $warehouse->district_id, 'r.destination_region_id' => $dest, 'r.active' => true, 's.active' => true, 'c.active' => true])
                ->whereRaw('amount_per_kg <= (1000000000000 - base_amount) / ?', [$kg])->orderBy('r.id')->sharedLock()
                ->select(['r.*', 's.code as service_code', 'c.name as carrier_label', 'c.id as carrier_id'])
                ->selectRaw('base_amount + amount_per_kg * ? as amount', [$kg])->get();
        }

        return $rates->each(function ($rate) use ($order, $warehouse, $dest, $grams, $source) {
            $rate->source = $source;
            $rate->rate_key = hash('sha256', json_encode([$order->id, $warehouse->id, $warehouse->district_id, $dest, $grams, $rate], JSON_THROW_ON_ERROR));
        });
    }
}
