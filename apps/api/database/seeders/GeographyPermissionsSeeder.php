<?php

namespace Database\Seeders;

final class GeographyPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = [
        'geography.region.view', 'geography.region.create', 'geography.region.update', 'geography.region.import',
        'geography.reason.view', 'geography.reason.create', 'geography.reason.update',
        'geography.unit.view', 'geography.unit.create', 'geography.unit.update',
        'geography.tax.view', 'geography.tax.create', 'geography.tax.update',
    ];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin' => self::ACTIONS,
            'product_manager', 'order_staff' => ['geography.region.view', 'geography.reason.view', 'geography.unit.view', 'geography.tax.view'],
            'finance' => ['geography.reason.view', 'geography.tax.view'],
            default => [],
        };
    }
}
