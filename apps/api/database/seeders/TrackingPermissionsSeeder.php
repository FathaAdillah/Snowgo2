<?php

namespace Database\Seeders;

final class TrackingPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['shipping.tracking.manage'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return in_array($role, ['admin', 'order_staff', 'warehouse'], true) ? self::ACTIONS : [];
    }
}
