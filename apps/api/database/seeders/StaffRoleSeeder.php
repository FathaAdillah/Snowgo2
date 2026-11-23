<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class StaffRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['admin', 'product_manager', 'inventory_staff', 'order_staff', 'finance', 'customer_service', 'warehouse', 'content_manager'] as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($role->wasRecentlyCreated) {
                $role->givePermissionTo('reporting.dashboard.view', 'admin.profile.view');
                if ($name !== 'inventory_staff') {
                    $role->givePermissionTo('foundation.file.view', 'foundation.file.create', 'foundation.file.download');
                }
            }
        }
    }
}
