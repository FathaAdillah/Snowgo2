<?php

namespace App\Modules\Geography;

use App\Support\Modules\ModuleServiceProvider;

final class GeographyServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        if ($this->app->runningInConsole()) {
            $this->commands([SyncWilayah::class]);
        }
    }
}
