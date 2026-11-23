<?php

namespace Database\Seeders;

final class ProfilePermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['admin.profile.update', 'admin.profile.change_email', 'admin.profile.change_password', 'admin.profile.manage_mfa'];
}
