<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Payments\Http\BankController;
use App\Modules\Payments\Http\PaymentController;
use App\Modules\Payments\Http\ReconciliationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', EnsureIdentity::class.':customer'])->name('payments.customer.')->group(function () {
    Route::get('bank-accounts', [BankController::class, 'active'])->name('banks');
    Route::get('payments', [PaymentController::class, 'index'])->name('index');
    Route::get('payments/{number}', [PaymentController::class, 'show'])->name('show');
    Route::post('payments', [PaymentController::class, 'create'])->name('create');
    Route::post('payments/{number}/proofs', [PaymentController::class, 'proof'])->name('proof');
    Route::post('payments/{number}/cancellations', [PaymentController::class, 'cancel'])->name('cancel');
});
Route::prefix('admin')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->name('payments.staff.')->group(function () {
    Route::middleware('can:payments.bank.view')->group(function () {
        Route::get('bank-accounts', [BankController::class, 'index'])->name('banks');
        Route::get('bank-accounts/{id}', [BankController::class, 'show'])->whereNumber('id')->name('bank');
        Route::post('bank-accounts', [BankController::class, 'create'])->middleware(['can:payments.bank.manage', 'can:payments.bank.create'])->name('bank.create');
        Route::patch('bank-accounts/{id}', [BankController::class, 'update'])->whereNumber('id')->middleware(['can:payments.bank.manage', 'can:payments.bank.update'])->name('bank.update');
    });
    Route::middleware('can:payments.payment.view')->group(function () {
        Route::get('payments', [PaymentController::class, 'index'])->name('index');
        Route::get('payments/{number}', [PaymentController::class, 'show'])->name('show');
        Route::post('payments/{number}/review-claims', [PaymentController::class, 'claim'])->middleware('can:payments.payment.review')->name('claim');
        Route::post('payments/{number}/decisions', [PaymentController::class, 'decide'])->middleware('can:payments.payment.verify')->name('decision');
    });
    Route::middleware('can:payments.reconciliation.view')->group(function () {
        Route::get('payment-reconciliations', [ReconciliationController::class, 'index'])->name('reconciliations');
        Route::get('payment-reconciliations/{id}', [ReconciliationController::class, 'show'])->whereNumber('id')->name('reconciliation');
        Route::post('payments/{number}/reconciliations', [ReconciliationController::class, 'open'])->middleware('can:payments.payment.recover')->name('reconciliation.open');
        Route::post('payment-reconciliations/{id}/reviews', [ReconciliationController::class, 'review'])->whereNumber('id')->middleware('can:payments.reconciliation.review')->name('reconciliation.review');
        Route::post('payment-reconciliations/{id}/decisions', [ReconciliationController::class, 'decide'])->whereNumber('id')->middleware('can:payments.payment.recover')->name('reconciliation.decide');
    });
});
