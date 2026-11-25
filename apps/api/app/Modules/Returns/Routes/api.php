<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Returns\Http\ReturnController;
use Illuminate\Support\Facades\Route;

Route::prefix('returns')->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('returns.customer.')->group(function () {
    Route::get('/', [ReturnController::class, 'index'])->name('index');
    Route::get('{number}', [ReturnController::class, 'show'])->name('show');
    Route::post('/', [ReturnController::class, 'create'])->name('create');
    Route::post('{number}/dispatches', [ReturnController::class, 'dispatch'])->name('dispatch');
});
Route::prefix('admin/returns')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:returns.return.view'])->name('returns.staff.')->group(function () {
    Route::get('/', [ReturnController::class, 'index'])->name('index');
    Route::get('{number}', [ReturnController::class, 'show'])->name('show');
    Route::post('/', [ReturnController::class, 'create'])->middleware('can:returns.return.create')->name('create');
    Route::post('{number}/reviews', [ReturnController::class, 'review'])->middleware('can:returns.return.review')->name('review');
    Route::post('{number}/receipts', [ReturnController::class, 'receive'])->middleware('can:returns.return.inspect')->name('receive');
    Route::post('{number}/inspections', [ReturnController::class, 'inspect'])->middleware('can:returns.return.inspect')->name('inspect');
    Route::post('{number}/resolutions', [ReturnController::class, 'resolve'])->middleware(['can:returns.return.review', 'can:returns.return.resolve'])->name('resolve');
});
