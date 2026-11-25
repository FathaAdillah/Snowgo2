<?php

namespace App\Modules\Checkout;

use App\Modules\Catalog\CheckoutProducts;
use App\Modules\Inventory\StockAvailability;
use App\Modules\Preorders\CheckoutAllocations;
use App\Support\Http\ApiProblem;
use Illuminate\Validation\ValidationException;

final class GroupPricing
{
    public const MAX = 1_000_000_000_000;

    public static function bounded(int $amount): int
    {
        if ($amount > self::MAX) {
            throw ValidationException::withMessages(['amount' => 'Checkout exceeds the IDR settlement limit.']);
        }

        return $amount;
    }

    /** @return array<string,array<string,mixed>> */
    public function groups(object $cart, bool $submit): array
    {
        $variants = app(CheckoutProducts::class)->snapshots($cart->items->pluck('variant_id'));
        $offers = app(CheckoutAllocations::class)->offers($cart->items->pluck('campaign_variant_id')->filter(), $cart->user_id, $submit);
        $available = StockAvailability::readyToShip()->whereIn('l.variant_id', $variants->keys())->pluck('l.available', 'l.variant_id');
        $groups = [];
        foreach ($cart->items as $i) {
            $v = $variants->get($i->variant_id);
            $po = $offers->get($i->campaign_variant_id);
            if (! $v || ! $this->eligible($i, $v, $po)) {
                throw new ApiProblem(409, $submit ? 'QUOTE_CHANGED' : 'OFFER_UNAVAILABLE', 'An offer is no longer eligible.');
            }
            if ($i->qty > ($po->remaining ?? ($available[$v->id] ?? 0))) {
                throw new ApiProblem(409, ! $submit ? 'OFFER_UNAVAILABLE' : ($po ? 'QUOTA_FULL' : 'OUT_OF_STOCK'), 'Insufficient availability.');
            }
            $key = $po ? "po:$po->campaign_id" : 'rts';
            $g = $groups[$key] ?? ['method' => $po ? 'PREORDER' : 'READY_TO_SHIP', 'campaign_id' => $po->campaign_id ?? null,
                'campaign_slug' => $po->campaign->slug ?? null, 'campaign_terms' => $po ? array_intersect_key((array) $po->campaign,
                    array_flip(['terms', 'slug', 'payment_scheme', 'ready_eta_from', 'ready_eta_to', 'ship_eta_from', 'ship_eta_to', 'origin', 'transport_mode'])) : null,
                'subtotal_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 0,
                'due_now_amount' => 0, 'initial_tax' => 0, 'weight' => 0, 'term' => 'FULL', 'lines' => []];
            $unit = $po->unit_amount ?? $v->rts_price_amount;
            $initial = $i->term === 'DEPOSIT' ? $po->deposit_unit_amount : $unit;
            $net = self::bounded($unit * $i->qty);
            $tax = intdiv($net * $v->tax_bps + 5000, 10000);
            $initialTax = intdiv($initial * $i->qty * $v->tax_bps + 5000, 10000);
            $g['subtotal_amount'] += $net;
            $g['tax_amount'] += $tax;
            $g['total_amount'] = self::bounded($g['subtotal_amount'] + $g['tax_amount']);
            $g['initial_tax'] += $initialTax;
            $g['due_now_amount'] += $i->qty * $initial + $initialTax;
            $g['weight'] += $i->qty * $v->weight_g;
            if ($i->term === 'DEPOSIT') {
                $g['term'] = 'DEPOSIT';
            }
            $g['lines'][] = ['variant_id' => $v->id, 'campaign_variant_id' => $i->campaign_variant_id, 'sku_snapshot' => $v->sku,
                'product_snapshot' => $v->product_name, 'variant_snapshot' => $v->name, 'weight_g' => $v->weight_g,
                'qty' => $i->qty, 'unit_amount' => $unit, 'term' => $i->term, 'initial_unit_amount' => $initial,
                'tax_class_id' => $v->tax_class_id, 'tax_bps' => $v->tax_bps, 'tax_amount' => $tax, 'initial_tax' => $initialTax];
            $groups[$key] = $g;
        }

        return $groups;
    }

    private function eligible(object $item, object $variant, ?object $offer): bool
    {
        if ($item->campaign_variant_id === null) {
            return $variant->rts_enabled;
        }
        if (! $offer || $offer->variant_id !== $variant->id || $item->qty < $offer->min_qty || $item->qty > $offer->max_qty) {
            return false;
        }

        return $item->term === 'DEPOSIT'
            ? $offer->deposit_unit_amount !== null && $offer->campaign->payment_scheme !== 'FULL_ONLY'
            : $offer->campaign->payment_scheme !== 'DEPOSIT_ONLY';
    }
}
