<?php

use App\Modules\Foundation\Http\Controllers\LivenessController;
use App\Modules\Foundation\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('health', LivenessController::class)->name('foundation.health');

// Internal probes are blocked at the edge; see infrastructure/nginx.
Route::get('internal/readiness', ReadinessController::class)->name('foundation.readiness');
