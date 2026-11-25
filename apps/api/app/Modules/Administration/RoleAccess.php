<?php

namespace App\Modules\Administration;

use App\Models\User;
use App\Modules\Identity\Staff\StaffAccess;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RoleAccess
{
    /** @return list<string> */
    public static function assignable(User $actor): array
    {
        return array_values(array_diff(StaffAccess::permissions($actor), StaffAccess::OPERATOR_TOOLS));
    }

    /** @param list<string> $available */
    public static function editable(Role $role, array $available): bool
    {
        return $role->name !== StaffAccess::SUPER && ! $role->getAttribute('bypasses_permissions')
            && $role->permissions->pluck('name')->diff($available)->isEmpty();
    }

    /** @param list<string> $available
     * @return array<string,mixed>
     */
    public static function card(Role $role, array $available): array
    {
        $system = $role->name === StaffAccess::SUPER;
        $editable = self::editable($role, $available);
        $members = (int) $role->getAttribute('users_count');

        return ['id' => $role->getKey(), 'name' => $role->name,
            'display_name' => $role->getAttribute('display_name') ?? Str::headline($role->name),
            'description' => $role->getAttribute('description') ?? '', 'revision' => $role->getAttribute('revision'),
            'members' => $members, 'system' => $system, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'editable' => $editable, 'deletable' => $editable && ! $members && ! in_array($role->name, StaffAccess::DEFAULT_ROLES, true)];
    }

    /** @return list<array{name:string,section:string,resource:string,action:string,assignable:bool}> */
    public static function catalog(User $actor): array
    {
        $available = self::assignable($actor);

        return Permission::query()->where('guard_name', 'web')->whereNotIn('name', StaffAccess::OPERATOR_TOOLS)
            ->orderBy('name')->pluck('name')->map(static function (string $name) use ($available) {
                $parts = explode('.', $name);
                $section = array_shift($parts);
                $action = array_pop($parts) ?? $name;

                return ['name' => $name, 'section' => $section, 'resource' => implode('.', $parts) ?: $section,
                    'action' => $action, 'assignable' => in_array($name, $available, true)];
            })->values()->all();
    }
}
