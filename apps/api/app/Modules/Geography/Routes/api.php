<?php

use App\Modules\Geography\Http\Controllers\ImportController;
use App\Modules\Geography\Http\Controllers\PublicRegionsController;
use App\Modules\Geography\Http\Controllers\ReasonController;
use App\Modules\Geography\Http\Controllers\RegionController;
use App\Modules\Geography\Http\Controllers\TaxController;
use App\Modules\Geography\Http\Controllers\UnitController;
use App\Modules\Geography\Master;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use Illuminate\Support\Facades\Route;

Route::get('regions', PublicRegionsController::class)->name('public.regions');
Route::prefix('admin')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    foreach ([
        [Master::Region, RegionController::class], [Master::Reason, ReasonController::class],
        [Master::Unit, UnitController::class], [Master::Tax, TaxController::class],
    ] as [$master, $controller]) {
        $path = str_replace('_', '-', $master->table());
        Route::prefix($path)->name("geography.$path.")->group(function () use ($master, $controller) {
            Route::get('/', [$controller, 'index'])->middleware('can:'.$master->permission('view'))->name('index');
            Route::get('{id}', [$controller, 'show'])->whereNumber('id')->middleware('can:'.$master->permission('view'))->name('show');
            Route::post('/', [$controller, 'create'])->middleware('can:'.$master->permission('create'))->name('create');
            Route::patch('{id}', [$controller, 'update'])->whereNumber('id')->middleware('can:'.$master->permission('update'))->name('update');
        });
    }
    Route::post('region-imports', ImportController::class)->middleware('can:geography.region.import')->name('geography.regions.import');
});
