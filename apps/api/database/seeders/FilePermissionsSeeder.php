<?php

namespace Database\Seeders;

final class FilePermissionsSeeder extends FeaturePermissions
{
    protected const ACTIONS = ['foundation.file.create', 'foundation.file.view', 'foundation.file.download'];
}
