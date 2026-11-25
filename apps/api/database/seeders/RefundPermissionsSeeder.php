<?php

namespace Database\Seeders;

final class RefundPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['refunds.refund.view', 'refunds.refund.create', 'refunds.refund.review', 'refunds.refund.execute', 'refunds.refund.cancel', 'refunds.shortage.review'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'finance' => self::ACTIONS,
            'customer_service', 'admin' => ['refunds.refund.view', 'refunds.refund.create'],
            default => [],
        };
    }
}
