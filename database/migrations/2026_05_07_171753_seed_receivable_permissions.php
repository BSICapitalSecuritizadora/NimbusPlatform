<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'receivables.view',
            'receivables.create',
            'receivables.update',
            'receivables.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'super-admin'])->givePermissionTo($permissions);
        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo($permissions);
        Role::firstOrCreate(['name' => 'editor'])->givePermissionTo([
            'receivables.view',
            'receivables.create',
            'receivables.update',
        ]);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'receivables.view',
                'receivables.create',
                'receivables.update',
                'receivables.delete',
            ])
            ->delete();
    }
};
