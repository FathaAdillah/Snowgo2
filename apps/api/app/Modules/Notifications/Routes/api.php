<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Notifications\Http\InboxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', EnsureIdentity::class.':customer'])->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');
    Route::put('{id}/read-receipt', [InboxController::class, 'read'])->whereUlid('id')->name('read');
});
