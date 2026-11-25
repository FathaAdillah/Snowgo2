<?php

namespace App\Modules\Checkout;

use App\Modules\Cart\CustomerCart;
use App\Modules\Customers\Addresses\AddressSnapshot;
use App\Modules\Shipping\DomesticQuotes;
use App\Support\Http\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CheckoutPricing
{
    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    public function preview(int $owner, array $data): array
    {
        return DB::transaction(fn () => $this->createPreview($owner, $data));
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    private function createPreview(int $owner, array $data): array
    {
        $carts = app(CustomerCart::class);
        $carts->lockOwner($owner);
        $cart = $carts->checkoutCart($owner, (int) $data['cart_version']);
        $plan = $this->calculate($cart, $data, false);
        $groups = [];
        foreach ($plan['groups'] as $group) {
            $groups[] = array_filter(Arr::only($group, ['method', 'campaign_slug', 'subtotal_amount', 'discount_amount', 'tax_amount',
                'shipping_amount', 'total_amount', 'due_now_amount']), fn ($v) => $v !== null);
        }
        $result = ['cart_version' => $cart->version, 'expires_at' => $plan['expires']->toIso8601String(),
            'shipping_quotes' => $plan['options'], 'groups' => $groups];
        if (! isset($plan['groups']['rts']) || isset($data['shipping_quote_id'])) {
            $token = bin2hex(random_bytes(32));
            DB::table('checkout_previews')->insert(['user_id' => $owner, 'cart_id' => $cart->id, 'cart_version' => $cart->version,
                'quote_hash' => $token, 'pricing_hash' => $plan['hash'], 'expires_at' => $plan['expires']]);
            $result['quote_hash'] = $token;
        }

        return $result;
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    public function calculate(object $cart, array $data, bool $submit): array
    {
        $address = app(AddressSnapshot::class)->capture($cart->user_id, $data['address_id']);
        $groups = app(GroupPricing::class)->groups($cart, $submit);
        $selection = $data['shipping_quote_id'] ?? null;
        $expires = CarbonImmutable::now()->startOfSecond()->addMinutes(15);
        $options = [];
        if (isset($groups['rts'])) {
            if ($submit && ! $selection) {
                throw new ApiProblem(409, 'QUOTE_CHANGED', 'Select a domestic shipping quote.');
            }
            $options = app(DomesticQuotes::class)->resolve($cart, $address, $groups['rts']['weight'], $selection, $expires);
            if ($selection !== null) {
                $rate = $options[0];
                $expires = CarbonImmutable::parse($rate['expires_at']);
                $groups['rts']['shipping_amount'] = $rate['amount'];
                $groups['rts']['total_amount'] = $groups['rts']['due_now_amount'] = GroupPricing::bounded($groups['rts']['total_amount'] + $rate['amount']);
            }
        } elseif ($selection !== null) {
            throw new ApiProblem(409, 'QUOTE_CHANGED', 'Preorders do not include domestic shipping at checkout.');
        }
        $hash = hash('sha256', json_encode([$cart->user_id, $cart->id, $cart->version, $address, $groups, $selection], JSON_THROW_ON_ERROR));

        return compact('address', 'groups', 'options', 'hash', 'expires');
    }
}
