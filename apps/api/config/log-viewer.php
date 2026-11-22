<?php

use App\Modules\Administration\Http\Middleware\LocalLogAccess;

return [
    'enabled' => env('APP_ENV') === 'local',
    'route_path' => 'log-viewerx',
    'route_domain' => parse_url((string) env('ADMIN_URL', 'http://admin.localhost'), PHP_URL_HOST),
    'back_to_system_url' => env('ADMIN_URL'),
    'middleware' => [LocalLogAccess::class, 'web'],
    'api_middleware' => [LocalLogAccess::class, 'web'],
    'include_files' => ['laravel*.log'],
    'operator' => env('LOG_VIEWER_USER'),
    'password' => env('LOG_VIEWER_PASSWORD'),
];
