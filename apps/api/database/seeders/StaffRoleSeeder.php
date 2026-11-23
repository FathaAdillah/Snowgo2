<?php

namespace Database\Seeders;

use App\Modules\Identity\Staff\StaffAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class StaffRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (StaffAccess::DEFAULT_ROLES as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($role->wasRecentlyCreated) {
                $role->givePermissionTo('reporting.dashboard.view', 'admin.profile.view');
                $role->givePermissionTo(GeographyPermissionsSeeder::initialGrants($name));
                $role->givePermissionTo(CustomerPermissionsSeeder::initialGrants($name));
                $role->givePermissionTo(CatalogPermissionsSeeder::initialGrants($name));
                $role->givePermissionTo(InventoryPermissionsSeeder::initialGrants($name));
                if ($name !== 'inventory_staff') {
                    $role->givePermissionTo('foundation.file.view', 'foundation.file.create', 'foundation.file.download');
                }
            }
        }
    }
}
