<?php

namespace App\Modules\Cart;

use App\Modules\Catalog\ProductDirectory;
use App\Modules\Inventory\StockAvailability;
use App\Modules\Preorders\CampaignOffers;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PublishedOffers implements OfferProvider
{
    public function offers(): Builder
    {
        $rts = ProductDirectory::offerableVariants()->where('rts_enabled', true)
            ->leftJoinSub(StockAvailability::readyToShip(), 'stock', 'stock.variant_id', '=', 'product_variants.id')
            ->selectRaw('product_variants.id AS variant_id, sku, NULL::bigint AS campaign_variant_id, NULL::varchar AS campaign_slug,
                rts_price_amount AS unit_amount, 1 AS min_qty, coalesce(stock.available, 0) AS max_qty, true AS full_allowed, false AS deposit_allowed');

        return DB::query()->fromSub($rts->unionAll(CampaignOffers::open()), 'offers');
    }
}
