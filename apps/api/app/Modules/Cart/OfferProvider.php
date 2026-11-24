<?php

namespace App\Modules\Cart;

use Illuminate\Database\Query\Builder;

interface OfferProvider
{
    /**
     * Read-only estimates. One row per (variant_id,campaign_variant_id), with sku, campaign_slug,
     * unit_amount, min_qty, max_qty (including remaining supply), full_allowed and deposit_allowed.
     */
    public function offers(): Builder;
}
