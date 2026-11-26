<?php

namespace App\Modules\Shipping;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ProviderFreight
{
    public static function withLookup(Closure $op): mixed
    {
        try {
            return $op(CourierApi::enabled());
        } catch (FreightLookup $lookup) {
            if (DB::transactionLevel() !== 0) {
                throw new \LogicException('Cannot request freight inside a transaction.');
            }
            self::refresh($lookup);

            return $op(false);
        }
    }

    public static function rows(): Builder
    {
        return DB::table('provider_freights as f')->join('courier_services as s', 's.id', '=', 'f.service_id')
            ->join('couriers as c', 'c.id', '=', 's.courier_id')
            ->select('f.*', 's.code as service_code', 'c.name as carrier_label', 'c.id as carrier_id');
    }

    /** @return Collection<int,\stdClass> */
    public static function offers(?int $origin, ?int $dest, int $grams, bool $lookup, ?int $selected = null): Collection
    {
        if ((! CourierApi::enabled() && $selected === null) || ! $origin || ! $dest || $grams <= 0) {
            return collect();
        }
        $codes = DB::table('courier_region_codes')->whereIn('region_id', [$origin, $dest])->pluck('code', 'region_id');
        if (! isset($codes[$origin], $codes[$dest])) {
            return collect();
        }
        $q = self::rows()->where(['origin_code' => $codes[$origin], 'destination_code' => $codes[$dest], 'weight_g' => $grams, 's.active' => true, 'c.active' => true])
            ->where('f.expires_at', '>', now())->when($selected !== null, fn ($q) => $q->where('f.id', $selected))->orderBy('f.amount')->orderBy('f.id')->sharedLock()->get();
        if ($q->isEmpty() && $lookup) {
            throw new FreightLookup($codes[$origin], $codes[$dest], $grams);
        }

        return $q;
    }

    private static function refresh(FreightLookup $r): void
    {
        $key = "api-co-id:$r->origin:$r->destination:$r->grams";
        if (Cache::has("$key:recent")) {
            return;
        }
        try {
            Cache::lock("$key:fetch", 20)->block(8, fn () => self::fetch($r, $key));
        } catch (LockTimeoutException) {
            // Recheck the exact route and use the local fallback if the fetch is still pending.
        }
    }

    private static function fetch(FreightLookup $r, string $key): void
    {
        if (Cache::has("$key:recent")) {
            return;
        }
        try {
            $data = CourierApi::get('v2/rates', ['origin_district_code' => $r->origin, 'destination_district_code' => $r->destination, 'weight' => $r->grams / 1000]);
        } catch (CourierFailure $e) {
            logger()->notice('Courier API unavailable; checking manual rate.', ['reason' => $e->getMessage()]);
            Cache::put("$key:recent", true, 30);

            return;
        }
        $quote = $data['quote_id'] ?? null;
        if (! is_string($quote) || ! preg_match('/^[\w.-]{1,160}$/D', $quote) || ! is_array($data['rates'] ?? null) || count($data['rates']) > 1000) {
            Cache::put("$key:recent", true, 30);

            return;
        }
        DB::transaction(function () use ($data, $r, $quote) {
            $until = now()->addMinutes(15);
            $seen = [];
            foreach ($data['rates'] as $rate) {
                if (! is_array($rate) || ! ($fields = self::fields($rate))) {
                    continue;
                }
                $carrier = DB::table('courier_provider_masters as m')->join('couriers as c', 'c.id', '=', 'm.courier_id')
                    ->where(['m.code' => strtolower($rate['courier_code']), 'c.active' => true])->value('c.id');
                $code = strtoupper($rate['service_code']);
                if (! $carrier || isset($seen[$carrier.':'.$code])) {
                    continue;
                }
                $seen[$carrier.':'.$code] = true;
                $mapping = ['courier_code' => strtolower($rate['courier_code']), 'code' => $code];
                $service = DB::table('provider_service_codes')->where($mapping)->value('service_id');
                if (! $service) {
                    DB::table('courier_services')->insertOrIgnore(['courier_id' => $carrier, 'code' => $code, 'name' => $rate['service_name']]);
                    $service = DB::table('courier_services')->where(['courier_id' => $carrier, 'code' => $code])->value('id');
                    DB::table('provider_service_codes')->insertOrIgnore($mapping + ['service_id' => $service]);
                    $service = DB::table('provider_service_codes')->where($mapping)->value('service_id');
                }
                if (DB::table('courier_services')->where(['id' => $service, 'active' => true])->exists()) {
                    DB::table('provider_freights')->insert($fields + ['origin_code' => $r->origin, 'destination_code' => $r->destination,
                        'weight_g' => $r->grams, 'service_id' => $service, 'quote_id' => $quote, 'expires_at' => $until,
                        'provider_details' => json_encode(array_intersect_key($rate, array_flip(['courier_code', 'service_code', 'service_name', 'service_type', 'etd', 'est', 'price', 'handling_fee', 'insurance_fee', 'total_price'])), JSON_THROW_ON_ERROR)]);
                }
            }
        });
        Cache::put("$key:recent", true, 30);
    }

    /** @param array<string,mixed> $r
     * @return array<string,int>|null */
    private static function fields(array $r): ?array
    {
        foreach (['courier_code', 'service_code'] as $k) {
            if (! is_string($r[$k] ?? null) || ! preg_match('/^[a-z0-9_-]{1,32}$/Di', $r[$k])) {
                return null;
            }
        }
        $price = $r['price'] ?? null;
        $fee = $r['handling_fee'] ?? 0;
        $insurance = $r['insurance_fee'] ?? 0;
        foreach ([$price, $fee, $insurance] as $value) {
            if (! is_int($value) || $value < 0 || $value > 1000000000000) {
                return null;
            }
        }
        $amount = $r['total_price'] ?? ($fee === 0 && $insurance === 0 ? $price : null);
        if (! is_int($amount) || $amount <= 0 || $amount > 1000000000000 || ! is_string($r['service_name'] ?? null) || ! trim($r['service_name']) || mb_strlen($r['service_name']) > 120) {
            return null;
        }
        $eta = $r['etd'] ?? null;
        if (! is_string($eta) || ! preg_match('/^(\d{1,3})(?:\s*-\s*(\d{1,3}))?(?:\s*(?:hari|days?))?$/Di', trim($eta), $m)) {
            if (! is_int($r['est'] ?? null) || $r['est'] < 0 || $r['est'] > 999) {
                return null;
            }
            $m = [1 => $r['est']];
        }
        $min = (int) $m[1];
        $max = (int) ($m[2] ?? $min);

        return $max < $min ? null : ['price' => $price, 'handling_fee' => $fee, 'amount' => $amount, 'eta_min_days' => $min, 'eta_max_days' => $max];
    }
}
