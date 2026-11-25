<?php

use App\Modules\Catalog\CatalogFileOwner;
use App\Modules\Catalog\VariantFileOwner;
use App\Modules\Geography\RegionStaging;
use App\Modules\RequestOrders\RequestAttachmentPolicy;

return [
    // Domain modules register owner type => FileOwnerPolicy. Missing policies deny access.
    'owners' => ['staging' => RegionStaging::class, 'product' => CatalogFileOwner::class,
        'variant' => VariantFileOwner::class, 'request_order' => RequestAttachmentPolicy::class],
    'clamd' => env('CLAMD_ADDRESS', 'tcp://clamav:3310'),
    'signing_endpoint' => env('S3_DOWNLOAD_ENDPOINT', env('S3_ENDPOINT')),
];
