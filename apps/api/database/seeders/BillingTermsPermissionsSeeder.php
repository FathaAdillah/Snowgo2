<?php

namespace Database\Seeders;

final class BillingTermsPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['orders.charge.propose', 'invoicing.invoice.credit_adjust'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin' => self::ACTIONS,'order_staff' => ['orders.charge.propose'],'finance' => ['invoicing.invoice.credit_adjust'],default => []
        };
    }
}
