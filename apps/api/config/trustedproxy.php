<?php

return [
    // Edge Nginx addresses allowed to set X-Forwarded-* (comma-separated IPs/CIDRs).
    'proxies' => env('TRUSTED_PROXIES'),
];
