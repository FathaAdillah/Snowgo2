<?php

namespace Database\Seeders;

final class DashboardPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['reporting.dashboard.view', 'admin.profile.view'];
}
