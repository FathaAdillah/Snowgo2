<?php

namespace Database\Seeders;

use App\Modules\Catalog\Resource;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class CatalogPermissionsSeeder extends Seeder
{
    /** @return list<string> */
    public static function actions(): array
    {
        $actions = [Resource::Product->permission('publish')];
        foreach (Resource::cases() as $resource) {
            foreach (['view', 'create', 'update', 'archive'] as $action) {
                $actions[] = $resource->permission($action);
            }
        }

        return $actions;
    }

    public function run(): void
    {
        foreach (self::actions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /** @return list<string> */
    public static function initialGrants(string $role): array
    {
        return match ($role) {
            'admin', 'product_manager' => self::actions(),
            'content_manager' => array_map(fn (Resource $resource) => $resource->permission('view'), Resource::cases()),
            default => [],
        };
    }
}
