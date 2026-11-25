<?php

namespace App\Modules\Preorders;

use App\Modules\Catalog\ProductDirectory;
use Illuminate\Database\Query\Builder;

final class CampaignOffers
{
    public static function open(): Builder
    {
        return ProductDirectory::offerableVariants()
            ->join('preorder_campaign_variants as cv', 'cv.variant_id', '=', 'product_variants.id')
            ->join('preorder_campaigns as c', 'c.id', '=', 'cv.campaign_id')
            ->where('c.status', 'OPEN')->whereRaw('c.starts_at <= statement_timestamp() AND c.ends_at > statement_timestamp()')
            ->selectRaw("product_variants.id AS variant_id, sku, cv.id AS campaign_variant_id, c.slug AS campaign_slug,
                cv.unit_amount, cv.min_qty, least(cv.max_qty, cv.quota-cv.reserved_qty-cv.confirmed_qty) AS max_qty,
                c.payment_scheme <> 'DEPOSIT_ONLY' AS full_allowed,
                c.payment_scheme <> 'FULL_ONLY' AND cv.deposit_unit_amount IS NOT NULL AS deposit_allowed");
    }
}
