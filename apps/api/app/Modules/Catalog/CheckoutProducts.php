<?php

namespace App\Modules\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CheckoutProducts
{
    /** @param Collection<int,int> $ids
     * @return Collection<int,\stdClass> */
    public function snapshots(Collection $ids): Collection
    {
        $eligible = ProductDirectory::variants()->whereIn('id', $ids);
        $products = ProductDirectory::published()->whereIn('id', (clone $eligible)->select('product_id'))
            ->orderBy('id')->sharedLock()->get(['id', 'name', 'tax_class_id'])->keyBy('id');
        $tax = DB::table('tax_classes')->whereIn('id', $products->pluck('tax_class_id'))->where('active', true)
            ->orderBy('id')->sharedLock()->pluck('rate_bps', 'id');
        $variants = $eligible->whereIn('product_id', $products->keys())->orderBy('id')->sharedLock()
            ->get(['id', 'sku', 'product_id', 'name', 'weight_g', 'rts_enabled', 'rts_price_amount'])->keyBy('id');
        foreach ($variants as $v) {
            $product = $products[$v->product_id];
            $v->product_name = $product->name;
            $v->tax_class_id = $product->tax_class_id;
            $v->tax_bps = $tax[$product->tax_class_id] ?? 0;
        }

        return $variants;
    }
}
