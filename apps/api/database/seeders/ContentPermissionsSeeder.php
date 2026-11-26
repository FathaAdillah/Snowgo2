<?php

namespace Database\Seeders;

final class ContentPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['content.entry.view', 'content.entry.create', 'content.entry.update', 'content.entry.publish', 'content.entry.archive'];

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return in_array($role, ['content_manager', 'admin'], true) ? self::ACTIONS : [];
    }
}
