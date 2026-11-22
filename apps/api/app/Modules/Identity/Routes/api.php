<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\ChallengesController;
use App\Modules\Identity\Http\Controllers\SessionsController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use Illuminate\Support\Facades\Route;

// Module routes are mounted under /api/v1 by the module service provider.
Route::prefix('auth')->name('identity.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::middleware('throttle:identity-challenges')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('email-verifications', [ChallengesController::class, 'verify'])->name('email-verifications');
        Route::post('password-reset-requests', [ChallengesController::class, 'requestReset'])->name('password-reset-requests');
        Route::post('password-resets', [ChallengesController::class, 'reset'])->name('password-resets');
    });
    Route::post('email-verification-requests', [ChallengesController::class, 'requestVerification'])
        ->middleware(['auth:web', EnsureIdentity::class.':customer-unverified', 'throttle:identity-resend'])
        ->name('email-verification-requests');

    Route::middleware(['auth:web', EnsureIdentity::class.':session'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('sessions', [SessionsController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{sessionRef}', [SessionsController::class, 'destroy'])->name('sessions.destroy');
    });
});
