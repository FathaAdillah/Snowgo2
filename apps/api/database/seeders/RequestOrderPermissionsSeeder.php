<?php

namespace Database\Seeders;

final class RequestOrderPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['request_orders.request.view', 'request_orders.request.review', 'request_orders.request.start', 'request_orders.request.need_information',
        'request_orders.request.reject', 'request_orders.request.reopen', 'request_orders.request.assign', 'request_orders.request.reply', 'request_orders.request.note',
        'request_orders.quotation.view', 'request_orders.quotation.manage', 'request_orders.quotation.create', 'request_orders.quotation.update', 'request_orders.quotation.dispatch', 'request_orders.quotation.withdraw'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        if ($role === 'customer_service') {
            return array_values(array_filter(self::ACTIONS, fn ($p) => ! str_starts_with($p, 'request_orders.quotation.') || $p === 'request_orders.quotation.view'));
        }

        return in_array($role, ['admin', 'order_staff'], true) ? self::ACTIONS : [];
    }
}
