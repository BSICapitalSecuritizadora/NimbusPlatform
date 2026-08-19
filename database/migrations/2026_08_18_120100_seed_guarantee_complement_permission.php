<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria a permissão de complementar garantia existente.
 *
 * Sem isto, ambientes já provisionados teriam a ação recomendada da revisão
 * invisível — e o revisor voltaria a escolher entre criar duplicata e rejeitar,
 * que é exatamente o que este fluxo veio remover.
 *
 * Complementar acompanha quem já podia confirmar: quem tem autoridade para
 * transformar uma candidata em garantia oficial tem autoridade para enriquecer
 * uma existente, e a operação é menos destrutiva que a criação.
 */
return new class extends Migration
{
    private const PERMISSION = 'guarantees.complement_guarantee';

    public function up(): void
    {
        $guardName = (string) config('auth.defaults.guard', 'web');
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => $guardName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', $guardName)
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        $approvePermissionId = DB::table('permissions')
            ->where('name', 'guarantees.approve_suggestion')
            ->where('guard_name', $guardName)
            ->value('id');

        $roleIds = $approvePermissionId === null
            ? DB::table('roles')->where('guard_name', $guardName)->whereIn('name', ['super-admin', 'admin'])->pluck('id')
            : DB::table('role_has_permissions')->where('permission_id', $approvePermissionId)->pluck('role_id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
