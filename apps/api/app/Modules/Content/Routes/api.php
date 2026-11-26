<?php

use App\Modules\Content\ContentController;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use Illuminate\Support\Facades\Route;

Route::get('content',[ContentController::class,'directory'])->name('content.directory');
Route::get('content/{slug}', [ContentController::class, 'published'])->name('content.published');
Route::prefix('admin/content')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    Route::get('/', [ContentController::class, 'index'])->middleware('can:content.entry.view')->name('content.index');
    Route::get('{id}', [ContentController::class, 'show'])->whereNumber('id')->middleware('can:content.entry.view')->name('content.show');
    Route::post('/', [ContentController::class, 'create'])->middleware('can:content.entry.create')->name('content.create');
    Route::patch('{id}', [ContentController::class, 'update'])->whereNumber('id')->middleware('can:content.entry.update')->name('content.update');
});
