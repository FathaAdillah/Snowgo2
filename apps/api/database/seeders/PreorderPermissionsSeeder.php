<?php

namespace Database\Seeders;

final class PreorderPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['preorders.campaign.view', 'preorders.campaign.manage', 'preorders.campaign.create', 'preorders.campaign.update',
        'preorders.campaign.schedule', 'preorders.campaign.open', 'preorders.campaign.close', 'preorders.campaign.cancel',
        'preorders.campaign.start_procurement', 'preorders.campaign.fulfill', 'preorders.campaign.complete'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin', 'order_staff' => self::ACTIONS,
            'product_manager', 'customer_service' => ['preorders.campaign.view'],
            default => [],
        };
    }
}
