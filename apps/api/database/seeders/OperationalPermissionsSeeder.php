<?php

namespace Database\Seeders;

final class OperationalPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['admin.audit.view', 'admin.setting.view', 'admin.setting.manage'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin' => self::ACTIONS,'finance' => ['admin.audit.view'],default => []
        };
    }
}
