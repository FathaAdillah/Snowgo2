<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Refunds\Http\RefundController;
use Illuminate\Support\Facades\Route;

Route::prefix('refunds')->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('refunds.customer.')->group(function () {
    Route::get('/', [RefundController::class, 'index'])->name('index');
    Route::get('{number}', [RefundController::class, 'show'])->name('show');
    Route::post('/', [RefundController::class, 'create'])->name('create');
});
Route::prefix('admin/refunds')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:refunds.refund.view'])->name('refunds.staff.')->group(function () {
    Route::get('/', [RefundController::class, 'index'])->name('index');
    Route::get('{number}', [RefundController::class, 'show'])->name('show');
    Route::post('/', [RefundController::class, 'create'])->middleware('can:refunds.refund.create')->name('create');
    Route::post('{number}/reviews', [RefundController::class, 'review'])->middleware('can:refunds.refund.review')->name('review');
    Route::post('{number}/execution-attempts', [RefundController::class, 'attempt'])->middleware('can:refunds.refund.execute')->name('attempt');
    Route::post('{number}/transfers', [RefundController::class, 'transfer'])->middleware('can:refunds.refund.execute')->name('transfer');
    Route::post('{number}/cancellations', [RefundController::class, 'cancel'])->middleware(['can:refunds.refund.review', 'can:refunds.refund.cancel'])->name('cancel');
});
