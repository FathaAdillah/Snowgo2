<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\RequestOrders\Http\CaseController;
use App\Modules\RequestOrders\Http\QuotationController;
use App\Modules\RequestOrders\Http\StaffCaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('request-orders')->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('requests.')->group(function () {
    Route::get('/', [CaseController::class, 'index'])->name('index');
    Route::post('/', [CaseController::class, 'create'])->middleware('throttle:5,1,request-submit:')->name('create');
    Route::get('{number}', [CaseController::class, 'show'])->name('show');
    Route::post('{number}/quotations/{quotation_number}/decisions', [QuotationController::class, 'decide'])->middleware('throttle:10,1,quotation-decision:')->name('quotation.decide');
    Route::post('{number}/messages', [CaseController::class, 'message'])->middleware('throttle:20,1,request-discuss:')->name('message');
    Route::post('{number}/cancellations', [CaseController::class, 'cancel'])->middleware('throttle:10,1,request-cancel:')->name('cancel');
});
Route::prefix('admin/request-orders')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:request_orders.request.view'])->name('requests.staff.')->group(function () {
    Route::get('/', [StaffCaseController::class, 'index'])->name('index');
    Route::get('{number}', [StaffCaseController::class, 'show'])->name('show');
    Route::prefix('{number}/quotations')->middleware(['can:request_orders.quotation.view', 'can:request_orders.quotation.manage'])->name('quotation.')->group(function () {
        Route::post('/', [QuotationController::class, 'create'])->middleware('can:request_orders.quotation.create')->name('create');
        Route::patch('{quotation_number}', [QuotationController::class, 'update'])->middleware('can:request_orders.quotation.update')->name('update');
        Route::post('{quotation_number}/dispatches', [QuotationController::class, 'dispatch'])->middleware('can:request_orders.quotation.dispatch')->name('dispatch');
        Route::post('{quotation_number}/withdrawals', [QuotationController::class, 'withdraw'])->middleware('can:request_orders.quotation.withdraw')->name('withdraw');
    });
    Route::middleware('can:request_orders.request.review')->group(function () {
        Route::post('{number}/reviews', [StaffCaseController::class, 'review'])->name('review');
        Route::post('{number}/messages', [StaffCaseController::class, 'message'])->name('message');
    });
});
