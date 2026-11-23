<?php

use App\Modules\Customers\Http\AddressController;
use App\Modules\Customers\Http\ProfileController;
use App\Modules\Customers\Http\SupportController;
use App\Modules\Customers\Http\WishlistController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', EnsureIdentity::class.':customer'])->name('customers.')->group(function () {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('addresses', [AddressController::class, 'create'])->name('addresses.create');
    Route::patch('addresses/{id}', [AddressController::class, 'update'])->whereUlid('id')->name('addresses.update');
    Route::delete('addresses/{id}', [AddressController::class, 'destroy'])->whereUlid('id')->name('addresses.destroy');
    Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::put('wishlist/{slug}', [WishlistController::class, 'store'])->where('slug', '[a-z0-9-]{1,200}')->name('wishlist.store');
    Route::delete('wishlist/{slug}', [WishlistController::class, 'destroy'])->where('slug', '[a-z0-9-]{1,200}')->name('wishlist.destroy');
});

Route::prefix('admin/customers')->name('support.customers.')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    Route::middleware('can:customers.customer.view')->group(function () {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::get('{id}', [SupportController::class, 'show'])->whereNumber('id')->name('show');
        Route::get('{id}/activity', [SupportController::class, 'activity'])->whereNumber('id')->name('activity');
    });
    Route::get('{id}/addresses', [SupportController::class, 'addresses'])->whereNumber('id')->middleware('can:customers.customer.pii_view')->name('addresses');
});
