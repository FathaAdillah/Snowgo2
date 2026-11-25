<?php

namespace Database\Seeders;

use App\Modules\Identity\Staff\StaffAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(713007)');
            $role = Role::firstOrCreate(['name' => StaffAccess::SUPER, 'guard_name' => 'web'], ['bypasses_permissions' => true]);
            if (! $role->getAttribute('bypasses_permissions')) {
                throw new LogicException('Reserved role name already in use. Review its members before migrating; no privileges were changed.');
            }
            $this->call([DashboardPermissionsSeeder::class, ProfilePermissionsSeeder::class, FilePermissionsSeeder::class, StaffPermissionsSeeder::class, RolePermissionsSeeder::class, GeographyPermissionsSeeder::class, CustomerPermissionsSeeder::class, CatalogPermissionsSeeder::class, InventoryPermissionsSeeder::class, ReservationPermissionsSeeder::class, OrderPermissionsSeeder::class, OperatorToolsPermissionsSeeder::class, StaffRoleSeeder::class]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
