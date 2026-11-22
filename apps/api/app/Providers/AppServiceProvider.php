<?php

namespace App\Providers;

use App\Support\Http\ApiContract;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MiddlewareAuthSecurityStrategy::class, fn () => new MiddlewareAuthSecurityStrategy(
            scheme: SecurityScheme::apiKey('cookie', config('session.cookie'))->as('session'),
        ));

        /** @var list<class-string<ServiceProvider>> $modules */
        $modules = config('modules.providers', []);

        foreach ($modules as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        Scramble::configure()->withDocumentTransformers(ApiContract::class);
    }
}
