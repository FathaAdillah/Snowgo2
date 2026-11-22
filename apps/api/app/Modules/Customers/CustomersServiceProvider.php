<?php

namespace App\Modules\Customers;

use App\Modules\Customers\Profiles\CustomerProfiles;
use App\Modules\Identity\Contracts\CustomerDirectory;
use App\Support\Modules\ModuleServiceProvider;

final class CustomersServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerDirectory::class, CustomerProfiles::class);
    }
}
