<?php

namespace Database\Seeders;

final class ShippingPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['orders.packing.manage', 'shipping.courier.archive', 'shipping.courier.create', 'shipping.courier.manage', 'shipping.courier.update', 'shipping.courier.view', 'shipping.quote.create', 'shipping.rate.archive', 'shipping.rate.create', 'shipping.rate.manage', 'shipping.rate.update', 'shipping.rate.view', 'shipping.service.archive', 'shipping.service.create', 'shipping.service.manage', 'shipping.service.update', 'shipping.service.view', 'shipping.shipment.cancel', 'shipping.shipment.create', 'shipping.shipment.dispatch', 'shipping.shipment.manage', 'shipping.shipment.view'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin' => self::ACTIONS,
            'warehouse' => array_values(array_filter(self::ACTIONS, fn ($k) => str_contains($k, 'shipment.') || str_ends_with($k, '.view') || in_array($k, ['shipping.quote.create', 'orders.packing.manage'], true))),
            'order_staff' => ['shipping.shipment.view', 'orders.packing.manage', 'shipping.courier.view', 'shipping.service.view', 'shipping.rate.view'],
            'customer_service' => ['shipping.shipment.view'],
            default => [],
        };
    }
}
