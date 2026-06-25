<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissões operacionais para importação de índices e aprovação (maker/checker) de séries projetadas.
     */
    private const PERMISSIONS = [
        'pu.index.import',
        'pu.projection.approve',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        foreach (['super-admin', 'admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();

            foreach (self::PERMISSIONS as $permission) {
                $role?->givePermissionTo($permission);
            }
        }

        Role::query()->where('name', 'editor')->first()?->givePermissionTo('pu.index.import');
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
    }
};
