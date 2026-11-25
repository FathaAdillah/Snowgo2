<?php

namespace Database\Seeders;

final class OrderPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['orders.order.view', 'orders.order.manage', 'orders.order.cancel', 'orders.order.complete'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin', 'order_staff' => self::ACTIONS,
            'customer_service', 'warehouse' => ['orders.order.view'],
            default => [],
        };
    }
}
