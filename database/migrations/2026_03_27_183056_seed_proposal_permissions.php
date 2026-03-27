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
            'proposals.view',
            'proposals.update',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $commercialRepresentative = Role::firstOrCreate(['name' => 'commercial-representative']);

        $admin->givePermissionTo($permissions);
        $commercialRepresentative->givePermissionTo($permissions);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'commercial-representative')->first()?->delete();
        Permission::whereIn('name', ['proposals.view', 'proposals.update'])->delete();
    }
};
