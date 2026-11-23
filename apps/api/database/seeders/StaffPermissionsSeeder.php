<?php

namespace Database\Seeders;

final class StaffPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['admin.staff.view', 'admin.staff.create', 'admin.staff.update', 'admin.staff.assign_roles', 'admin.staff.change_status', 'admin.staff.sessions.delete'];
}
