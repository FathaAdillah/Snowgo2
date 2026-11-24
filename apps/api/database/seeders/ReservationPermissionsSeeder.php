<?php

namespace Database\Seeders;

final class ReservationPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['inventory.reservation.view'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return in_array($role, ['admin', 'inventory_staff', 'order_staff', 'warehouse'], true) ? self::ACTIONS : [];
    }
}
