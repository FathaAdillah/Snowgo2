<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Identity\Staff\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        Gate::define('viewHorizon', static fn (User $user): bool => StaffAccess::super($user) && $user->can(StaffAccess::HORIZON));
        // Horizon's default callback bypasses its gate in local environments.
        Horizon::auth(static fn (Request $request): bool => Gate::forUser($request->user('web'))->allows('viewHorizon'));
    }
}
