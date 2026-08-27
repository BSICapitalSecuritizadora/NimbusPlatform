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

        $permissions = collect([
            'delegations.view',
            'delegations.create',
            'delegations.revoke',
            'delegations.manage',
        ])->mapWithKeys(fn (string $name): array => [
            $name => Permission::query()->firstOrCreate(['name' => $name]),
        ]);

        Role::query()
            ->whereIn('name', ['super-admin', 'admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions->values()));

        Role::query()
            ->where('name', 'editor')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions->only([
                'delegations.view',
                'delegations.create',
                'delegations.revoke',
            ])->values()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'delegations.view',
                'delegations.create',
                'delegations.revoke',
                'delegations.manage',
            ])
            ->get()
            ->each(function (Permission $permission): void {
                $permission->roles()->detach();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
