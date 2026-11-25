<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Procurement\Http\BatchController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/procurement-batches')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:procurement.batch.view'])->name('procurement.')->group(function () {
    Route::get('/', [BatchController::class, 'index'])->name('index');
    Route::get('eligible-items', [BatchController::class, 'choices'])->middleware(['can:procurement.batch.manage', 'can:procurement.batch.create'])->name('choices');
    Route::get('{number}', [BatchController::class, 'show'])->name('show');
    Route::middleware('can:procurement.batch.manage')->group(function () {
        Route::post('/', [BatchController::class, 'create'])->middleware('can:procurement.batch.create')->name('create');
        Route::post('{number}/stage-events', [BatchController::class, 'stage'])->middleware('can:procurement.batch.stage')->name('stage');
        Route::post('{number}/receipts', [BatchController::class, 'receipt'])->middleware('can:procurement.batch.receipt')->name('receipt');
        Route::post('{number}/closures', [BatchController::class, 'close'])->middleware('can:procurement.batch.close')->name('close');
    });
});
