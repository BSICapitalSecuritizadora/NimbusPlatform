<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria as permissões do módulo de Parcelas nos ambientes já provisionados.
 *
 * Parcelas acompanham Contratos: quem já podia registrar a venda passa a poder
 * registrar o fluxo de recebimento dela, sem nenhuma mudança prática de acesso
 * hoje. Ter o conjunto próprio é o que permite, mais adiante, dar as parcelas ao
 * financeiro sem abrir junto a edição do contrato.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> nova permissão => permissão que define quem a recebe
     */
    private const PERMISSIONS = [
        'contract-installments.view' => 'contracts.view',
        'contract-installments.create' => 'contracts.create',
        'contract-installments.update' => 'contracts.update',
        'contract-installments.delete' => 'contracts.delete',
        'contract-installments.restore' => 'contracts.restore',
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
