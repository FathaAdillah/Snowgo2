<?php

use App\Modules\Administration\Http\Middleware\LocalOperatorAccess;
use App\Modules\Identity\Staff\StaffAccess;
use Illuminate\Support\Facades\Route;

if (app()->environment('local')) {
    Route::get('/internal/operator-db-access', fn () => response()->noContent())
        ->middleware(LocalOperatorAccess::class.':'.StaffAccess::DB);
}
