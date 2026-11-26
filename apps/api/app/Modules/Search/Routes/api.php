<?php

use App\Modules\Catalog\Http\PublicCatalog;
use App\Modules\Search\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('search', [PublicCatalog::class, 'products'])->name('public.search');
Route::get('sitemap/products', [SitemapController::class, 'products'])->name('public.sitemap-products');
