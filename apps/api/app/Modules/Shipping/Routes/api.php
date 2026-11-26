<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Shipping\Http\CourierSyncController;
use App\Modules\Shipping\Http\ShipmentController;
use App\Modules\Shipping\Http\ShippingMasters;
use Illuminate\Support\Facades\Route;

Route::get('shipments/{number}', [ShipmentController::class, 'show'])->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('shipping.customer');
Route::prefix('admin')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->name('shipping.')->group(function () {
    Route::post('courier-master-syncs', CourierSyncController::class)->middleware(['can:shipping.courier.view', 'can:shipping.provider.sync', 'throttle:10,1'])->name('provider.sync');
    Route::get('shipment-rate-options', [ShipmentController::class, 'options'])->middleware(['can:shipping.shipment.view', 'can:shipping.rate.view', 'can:shipping.quote.create', 'throttle:30,1'])->name('provider.options');
    Route::get('couriers', [ShippingMasters::class, 'listCourier'])->middleware(['can:shipping.courier.view'])->name('courier.list');
    Route::get('couriers/{id}', [ShippingMasters::class, 'showCourier'])->middleware(['can:shipping.courier.view'])->whereNumber('id')->name('courier.show');
    Route::post('couriers', [ShippingMasters::class, 'createCourier'])->middleware(['can:shipping.courier.view', 'can:shipping.courier.manage', 'can:shipping.courier.create'])->name('courier.create');
    Route::patch('couriers/{id}', [ShippingMasters::class, 'updateCourier'])->middleware(['can:shipping.courier.view', 'can:shipping.courier.manage', 'can:shipping.courier.update'])->whereNumber('id')->name('courier.update');
    Route::get('courier-services', [ShippingMasters::class, 'listService'])->middleware(['can:shipping.service.view'])->name('service.list');
    Route::get('courier-services/{id}', [ShippingMasters::class, 'showService'])->middleware(['can:shipping.service.view'])->whereNumber('id')->name('service.show');
    Route::post('courier-services', [ShippingMasters::class, 'createService'])->middleware(['can:shipping.service.view', 'can:shipping.service.manage', 'can:shipping.service.create'])->name('service.create');
    Route::patch('courier-services/{id}', [ShippingMasters::class, 'updateService'])->middleware(['can:shipping.service.view', 'can:shipping.service.manage', 'can:shipping.service.update'])->whereNumber('id')->name('service.update');
    Route::get('shipping-rates', [ShippingMasters::class, 'listRate'])->middleware(['can:shipping.rate.view'])->name('rate.list');
    Route::get('shipping-rates/{id}', [ShippingMasters::class, 'showRate'])->middleware(['can:shipping.rate.view'])->whereNumber('id')->name('rate.show');
    Route::post('shipping-rates', [ShippingMasters::class, 'createRate'])->middleware(['can:shipping.rate.view', 'can:shipping.rate.manage', 'can:shipping.rate.create'])->name('rate.create');
    Route::patch('shipping-rates/{id}', [ShippingMasters::class, 'updateRate'])->middleware(['can:shipping.rate.view', 'can:shipping.rate.manage', 'can:shipping.rate.update'])->whereNumber('id')->name('rate.update');
    Route::get('shipments', [ShipmentController::class, 'index'])->middleware(['can:shipping.shipment.view'])->name('shipment.index');
    Route::get('shipments/{number}', [ShipmentController::class, 'show'])->middleware(['can:shipping.shipment.view'])->name('shipment.show');
    Route::post('shipments', [ShipmentController::class, 'create'])->middleware(['can:shipping.shipment.view', 'can:shipping.shipment.manage', 'can:shipping.shipment.create'])->name('shipment.create');
    Route::post('shipments/{number}/tracking-events', [ShipmentController::class, 'track'])->middleware(['can:shipping.shipment.view', 'can:shipping.tracking.manage'])->name('shipment.track');
    Route::post('shipments/{number}/dispatches', [ShipmentController::class, 'dispatch'])->middleware(['can:shipping.shipment.view', 'can:shipping.shipment.manage', 'can:shipping.shipment.dispatch'])->name('shipment.dispatch');
    Route::post('shipments/{number}/cancellations', [ShipmentController::class, 'cancel'])->middleware(['can:shipping.shipment.view', 'can:shipping.shipment.manage', 'can:shipping.shipment.cancel'])->name('shipment.cancel');
    Route::post('orders/{number}/packing-events', [ShipmentController::class, 'pack'])->middleware(['can:shipping.shipment.view', 'can:orders.packing.manage'])->name('shipment.pack');
    Route::post('shipping-quotes', [ShipmentController::class, 'quote'])->middleware(['can:shipping.shipment.view', 'can:shipping.quote.create', 'can:shipping.rate.view'])->name('shipment.quote');
});
