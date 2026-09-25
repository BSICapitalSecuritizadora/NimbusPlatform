<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['measurements.financial-rules.view', 'measurements.financial-rules.manage'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            Role::query()->whereIn('name', ['admin', 'super-admin'])->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::query()->whereIn('name', ['measurements.financial-rules.view', 'measurements.financial-rules.manage'])->get()
            ->each(fn (Permission $permission) => $permission->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
