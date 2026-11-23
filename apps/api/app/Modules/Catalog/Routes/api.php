<?php

use App\Modules\Catalog\Http\PublicCatalog;
use App\Modules\Catalog\Resource;
use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use Illuminate\Support\Facades\Route;

Route::get('products', [PublicCatalog::class, 'products'])->name('public.products');
Route::get('products/{slug}', [PublicCatalog::class, 'detail'])->where('slug', '[a-z0-9-]{1,200}')->name('public.product-detail');
Route::get('categories', [PublicCatalog::class, 'categories'])->name('public.categories');
Route::prefix('admin')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff'])->group(function () {
    foreach (Resource::cases() as $resource) {
        $controller = 'App\\Modules\\Catalog\\Http\\'.$resource->name.'Controller';
        Route::prefix($resource->path())->name('catalog.'.$resource->path().'.')->group(function () use ($resource, $controller) {
            Route::get('/', [$controller, 'index'])->middleware('can:'.$resource->permission('view'))->name('index');
            Route::get('{id}', [$controller, 'show'])->whereNumber('id')->middleware('can:'.$resource->permission('view'))->name('show');
            Route::post('/', [$controller, 'create'])->middleware('can:'.$resource->permission('create'))->name('create');
            Route::patch('{id}', [$controller, 'update'])->whereNumber('id')->middleware('can:'.$resource->permission('update'))->name('update');
        });
    }
});
