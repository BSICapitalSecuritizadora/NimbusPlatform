<?php

namespace Database\Seeders;

use App\Enums\AccessPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = AccessPermission::values();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $editor = Role::firstOrCreate(['name' => 'editor']);
        $commercialRepresentative = Role::firstOrCreate(['name' => 'commercial-representative']);

        $superAdmin->syncPermissions($permissions);
        $admin->syncPermissions($permissions);

        $editor->syncPermissions([
            'investors.view', 'investors.create', 'investors.update',
            'emissions.view', 'emissions.create', 'emissions.update',
            'expenses.view', 'expenses.create', 'expenses.update',
            'funds.view', 'funds.create', 'funds.update',
            'documents.view', 'documents.create', 'documents.update',
            'constructions.view', 'constructions.create', 'constructions.update',
            'sales-boards.view', 'sales-boards.create', 'sales-boards.update',
        ]);

        $commercialRepresentative->syncPermissions([
            'proposals.view',
            'proposals.update',
        ]);
    }
}
