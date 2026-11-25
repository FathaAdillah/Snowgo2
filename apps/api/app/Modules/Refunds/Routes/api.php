<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Refunds\Http\RefundController;
use App\Modules\Refunds\Http\ShortageController;
use Illuminate\Support\Facades\Route;

Route::prefix('refunds')->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('refunds.customer.')->group(function () {
    Route::get('/', [RefundController::class, 'index'])->name('index');
    Route::get('{number}', [RefundController::class, 'show'])->name('show');
    Route::post('/', [RefundController::class, 'create'])->name('create');
});
Route::prefix('admin/refunds')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:refunds.refund.view'])->name('refunds.staff.')->group(function () {
    Route::get('/', [RefundController::class, 'index'])->name('index');
    Route::get('supplier-shortages', [ShortageController::class, 'index'])->middleware('can:refunds.shortage.review')->name('shortage.index');
    Route::post('supplier-shortages/{receipt}/dismissals', [ShortageController::class, 'dismiss'])->middleware('can:refunds.shortage.review')->whereNumber('receipt')->name('shortage.dismiss');
    Route::post('supplier-shortages/{receipt}/refunds', [ShortageController::class, 'refund'])->middleware(['can:refunds.shortage.review', 'can:refunds.refund.create'])->whereNumber('receipt')->name('shortage.refund');
    Route::get('{number}', [RefundController::class, 'show'])->name('show');
    Route::post('/', [RefundController::class, 'create'])->middleware('can:refunds.refund.create')->name('create');
    Route::post('{number}/reviews', [RefundController::class, 'review'])->middleware('can:refunds.refund.review')->name('review');
    Route::post('{number}/execution-attempts', [RefundController::class, 'attempt'])->middleware('can:refunds.refund.execute')->name('attempt');
    Route::post('{number}/transfers', [RefundController::class, 'transfer'])->middleware('can:refunds.refund.execute')->name('transfer');
    Route::post('{number}/cancellations', [RefundController::class, 'cancel'])->middleware(['can:refunds.refund.review', 'can:refunds.refund.cancel'])->name('cancel');
});
