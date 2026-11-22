<?php

use App\Modules\Administration\AdministrationServiceProvider;
use App\Modules\Cart\CartServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Checkout\CheckoutServiceProvider;
use App\Modules\Content\ContentServiceProvider;
use App\Modules\Customers\CustomersServiceProvider;
use App\Modules\Foundation\FoundationServiceProvider;
use App\Modules\Geography\GeographyServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Invoicing\InvoicingServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Preorders\PreordersServiceProvider;
use App\Modules\Procurement\ProcurementServiceProvider;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Modules\Refunds\RefundsServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\RequestOrders\RequestOrdersServiceProvider;
use App\Modules\Returns\ReturnsServiceProvider;
use App\Modules\Search\SearchServiceProvider;
use App\Modules\Shipping\ShippingServiceProvider;

/*
 * Module service providers, in dependency order (docs/03-TECH-STACK-ARCHITECTURE.md).
 */
return [
    'providers' => [
        FoundationServiceProvider::class,
        IdentityServiceProvider::class,
        CustomersServiceProvider::class,
        GeographyServiceProvider::class,
        CatalogServiceProvider::class,
        InventoryServiceProvider::class,
        PreordersServiceProvider::class,
        ProcurementServiceProvider::class,
        RequestOrdersServiceProvider::class,
        CartServiceProvider::class,
        CheckoutServiceProvider::class,
        OrdersServiceProvider::class,
        InvoicingServiceProvider::class,
        PaymentsServiceProvider::class,
        PromotionsServiceProvider::class,
        ShippingServiceProvider::class,
        RefundsServiceProvider::class,
        ReturnsServiceProvider::class,
        ContentServiceProvider::class,
        NotificationsServiceProvider::class,
        SearchServiceProvider::class,
        ReportingServiceProvider::class,
        AdministrationServiceProvider::class,
    ],
];
