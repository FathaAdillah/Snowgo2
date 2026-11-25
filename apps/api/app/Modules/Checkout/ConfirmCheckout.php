<?php

namespace App\Modules\Checkout;

use App\Modules\Administration\OperationalSettings;
use App\Modules\Cart\CustomerCart;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Inventory\StockReservations;
use App\Modules\Invoicing\InitialInvoices;
use App\Modules\Orders\CheckoutOrders;
use App\Modules\Preorders\CheckoutAllocations;
use App\Modules\Shipping\DomesticQuotes;
use App\Support\Http\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConfirmCheckout
{
    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    public function submit(int $owner, array $data): array
    {
        return DB::transaction(function () use ($owner, $data) {
            $carts = app(CustomerCart::class);
            $carts->lockOwner($owner);
            $version = (int) $data['cart_version'];
            $selection = $data['shipping_quote_id'] ?? null;
            $notes = $data['notes'] ?? null;
            $intent = hash('sha256', json_encode([$version, $data['address_id'], $selection, $data['quote_hash'], $notes], JSON_THROW_ON_ERROR));
            $existing = DB::table('checkouts')->where(['user_id' => $owner, 'cart_version' => $version])->first();
            if ($existing) {
                if (! hash_equals($existing->intent_hash, $intent)) {
                    throw new ApiProblem(409, 'VERSION_CONFLICT', 'This version already has a different checkout.');
                }

                return $this->result($owner, $existing->public_id);
            }
            $cart = $carts->checkoutCart($owner, $version);
            $preview = DB::table('checkout_previews')->where(['quote_hash' => $data['quote_hash'], 'user_id' => $owner,
                'cart_id' => $cart->id, 'cart_version' => $version])->sharedLock()->first();
            if (! $preview) {
                throw $this->changed();
            }
            $this->checkExpiry($preview);
            $plan = app(CheckoutPricing::class)->calculate($cart, $data, true);
            if (! hash_equals($preview->pricing_hash, $plan['hash'])) {
                throw $this->changed();
            }
            $public = (string) Str::ulid();
            $id = DB::table('checkouts')->insertGetId(['user_id' => $owner, 'cart_id' => $cart->id, 'cart_version' => $version,
                'quote_hash' => $data['quote_hash'], 'intent_hash' => $intent, 'public_id' => $public]);
            $preorderDue = now()->addHours(24)->toIso8601String();
            foreach ($plan['groups'] as $g) {
                $due = $g['method'] === 'READY_TO_SHIP' ? now()->addMinutes(OperationalSettings::value('inventory.reservation_ttl_minutes'))->toIso8601String() : $preorderDue;
                $order = app(CheckoutOrders::class)->create($id, $owner, $g, $plan['address'], $due, $notes);
                if ($g['method'] === 'READY_TO_SHIP') {
                    app(StockReservations::class)->reserve($order['id']);
                } else {
                    foreach ($order['lines'] as $line) {
                        app(CheckoutAllocations::class)->reserve($line['campaign_variant_id'], $line['id'], $line['qty'], $due);
                    }
                }
                app(InitialInvoices::class)->issue($order['id'], $owner, $g, $order['lines'], $due);
            }
            $this->checkExpiry($preview);
            if ($selection !== null) {
                app(DomesticQuotes::class)->consume($cart->id, $plan['options'][0]);
            }
            $carts->convert($cart->id);
            app(Outbox::class)->record('CheckoutCompleted', 'checkout', $id, ['cart_version' => $version]);

            return $this->result($owner, $public);
        });
    }

    /** @return array<string,mixed> */
    public function result(int $owner, string $public): array
    {
        $checkout = DB::table('checkouts')->where(['user_id' => $owner, 'public_id' => $public])->first() ?? abort(404);
        $rows = DB::table('orders as o')->join('invoices as i', 'i.order_id', '=', 'o.id')->where('o.checkout_id', $checkout->id)
            ->where('i.source_ref', 'checkout:initial')->orderBy('o.id')->get(['o.number as order', 'i.number as invoice']);

        return ['id' => $public, 'order_numbers' => $rows->pluck('order')->all(), 'invoice_numbers' => $rows->pluck('invoice')->all(),
            'completed_at' => CarbonImmutable::parse($checkout->completed_at)->toIso8601String()];
    }

    private function changed(): ApiProblem
    {
        return new ApiProblem(409, 'QUOTE_CHANGED', 'Review a new checkout preview before confirming.');
    }

    private function checkExpiry(object $preview): void
    {
        if (now()->gte($preview->expires_at)) {
            throw new ApiProblem(409, 'QUOTE_EXPIRED', 'Review a new checkout preview.');
        }
    }
}
