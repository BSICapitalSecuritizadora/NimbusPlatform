<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria as permissões do módulo de Contratos nos ambientes já provisionados.
 *
 * Contratos acompanham Clientes: quem já podia cadastrar o comprador passa a
 * poder registrar a venda dele. Restaurar fica restrito a quem já podia
 * restaurar cliente, pela mesma razão -- devolver um registro excluído à
 * operação é mais sensível do que criá-lo.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> nova permissão => permissão que define quem a recebe
     */
    private const PERMISSIONS = [
        'contracts.view' => 'clients.view',
        'contracts.create' => 'clients.create',
        'contracts.update' => 'clients.update',
        'contracts.delete' => 'clients.delete',
        'contracts.restore' => 'clients.restore',
    ];

    public function up(): void
    {
        $guardName = (string) config('auth.defaults.guard', 'web');
        $now = now();

        foreach (self::PERMISSIONS as $permission => $referencePermission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => $guardName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->where('guard_name', $guardName)
                ->value('id');

            if ($permissionId === null) {
                continue;
            }

            $referenceId = DB::table('permissions')
                ->where('name', $referencePermission)
                ->where('guard_name', $guardName)
                ->value('id');

            $roleIds = $referenceId === null
                ? DB::table('roles')->where('guard_name', $guardName)->whereIn('name', ['super-admin', 'admin'])->pluck('id')
                : DB::table('role_has_permissions')->where('permission_id', $referenceId)->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys(self::PERMISSIONS))
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
