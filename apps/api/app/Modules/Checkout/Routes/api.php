<?php

use App\Modules\Checkout\Http\CheckoutController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', EnsureIdentity::class.':customer'])->name('checkout.')->group(function () {
    Route::post('checkout/previews', [CheckoutController::class, 'preview'])->middleware('throttle:10,1,checkout-preview:')->name('preview');
    Route::post('checkout', [CheckoutController::class, 'submit'])->middleware('throttle:10,1,checkout-submit:')->name('submit');
    Route::get('checkouts/{id}', [CheckoutController::class, 'show'])->whereUlid('id')->name('show');
});
