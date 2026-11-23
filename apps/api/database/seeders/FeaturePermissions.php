<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

abstract class FeaturePermissions extends Seeder
{
    protected const ACTIONS = [];

    public function run(): void
    {
        foreach (static::ACTIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}
