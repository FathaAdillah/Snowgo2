<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Orders\Http\ChargeController;
use App\Modules\Orders\Http\OrderController;
use App\Modules\Orders\Http\StaffOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('orders')->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('orders.')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('{number}', [OrderController::class, 'show'])->name('show');
    Route::post('{number}/cancellations', [OrderController::class, 'cancel'])->name('cancel');
    Route::post('{number}/charge-consents/{id}/decisions', [ChargeController::class, 'decide'])->whereUlid('id')->name('charge.decide');
    Route::post('{number}/completions', [OrderController::class, 'complete'])->name('complete');
});
Route::prefix('admin/orders')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:orders.order.view'])->name('orders.staff.')->group(function () {
    Route::get('/', [StaffOrderController::class, 'index'])->name('index');
    Route::get('{number}', [StaffOrderController::class, 'show'])->name('show');
    Route::post('{number}/charge-consents', [ChargeController::class, 'propose'])->middleware(['can:orders.order.manage', 'can:orders.charge.propose'])->name('charge.propose');
    Route::post('{number}/actions/{action}', [StaffOrderController::class, 'action'])->whereIn('action', ['CANCEL', 'COMPLETE'])->middleware('can:orders.order.manage')->name('action');
});
