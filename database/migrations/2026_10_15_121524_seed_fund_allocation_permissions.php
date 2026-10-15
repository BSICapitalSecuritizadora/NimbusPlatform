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
            'fund-allocations.view',
            'fund-allocations.create',
            'fund-allocations.submit',
            'fund-allocations.approve',
            'fund-allocations.correct',
            'fund-allocations.manage_references',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo($permissions);

        // The editor operates the workflow but never approves: approval stays
        // with a second user, mirroring the sales-boards.approve segregation.
        Role::firstOrCreate(['name' => 'editor'])->givePermissionTo([
            'fund-allocations.view',
            'fund-allocations.create',
            'fund-allocations.submit',
            'fund-allocations.correct',
            'fund-allocations.manage_references',
        ]);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'fund-allocations.view',
                'fund-allocations.create',
                'fund-allocations.submit',
                'fund-allocations.approve',
                'fund-allocations.correct',
                'fund-allocations.manage_references',
            ])
            ->delete();
    }
};
