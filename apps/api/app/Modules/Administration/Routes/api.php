<?php

use App\Modules\Administration\Http\Controllers\StaffAccountController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/staff')->name('admin.staff.')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    Route::get('/', [StaffAccountController::class, 'index'])->middleware('can:admin.staff.view')->name('index');
    Route::post('/', [StaffAccountController::class, 'create'])->middleware(['can:admin.staff.create', 'can:admin.staff.assign_roles'])->name('create');
    Route::patch('{id}', [StaffAccountController::class, 'update'])->whereNumber('id')->middleware('can:admin.staff.update')->name('update');
    Route::delete('{id}/sessions', [StaffAccountController::class, 'revoke'])->whereNumber('id')->middleware('can:admin.staff.sessions.delete')->name('revoke');
});
