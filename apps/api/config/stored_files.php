<?php

use App\Modules\Geography\RegionStaging;

return [
    // Domain modules register owner type => FileOwnerPolicy. Missing policies deny access.
    'owners' => ['staging' => RegionStaging::class],
    'clamd' => env('CLAMD_ADDRESS', 'tcp://clamav:3310'),
    'signing_endpoint' => env('S3_DOWNLOAD_ENDPOINT', env('S3_ENDPOINT')),
];
