<?php

use App\Modules\Administration\Http\Middleware\LocalOperatorAccess;

return [
    'enabled' => env('APP_ENV') === 'local',
    'route_path' => 'log-viewerx',
    'route_domain' => parse_url((string) env('ADMIN_URL', 'http://admin.localhost'), PHP_URL_HOST),
    'back_to_system_url' => env('ADMIN_URL'),
    'middleware' => ['web', LocalOperatorAccess::class],
    'api_middleware' => ['web', LocalOperatorAccess::class],
    'include_files' => ['laravel*.log'],
];
