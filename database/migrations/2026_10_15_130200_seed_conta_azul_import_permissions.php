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
            'conta-azul-imports.view',
            'conta-azul-imports.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo($permissions);

        // Staged bills are pre-operational data under reconciliation: the
        // editor may inspect them, but running an ingestion stays with the
        // admin until the governed Phase 3B workflow defines who operates
        // the pipeline.
        Role::firstOrCreate(['name' => 'editor'])->givePermissionTo([
            'conta-azul-imports.view',
        ]);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'conta-azul-imports.view',
                'conta-azul-imports.manage',
            ])
            ->delete();
    }
};
