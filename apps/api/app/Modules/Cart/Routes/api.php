<?php

use App\Modules\Cart\Http\CartController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use Illuminate\Support\Facades\Route;

Route::prefix('cart')->name('cart.')->middleware(['auth:web', EnsureIdentity::class.':customer'])->group(function () {
    Route::get('/', [CartController::class, 'show'])->name('show');
    Route::post('items', [CartController::class, 'store'])->name('items.store');
    Route::patch('items/{id}', [CartController::class, 'update'])->whereUlid('id')->name('items.update');
    Route::delete('items/{id}', [CartController::class, 'destroy'])->whereUlid('id')->name('items.destroy');
});
