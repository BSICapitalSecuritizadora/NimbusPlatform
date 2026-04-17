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
            'funds.view',
            'funds.create',
            'funds.update',
            'funds.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo($permissions);
        Role::firstOrCreate(['name' => 'editor'])->givePermissionTo([
            'funds.view',
            'funds.create',
            'funds.update',
        ]);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'funds.view',
                'funds.create',
                'funds.update',
                'funds.delete',
            ])
            ->delete();
    }
};
