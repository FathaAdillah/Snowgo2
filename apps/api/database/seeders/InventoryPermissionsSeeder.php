<?php

namespace Database\Seeders;

final class InventoryPermissionsSeeder extends FeaturePermissions
{
    private const OPERATIONS = ['inventory.warehouse.view', 'inventory.stock.view', 'inventory.movement.view',
        'inventory.adjustment.view', 'inventory.receipt.create', 'inventory.adjustment.create'];

    protected const ACTIONS = [...self::OPERATIONS, 'inventory.warehouse.create', 'inventory.warehouse.update', 'inventory.warehouse.archive'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin', 'inventory_staff' => self::ACTIONS,
            'warehouse' => self::OPERATIONS,
            'product_manager' => ['inventory.stock.view'],
            default => [],
        };
    }
}
