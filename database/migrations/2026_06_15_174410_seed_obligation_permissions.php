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
            'obligations.view',
            'obligations.create',
            'obligations.update',
            'obligations.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo($permissions);
        Role::firstOrCreate(['name' => 'editor'])->givePermissionTo([
            'obligations.view',
            'obligations.create',
            'obligations.update',
        ]);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'obligations.view',
                'obligations.create',
                'obligations.update',
                'obligations.delete',
            ])
            ->delete();
    }
};
