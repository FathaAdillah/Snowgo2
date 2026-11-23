<?php

namespace Database\Seeders;

final class CustomerPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['customers.customer.view', 'customers.customer.pii_view'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin', 'customer_service' => self::ACTIONS,
            'order_staff' => ['customers.customer.view'],
            default => [],
        };
    }
}
