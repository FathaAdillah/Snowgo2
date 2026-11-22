<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Each module owns its routes and migrations. Module routes are mounted
 * under /api/v1 with the `api` middleware group.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $path = $this->modulePath();

        if (is_dir($path.'/Database/Migrations')) {
            $this->loadMigrationsFrom($path.'/Database/Migrations');
        }

        if (! $this->app->routesAreCached() && is_file($path.'/Routes/api.php')) {
            Route::prefix('api/v1')
                ->middleware('api')
                ->group($path.'/Routes/api.php');
        }
    }

    protected function modulePath(): string
    {
        return dirname((string) (new ReflectionClass($this))->getFileName());
    }
}
