<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `ContactMessageResource` e `ReminderLogResource` passaram a exigir permissão
 * explícita. O deploy roda `migrate`, não `db:seed`, então sem esta migration as
 * permissões novas não existiriam no banco e os dois Resources ficariam
 * inacessíveis para todo mundo — inclusive para o administrador.
 *
 * Concede apenas aos papéis administrativos: `editor` e
 * `commercial-representative` não têm necessidade de ler a base de leads.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        AccessPermission::ContactMessagesView,
        AccessPermission::ContactMessagesUpdate,
        AccessPermission::ReminderLogsView,
    ];

    private const ADMINISTRATIVE_ROLES = ['super-admin', 'admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSIONS)
            ->map(fn (AccessPermission $permission): Permission => Permission::firstOrCreate([
                'name' => $permission->value,
                'guard_name' => config('auth.defaults.guard', 'web'),
            ]));

        foreach (self::ADMINISTRATIVE_ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();

            $role?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', array_map(
                static fn (AccessPermission $permission): string => $permission->value,
                self::PERMISSIONS,
            ))
            ->each(fn (Permission $permission) => $permission->delete());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
