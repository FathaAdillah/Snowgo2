<?php

use App\Modules\Administration\Http\Controllers\RoleController;
use App\Modules\Administration\Http\Controllers\StaffAccountController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/staff')->name('admin.staff.')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    Route::get('/', [StaffAccountController::class, 'index'])->middleware('can:admin.staff.view')->name('index');
    Route::get('role-options', [RoleController::class, 'options'])->middleware('can:admin.staff.assign_roles')->name('role-options');
    Route::post('/', [StaffAccountController::class, 'create'])->middleware(['can:admin.staff.create', 'can:admin.staff.assign_roles'])->name('create');
    Route::patch('{id}', [StaffAccountController::class, 'update'])->whereNumber('id')->middleware('can:admin.staff.update')->name('update');
    Route::delete('{id}/sessions', [StaffAccountController::class, 'revoke'])->whereNumber('id')->middleware('can:admin.staff.sessions.delete')->name('revoke');
});

Route::prefix('admin')->name('admin.roles.')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    Route::get('permissions', [RoleController::class, 'catalog'])->middleware('can:admin.roles.view')->name('catalog');
    Route::get('roles', [RoleController::class, 'index'])->middleware('can:admin.roles.view')->name('index');
    Route::get('roles/{id}', [RoleController::class, 'show'])->whereNumber('id')->middleware('can:admin.roles.view')->name('show');
    Route::post('roles', [RoleController::class, 'create'])->middleware(['can:admin.roles.create', 'can:admin.roles.assign_permissions'])->name('create');
    Route::patch('roles/{id}', [RoleController::class, 'update'])->whereNumber('id')->middleware(['can:admin.roles.update', 'can:admin.roles.assign_permissions'])->name('update');
    Route::delete('roles/{id}', [RoleController::class, 'destroy'])->whereNumber('id')->middleware('can:admin.roles.delete')->name('destroy');
});
