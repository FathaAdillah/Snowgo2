<?php

namespace App\Modules\Cart;

use App\Support\Modules\ModuleServiceProvider;

final class CartServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OfferProvider::class, PublishedOffers::class);
    }
}
