<?php

namespace Database\Seeders;

final class ReportPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['reporting.transaction.view'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return in_array($role, ['finance', 'admin'], true) ? self::ACTIONS : [];
    }
}
