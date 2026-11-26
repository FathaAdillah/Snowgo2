<?php

namespace App\Modules\Shipping;

use App\Support\Modules\ModuleServiceProvider;

final class ShippingServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        if ($this->app->runningInConsole()) {
            $this->commands([SyncCourierMaster::class]);
        }
    }
}
