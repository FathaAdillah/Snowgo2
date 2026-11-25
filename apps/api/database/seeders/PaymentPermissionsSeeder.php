<?php

namespace Database\Seeders;

final class PaymentPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['payments.bank.view', 'payments.bank.manage', 'payments.bank.create', 'payments.bank.update', 'payments.payment.view', 'payments.payment.review', 'payments.payment.verify', 'payments.proof.view'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin','finance' => self::ACTIONS,
            'customer_service' => ['payments.payment.view'],
            default => [],
        };
    }
}
