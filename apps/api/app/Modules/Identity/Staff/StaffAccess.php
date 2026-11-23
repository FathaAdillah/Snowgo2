<?php

namespace App\Modules\Identity\Staff;

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Surface;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class StaffAccess
{
    public const SUPER = 'super_admin';

    public const LOGS = 'operator.logs.view';

    public const DB = 'operator.database.open';

    public const PASSWORD_ONLY_VERSION = 'staff.password_only_version';

    public const DEFAULT_ROLES = ['admin', 'product_manager', 'inventory_staff', 'order_staff', 'finance', 'customer_service', 'warehouse', 'content_manager'];

    public static function super(User $user): bool
    {
        return $user->account_type === User::STAFF && $user->roles->contains(fn ($role) => $role->getAttribute('name') === self::SUPER
            && $role->getAttribute('guard_name') === 'web' && $role->getAttribute('bypasses_permissions'));
    }

    public static function active(User $user, Request $request): bool
    {
        return $user->account_type === User::STAFF && $user->disabled_at === null && $user->two_factor_confirmed_at !== null
            && DeviceSessions::surface($request) === Surface::Staff
            && ($request->session()->has(self::PASSWORD_ONLY_VERSION)
                ? self::passwordOnly($user) && $request->session()->get(self::PASSWORD_ONLY_VERSION) === $user->staff_session_version
                : $request->session()->get('staff.version') === $user->staff_session_version)
            && $request->session()->get('staff.started', 0) > now()->subHours(12)->timestamp
            && $request->session()->get('staff.seen', 0) > now()->subMinutes(30)->timestamp;
    }

    public static function passwordOnly(User $user): bool
    {
        return $user->staff_password_only && ! self::super($user);
    }

    /** @return list<string> */
    public static function permissions(User $user): array
    {
        return (self::super($user) ? Permission::query()->where('guard_name', 'web')->pluck('name')
            : $user->getAllPermissions()->where('guard_name', 'web')->pluck('name')->diff([self::LOGS, self::DB]))->sort()->values()->all();
    }

    /** Registered RBAC names only; never skip a resource policy or an operator-console gate. */
    public static function check(User $user, string $ability): ?bool
    {
        $permission = app(PermissionRegistrar::class)->getPermissions(['name' => $ability, 'guard_name' => 'web'], true)->first();
        if (! $permission) {
            return null;
        }

        return self::active($user, request()) && (self::super($user)
            || (! in_array($ability, [self::LOGS, self::DB], true) && $user->hasPermissionTo($permission)));
    }
}
