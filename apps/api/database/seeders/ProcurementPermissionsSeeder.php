<?php

namespace Database\Seeders;

final class ProcurementPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['procurement.batch.view', 'procurement.batch.manage', 'procurement.batch.create', 'procurement.batch.stage', 'procurement.batch.receipt', 'procurement.batch.close'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin', 'order_staff' => self::ACTIONS,
            'warehouse' => ['procurement.batch.view'],
            default => [],
        };
    }
}
