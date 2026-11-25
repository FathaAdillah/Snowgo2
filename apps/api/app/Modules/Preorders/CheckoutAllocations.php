<?php

namespace App\Modules\Preorders;

use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CheckoutAllocations
{
    /** @param Collection<int,int> $ids
     * @return Collection<int,\stdClass> */
    public function offers(Collection $ids, int $owner, bool $submit): Collection
    {
        $query = DB::table('preorder_campaign_variants')->whereIn('id', $ids);
        $campaigns = DB::table('preorder_campaigns')->whereIn('id', (clone $query)->select('campaign_id'))->where('status', 'OPEN')
            ->whereRaw('starts_at <= clock_timestamp() AND ends_at > clock_timestamp()')->orderBy('id')->sharedLock()->get()->keyBy('id');
        $variants = $query->whereIn('campaign_id', $campaigns->keys())->orderBy('id')->lock($submit)->get()->keyBy('id');
        $held = DB::table('preorder_allocations as a')->join('order_items as i', 'i.id', '=', 'a.order_item_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')->where('o.user_id', $owner)->whereIn('a.campaign_variant_id', $ids)
            ->whereIn('a.status', ['RESERVED', 'CONFIRMED', 'FULFILLED'])->groupBy('a.campaign_variant_id')
            ->selectRaw('a.campaign_variant_id,sum(a.qty) as qty')->pluck('qty', 'campaign_variant_id');
        foreach ($variants as $v) {
            $v->campaign = $campaigns[$v->campaign_id];
            $v->remaining = min($v->quota - $v->reserved_qty - $v->confirmed_qty, $v->max_qty - ($held[$v->id] ?? 0));
        }

        return $variants;
    }

    /** The caller holds the order lock; acquire quota locks in the same order as checkout.
     * @param list<int> $items */
    public function release(array $items): void
    {
        $rows = DB::table('preorder_allocations')->whereIn('order_item_id', $items)->orderBy('campaign_variant_id')->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== count($items) || $rows->contains(fn ($a) => $a->status !== 'RESERVED')) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Only reserved preorder units can be released.');
        }
        foreach ($rows as $row) {
            if (! DB::table('preorder_campaign_variants')->where('id', $row->campaign_variant_id)->where('reserved_qty', '>=', $row->qty)->decrement('reserved_qty', $row->qty)) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Preorder quota no longer matches the allocation.');
            }
            DB::table('preorder_allocations')->where('id', $row->id)->update(['status' => 'RELEASED']);
            app(Outbox::class)->record('PreorderAllocationReleased', 'preorder_allocation', $row->id, ['quantity' => $row->qty]);
        }
    }

    public function reserve(int $offer, int $item, int $qty, string $due): void
    {
        $open = DB::table('preorder_campaigns as c')->join('preorder_campaign_variants as cv', 'cv.campaign_id', '=', 'c.id')
            ->where('cv.id', $offer)->where('c.status', 'OPEN')->whereRaw('starts_at <= clock_timestamp() AND ends_at > clock_timestamp()')->exists();
        if (! $open) {
            throw new ApiProblem(409, 'QUOTE_CHANGED', 'The campaign is no longer open.');
        }
        $updated = DB::table('preorder_campaign_variants')->where('id', $offer)
            ->whereRaw('quota-reserved_qty-confirmed_qty >= ?', [$qty])->increment('reserved_qty', $qty);
        if (! $updated) {
            throw new ApiProblem(409, 'QUOTA_FULL', 'Not enough preorder quota.');
        }
        $id = DB::table('preorder_allocations')->insertGetId(['campaign_variant_id' => $offer, 'order_item_id' => $item,
            'qty' => $qty, 'status' => 'RESERVED', 'expires_at' => $due]);
        app(Outbox::class)->record('PreorderAllocationReserved', 'preorder_allocation', $id, ['quantity' => $qty]);
    }
}
