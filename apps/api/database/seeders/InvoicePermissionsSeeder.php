<?php

namespace Database\Seeders;

final class InvoicePermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['invoicing.invoice.view', 'invoicing.invoice.download', 'invoicing.invoice.manage', 'invoicing.invoice.void', 'invoicing.invoice.issue', 'invoicing.invoice.revise_due', 'invoicing.invoice.retry_pdf'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin','finance' => self::ACTIONS,
            'order_staff','customer_service' => ['invoicing.invoice.view', 'invoicing.invoice.download'],
            default => []
        };
    }
}
