<?php

use App\Modules\Administration\Http\Middleware\LocalOperatorAccess;

return [
    'enabled' => env('APP_ENV') === 'local' || (in_array(env('APP_ENV'), ['production', 'staging'], true) && env('OPERATOR_LOG_VIEWER_ENABLED', false) === true),
    'route_path' => 'log-viewerx',
    'route_domain' => parse_url((string) env('ADMIN_URL', 'http://admin.localhost'), PHP_URL_HOST),
    'back_to_system_url' => env('ADMIN_URL'),
    'middleware' => ['web', LocalOperatorAccess::class],
    'api_middleware' => ['web', LocalOperatorAccess::class],
    'include_files' => env('APP_ENV') === 'local' ? ['laravel*.log'] : ['operator-events*.log'],
];
