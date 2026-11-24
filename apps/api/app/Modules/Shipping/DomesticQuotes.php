<?php

namespace App\Modules\Shipping;

use App\Support\Http\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DomesticQuotes
{
    /** @param array<string,mixed> $address
     * @return list<array<string,mixed>> */
    public function resolve(object $cart, array $address, int $weight, ?string $selection, CarbonImmutable $expires): array
    {
        $saved = null;
        if ($selection !== null) {
            $saved = DB::table('shipping_quotes')->where(['public_id' => $selection, 'user_id' => $cart->user_id])
                ->lockForUpdate()->first() ?? abort(404);
            if ($saved->cart_id !== $cart->id || $saved->used_at !== null) {
                throw $this->changed();
            }
            if (now()->gte($saved->expires_at)) {
                throw new ApiProblem(409, 'QUOTE_EXPIRED', 'The shipping quote expired.');
            }
        }
        $warehouse = DB::table('warehouses')->where(['is_default' => true, 'active' => true])->sharedLock()->first();
        $rates = DB::table('shipping_rates as r')->join('courier_services as s', 's.id', '=', 'r.service_id')->join('couriers as c', 'c.id', '=', 's.courier_id')
            ->where(['r.active' => true, 's.active' => true, 'c.active' => true, 'r.destination_region_id' => $address['district_id']])
            ->where('r.origin_region_id', $warehouse->district_id ?? null)
            ->when($saved, fn ($q) => $q->where('r.id', $saved->rate_id))->orderBy('r.id')->sharedLock()
            ->get(['r.*', 's.code as service_code', 's.name as service_name', 'c.name as carrier_label', 'c.code as carrier_code']);
        $options = [];
        foreach ($rates as $rate) {
            $kg = intdiv($weight + 999, 1000);
            if ($rate->amount_per_kg > intdiv(1_000_000_000_000 - $rate->base_amount, $kg)) {
                continue;
            }
            $amount = $rate->base_amount + $kg * $rate->amount_per_kg;
            $context = hash('sha256', json_encode([$warehouse->id, $cart->id, $cart->version, $address, $weight, $rate], JSON_THROW_ON_ERROR));
            if ($saved && ! hash_equals($saved->context_hash, $context)) {
                throw $this->changed();
            }
            $id = $saved->public_id ?? (string) Str::ulid();
            $until = $saved ? CarbonImmutable::parse($saved->expires_at) : $expires;
            if (! $saved) {
                DB::table('shipping_quotes')->insert(['public_id' => $id, 'user_id' => $cart->user_id, 'cart_id' => $cart->id,
                    'service_id' => $rate->service_id, 'rate_id' => $rate->id, 'context_hash' => $context, 'amount' => $amount,
                    'estimated_days_from' => $rate->eta_min_days, 'estimated_days_to' => $rate->eta_max_days, 'expires_at' => $until]);
            }
            $options[] = ['id' => $id, 'service_code' => $rate->service_code, 'carrier_label' => $rate->carrier_label, 'amount' => $amount,
                'estimated_days_from' => $rate->eta_min_days, 'estimated_days_to' => $rate->eta_max_days, 'expires_at' => $until->toIso8601String()];
        }
        if (! $options) {
            throw $saved ? $this->changed() : new ApiProblem(409, 'NO_SHIPPING_RATE', 'No active domestic rate serves this route.');
        }

        return $options;
    }

    public function consume(string $id): void
    {
        if (! DB::table('shipping_quotes')->where('public_id', $id)->whereNull('used_at')->whereRaw('expires_at > clock_timestamp()')->update(['used_at' => now()])) {
            throw new ApiProblem(409, 'QUOTE_EXPIRED', 'The shipping quote expired.');
        }
    }

    private function changed(): ApiProblem
    {
        return new ApiProblem(409, 'QUOTE_CHANGED', 'Select a current shipping quote.');
    }
}
