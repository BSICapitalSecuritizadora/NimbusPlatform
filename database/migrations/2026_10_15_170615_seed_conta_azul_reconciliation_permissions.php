<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Phase 3B: governed reconciliation capabilities.
     *
     * Attribution, correction and stale-batch recovery are each their own
     * authority — deliberately separate from `manage` (running ingestions)
     * so deciding who may move a bill toward an Emission never rides along
     * with who may operate the pipeline. The editor stays view-only here,
     * as in Phase 3A.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'conta-azul-imports.attribute',
            'conta-azul-imports.correct',
            'conta-azul-imports.recover',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        Role::firstOrCreate(['name' => 'admin'])->givePermissionTo($permissions);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'conta-azul-imports.attribute',
                'conta-azul-imports.correct',
                'conta-azul-imports.recover',
            ])
            ->delete();
    }
};
