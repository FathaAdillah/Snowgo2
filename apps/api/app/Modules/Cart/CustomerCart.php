<?php

namespace App\Modules\Cart;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerCart
{
    public function __construct(private readonly OfferProvider $provider) {}

    /** @return array<string,mixed> */
    public function read(int $owner): array
    {
        // One statement snapshot: a concurrent edit must never pair the previous version with newer quantities.
        $rows = DB::table('carts as c')->where(['c.user_id' => $owner, 'c.status' => 'ACTIVE'])
            ->leftJoin('cart_items as i', 'i.cart_id', '=', 'c.id')
            ->leftJoinSub($this->provider->offers(), 'o', fn (JoinClause $join) => $join->on('o.variant_id', '=', 'i.variant_id')
                ->whereRaw('o.campaign_variant_id IS NOT DISTINCT FROM i.campaign_variant_id'))
            ->orderBy('i.id')->get(['c.version', 'i.public_id', 'i.qty', 'i.term', 'i.display_unit_amount', 'i.sku', 'i.campaign_slug',
                'o.sku as live_sku', 'o.campaign_slug as live_slug', 'o.unit_amount', 'o.min_qty', 'o.max_qty', 'o.full_allowed', 'o.deposit_allowed']);
        $items = [];
        $total = 0;
        foreach ($rows as $row) {
            if ($row->public_id === null) {
                continue;
            }
            $available = $row->unit_amount !== null && $this->eligible($row, $row->qty, $row->term);
            $amount = $row->unit_amount ?? $row->display_unit_amount;
            $slug = $row->live_slug ?? $row->campaign_slug;
            $items[] = ['id' => $row->public_id, 'sku' => $row->live_sku ?? $row->sku, ...($slug !== null ? ['campaign_slug' => $slug] : []),
                'qty' => $row->qty, 'term' => $row->term, 'display_unit_amount' => $amount,
                'availability' => ! $available ? 'UNAVAILABLE' : ($amount === $row->display_unit_amount ? 'AVAILABLE' : 'PRICE_CHANGED')];
            if ($available) {
                $total += $amount * $row->qty;
            }
        }

        return ['version' => $rows->first()->version ?? 0, 'items' => $items, 'estimated_total_amount' => $total];
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    public function set(int $owner, array $data, ?string $itemId = null): array
    {
        return $this->change($owner, $data, $itemId);
    }

    /** @return array<string,mixed> */
    public function remove(int $owner, string $itemId, int $version): array
    {
        return $this->change($owner, ['cart_version' => $version], $itemId, true);
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed> */
    private function change(int $owner, array $data, ?string $itemId, bool $delete = false): array
    {
        return DB::transaction(function () use ($owner, $data, $itemId, $delete) {
            // Serialize the first write too: there is no persisted cart to lock at virtual version zero.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ["cart:owner:$owner"]);
            $carts = DB::table('carts')->where('user_id', $owner);
            $cart = (clone $carts)->where('status', 'ACTIVE')->lockForUpdate()->first();
            $item = $itemId === null ? null : (DB::table('cart_items')->where('cart_id', $cart?->id)->where('public_id', $itemId)->first() ?? abort(404));
            $version = $cart->version ?? 0;
            if ($version !== (int) $data['cart_version']) {
                throw new ApiProblem(409, 'VERSION_CONFLICT', 'Reload the cart before editing.');
            }
            // Do not reuse versions from converted carts: an old tab may still submit its previous version.
            $next = ($cart->version ?? (int) $carts->max('version')) + 1;
            $cartId = $cart->id ?? DB::table('carts')->insertGetId(['user_id' => $owner, 'version' => $next]);
            if ($delete) {
                DB::table('cart_items')->where('id', $item->id)->delete();
            } else {
                $this->setItem($cartId, $data, $item);
            }
            if ($cart) {
                DB::table('carts')->where('id', $cartId)->update(['version' => $next, 'updated_at' => DB::raw('now()')]);
            }
            app(Journal::class)->audit('CartChanged', Subject::Cart, $cartId, $owner, ['version' => $version], ['version' => $next]);
            app(Outbox::class)->record('CartChanged', 'cart', $cartId, ['version' => $next]);

            return $this->read($owner);
        });
    }

    /** @param array<string,mixed> $data */
    private function setItem(int $cartId, array $data, ?object $item): void
    {
        $offers = $this->provider->offers();
        $offer = $item ? $offers->where('variant_id', $item->variant_id)->where('campaign_variant_id', $item->campaign_variant_id)->first()
            : $offers->where('sku', $data['sku'])->where('campaign_slug', $data['campaign_slug'] ?? null)->first();
        $qty = (int) $data['qty'];
        $term = $data['term'] ?? $item->term;
        if (! $offer || ! $this->eligible($offer, $qty, $term)) {
            throw new ApiProblem(409, 'OFFER_UNAVAILABLE', 'Offer is not available for this quantity or payment term.');
        }
        $identity = ['cart_id' => $cartId, 'variant_id' => $offer->variant_id, 'campaign_variant_id' => $offer->campaign_variant_id];
        $values = ['qty' => $qty, 'term' => $term, 'sku' => $offer->sku, 'campaign_slug' => $offer->campaign_slug];
        $line = $item->id ?? DB::table('cart_items')->where($identity)->value('id');
        if ($line !== null) {
            // Editing quantity or term doesn't acknowledge a price the customer may not have seen.
            DB::table('cart_items')->where('id', $line)->update($values);
        } else {
            DB::table('cart_items')->insert($identity + $values + ['public_id' => (string) Str::ulid(), 'display_unit_amount' => $offer->unit_amount]);
        }
    }

    private function eligible(object $offer, int $qty, string $term): bool
    {
        return $qty >= $offer->min_qty && $qty <= $offer->max_qty && ($term === 'FULL' ? $offer->full_allowed : $offer->deposit_allowed);
    }
}
