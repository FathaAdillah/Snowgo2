<?php

use App\Modules\Catalog\CatalogFileOwner;
use App\Modules\Catalog\VariantFileOwner;
use App\Modules\Geography\RegionStaging;
use App\Modules\Payments\PaymentEvidence;
use App\Modules\RequestOrders\RequestAttachmentPolicy;

return [
    // Uploadable parents only. Invoice PDFs use their dedicated, owner/permission-scoped download routes.
    'owners' => ['staging' => RegionStaging::class, 'product' => CatalogFileOwner::class,
        'variant' => VariantFileOwner::class, 'request_order' => RequestAttachmentPolicy::class, 'payment' => PaymentEvidence::class],
    'clamd' => env('CLAMD_ADDRESS', 'tcp://clamav:3310'),
    'signing_endpoint' => env('S3_DOWNLOAD_ENDPOINT', env('S3_ENDPOINT')),
];
