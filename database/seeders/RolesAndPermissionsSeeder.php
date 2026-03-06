<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Limpa cache de permissões
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Investors
            'investors.view',
            'investors.create',
            'investors.update',
            'investors.delete',

            // Emissions
            'emissions.view',
            'emissions.create',
            'emissions.update',
            'emissions.delete',

            // Documents
            'documents.view',
            'documents.create',
            'documents.update',
            'documents.delete',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $editor = Role::firstOrCreate(['name' => 'editor']);

        // admin: tudo
        $admin->syncPermissions($permissions);

        // editor: tudo menos delete
        $editor->syncPermissions([
            'investors.view', 'investors.create', 'investors.update',
            'emissions.view', 'emissions.create', 'emissions.update',
            'documents.view', 'documents.create', 'documents.update',
        ]);

        // super-admin: não precisa sync (Gate::before dá bypass),
        // mas não faz mal se quiser manter organizado.
    }
}
