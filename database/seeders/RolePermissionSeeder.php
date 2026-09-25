<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create every permission.
        foreach (Permissions::groups() as $permissions) {
            foreach (array_keys($permissions) as $name) {
                Permission::findOrCreate($name, 'web');
            }
        }

        // 2. The registrar memoises the permission list on first use, so it is
        //    now stale (it was empty when findOrCreate ran). Clear it before we
        //    try to attach permissions to roles.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = Permission::query()->where('guard_name', 'web')->get();

        // 3. Attach them to each role.
        foreach (array_keys(Permissions::roles()) as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $granted = Permissions::forRole($roleName);

            $role->syncPermissions($all->whereIn('name', $granted)->values());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
