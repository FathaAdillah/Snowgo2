<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Inventory\Http\ReservationController;
use App\Modules\Inventory\Http\StockController;
use App\Modules\Inventory\Http\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('inventory.')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('can:inventory.warehouse.view')->name('warehouses.index');
    Route::get('stock-reservations', [ReservationController::class, 'index'])->middleware('can:inventory.reservation.view')->name('reservations.index');
    Route::get('stock-reservations/{id}', [ReservationController::class, 'show'])->whereNumber('id')->middleware('can:inventory.reservation.view')->name('reservations.show');
    Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->whereNumber('id')->middleware('can:inventory.warehouse.view')->name('warehouses.show');
    Route::post('warehouses', [WarehouseController::class, 'create'])->middleware('can:inventory.warehouse.create')->name('warehouses.create');
    Route::patch('warehouses/{id}', [WarehouseController::class, 'update'])->whereNumber('id')->middleware('can:inventory.warehouse.update')->name('warehouses.update');
    foreach (['levels' => ['level', 'stock'], 'movements' => ['movement', 'movement'], 'adjustments' => ['adjustment', 'adjustment']] as $resource => [$single,$permission]) {
        Route::get("stock-$resource", [StockController::class, $resource])->middleware("can:inventory.$permission.view")->name("$resource.index");
        Route::get("stock-$resource/{id}", [StockController::class, $single])->whereNumber('id')->middleware("can:inventory.$permission.view")->name("$resource.show");
    }
    Route::post('stock-receipts', [StockController::class, 'receive'])->middleware('can:inventory.receipt.create')->name('receipt');
    Route::post('stock-adjustments', [StockController::class, 'adjust'])->middleware('can:inventory.adjustment.create')->name('adjust');
});
