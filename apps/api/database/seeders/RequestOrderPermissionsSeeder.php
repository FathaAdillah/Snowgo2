<?php

namespace Database\Seeders;

final class RequestOrderPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['request_orders.request.view', 'request_orders.request.review', 'request_orders.request.start', 'request_orders.request.need_information',
        'request_orders.request.reject', 'request_orders.request.reopen', 'request_orders.request.assign', 'request_orders.request.reply', 'request_orders.request.note'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return in_array($role, ['admin', 'order_staff', 'customer_service'], true) ? self::ACTIONS : [];
    }
}
