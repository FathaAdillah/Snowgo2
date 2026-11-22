<?php

use App\Modules\Foundation\Http\Controllers\FilesController;
use App\Modules\Foundation\Http\Controllers\LivenessController;
use App\Modules\Foundation\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('health', LivenessController::class)->name('foundation.health');

// Internal probes are blocked at the edge; see infrastructure/nginx.
Route::get('internal/readiness', ReadinessController::class)->name('foundation.readiness');

// Surface gates are supplied by Identity (verified customer / MFA-verified staff); absent gates deny.
foreach (['customer' => 'files', 'staff' => 'admin/files'] as $surface => $prefix) {
    Route::prefix($prefix)->name($surface.'.files.')->middleware(['auth:web', "can:files.surface.$surface", 'throttle:60,1'])
        ->group(function () use ($surface) {
            $upload = Route::post('/', [FilesController::class, 'upload'])->name('upload');
            $status = Route::get('{id}', [FilesController::class, 'show'])->whereUlid('id')->name('show');
            $download = Route::get('{id}/download', [FilesController::class, 'download'])->whereUlid('id')->name('download');
            if ($surface === 'staff') {
                $upload->middleware('can:foundation.file.manage');
                $status->middleware('can:foundation.file.view');
                $download->middleware('can:foundation.file.view');
            }
        });
}
