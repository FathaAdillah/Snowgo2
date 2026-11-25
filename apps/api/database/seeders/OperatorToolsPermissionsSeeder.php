<?php

namespace Database\Seeders;

use App\Modules\Identity\Staff\StaffAccess;

final class OperatorToolsPermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = StaffAccess::OPERATOR_TOOLS;
}
