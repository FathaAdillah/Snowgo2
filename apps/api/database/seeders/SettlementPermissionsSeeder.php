<?php

namespace Database\Seeders;

final class SettlementPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['payments.payment.recover', 'payments.reconciliation.review', 'payments.reconciliation.view'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'finance' => self::ACTIONS,'admin' => ['payments.reconciliation.view'],default => []
        };
    }
}
