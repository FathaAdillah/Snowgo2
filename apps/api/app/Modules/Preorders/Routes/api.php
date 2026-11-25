<?php

use App\Modules\Identity\Http\Middleware\EnsureIdentity;
use App\Modules\Identity\Http\Middleware\StaffOrigin;
use App\Modules\Preorders\CampaignWindow;
use App\Modules\Preorders\Http\CampaignController;
use App\Modules\Preorders\Http\StaffCampaignController;
use Illuminate\Support\Facades\Route;

Route::prefix('preorders')->name('preorders.')->group(function () {
    Route::get('/', [CampaignController::class, 'index'])->name('index');
    Route::get('{slug}', [CampaignController::class, 'show'])->name('show');
    Route::get('{slug}/allocations', [CampaignController::class, 'allocations'])->middleware(['auth:web', EnsureIdentity::class.':customer'])->name('allocations');
});
Route::prefix('admin/preorder-campaigns')->name('preorders.staff.')->middleware([StaffOrigin::class, 'auth:web', EnsureIdentity::class.':staff', 'can:preorders.campaign.view'])->group(function () {
    Route::get('/', [StaffCampaignController::class, 'index'])->name('index');
    Route::get('{number}', [StaffCampaignController::class, 'show'])->name('show');
    Route::middleware('can:preorders.campaign.manage')->group(function () {
        Route::post('/', [StaffCampaignController::class, 'create'])->middleware('can:preorders.campaign.create')->name('create');
        Route::patch('{number}', [StaffCampaignController::class, 'update'])->middleware('can:preorders.campaign.update')->name('update');
        Route::post('{number}/actions/{action}', [StaffCampaignController::class, 'action'])->whereIn('action', CampaignWindow::ACTIONS)->name('action');
    });
});
