<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $permissions = [
        'operations.view',
        'operations.create',
        'operations.update',
        'operations.delete',
        'measurements.view',
        'measurements.create',
        'measurements.update',
        'measurements.delete',
        'measurements.review',
        'measurements.pay',
        'measurements.finalize',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        foreach (['super-admin', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role])
                ->givePermissionTo($this->permissions);
        }

        Role::firstOrCreate(['name' => 'editor'])
            ->givePermissionTo([
                'operations.view',
                'operations.create',
                'operations.update',
                'measurements.view',
                'measurements.create',
                'measurements.update',
                'measurements.review',
                'measurements.pay',
                'measurements.finalize',
            ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', $this->permissions)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
