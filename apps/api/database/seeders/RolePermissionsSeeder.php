<?php

namespace Database\Seeders;

final class RolePermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['admin.roles.view', 'admin.roles.create', 'admin.roles.update', 'admin.roles.delete', 'admin.roles.assign_permissions'];
}
