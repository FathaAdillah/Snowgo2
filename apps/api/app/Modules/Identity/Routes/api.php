<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\ChallengesController;
use App\Modules\Identity\Http\Controllers\GoogleController;
use App\Modules\Identity\Http\Controllers\SessionsController;
use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Identity\Http\Controllers\StaffProfileController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::prefix('admin/auth')->name('staff.auth.')->middleware(StaffOrigin::class)->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])->middleware('throttle:identity-challenges')->block()->name('login');
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('mfa-enrollments', [StaffAuthController::class, 'enroll'])->block()->name('enroll');
        Route::post('mfa-enrollment-verifications', [StaffAuthController::class, 'confirm'])->block()->name('confirm');
    });
    Route::post('mfa-challenges', [StaffAuthController::class, 'verify'])->middleware('throttle:staff-mfa')->block()->name('verify');
    Route::middleware(['auth:web', EnsureIdentity::class.':staff'])->group(function () {
        Route::get('me', [StaffAuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->block()->name('logout');
        Route::post('step-up', [StaffAuthController::class, 'stepUp'])->middleware('throttle:5,1')->block()->name('step-up');
        Route::prefix('profile')->middleware(['can:admin.profile.view', 'throttle:5,1'])->group(function () {
            Route::post('name', [StaffProfileController::class, 'name'])->middleware('can:admin.profile.update')->block()->name('profile.name');
            Route::post('password', [StaffProfileController::class, 'password'])->middleware('can:admin.profile.change_password')->block()->name('profile.password');
            Route::post('mfa', [StaffProfileController::class, 'mfa'])->middleware('can:admin.profile.manage_mfa')->block()->name('profile.mfa');
            Route::post('email', [StaffProfileController::class, 'email'])->middleware('can:admin.profile.change_email')->block()->name('profile.email');
            Route::post('email/confirm', [StaffProfileController::class, 'confirmEmail'])->middleware('can:admin.profile.change_email')->block()->name('profile.email.confirm');
        });
    });
});

// Module routes are mounted under /api/v1 by the module service provider.
Route::prefix('auth')->name('identity.')->group(function () {
    // Cross-site top-level callback has no first-party Origin; start its cookie session explicitly.
    Route::prefix('google')->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->middleware(['web', 'throttle:10,1'])->group(function () {
            Route::get('redirect', [GoogleController::class, 'redirect'])->block(30)->name('google.redirect');
            Route::get('callback', [GoogleController::class, 'callback'])->block(30)->name('google.callback');
        });
    Route::post('google/links', [GoogleController::class, 'confirm'])
        ->middleware(['auth:web', EnsureIdentity::class.':customer-unverified', 'throttle:5,1'])->name('google.confirm');
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
