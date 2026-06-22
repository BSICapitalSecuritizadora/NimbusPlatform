<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'pu.dashboard.view';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => self::PERMISSION]);

        foreach (['super-admin', 'admin', 'editor'] as $roleName) {
            Role::query()->where('name', $roleName)->first()?->givePermissionTo(self::PERMISSION);
        }
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->where('name', self::PERMISSION)->delete();
    }
};
