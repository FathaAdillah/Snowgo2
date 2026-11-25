<?php

namespace Database\Seeders;

final class ReturnPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['returns.return.view', 'returns.return.create', 'returns.return.review', 'returns.return.inspect', 'returns.return.resolve'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'warehouse' => ['returns.return.view', 'returns.return.inspect'],
            'customer_service','admin' => ['returns.return.view', 'returns.return.create', 'returns.return.review', 'returns.return.resolve'],
            default => [],
        };
    }
}
