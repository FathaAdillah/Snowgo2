<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Invoicing\Http\InvoiceActions;
use App\Modules\Invoicing\Http\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('invoices')->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('invoices.customer.')->group(function () {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::get('{number}', [InvoiceController::class, 'show'])->name('show');
    Route::get('{number}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
});
Route::prefix('admin')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:invoicing.invoice.view'])->name('invoices.staff.')->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index'])->name('index');
    Route::get('invoices/{number}', [InvoiceController::class, 'show'])->name('show');
    Route::get('invoices/{number}/pdf', [InvoiceController::class, 'pdf'])->middleware(['can:invoicing.invoice.download', 'can:foundation.file.view', 'can:foundation.file.download'])->name('pdf');
    Route::middleware('can:invoicing.invoice.manage')->group(function () {
        Route::post('orders/{number}/invoices', [InvoiceActions::class, 'issue'])->middleware('can:invoicing.invoice.issue')->name('issue');
        Route::post('invoices/{number}/voids', [InvoiceActions::class, 'void'])->middleware('can:invoicing.invoice.void')->name('void');
        Route::post('invoices/{number}/due-date-revisions', [InvoiceActions::class, 'reviseDue'])->middleware('can:invoicing.invoice.revise_due')->name('revise');
        Route::post('invoices/{number}/pdf-requests', [InvoiceActions::class, 'retry'])->middleware('can:invoicing.invoice.retry_pdf')->name('retry');
    });
});
