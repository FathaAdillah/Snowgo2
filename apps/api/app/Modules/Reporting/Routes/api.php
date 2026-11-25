<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Reporting\Http\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('can:reporting.dashboard.view')->name('reporting.dashboard');
    Route::get('transaction-reports', [ReportController::class, 'transactions'])->middleware('can:reporting.transaction.view')->name('reporting.transactions');
});
