<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cria as permissões do módulo de garantias e as concede aos perfis existentes.
 *
 * Sem isto, ambientes já provisionados ficariam com o módulo invisível até que
 * alguém rodasse o seeder de perfis à mão — o mesmo caminho que as permissões
 * de obrigações e de curva de PU já seguiram.
 *
 * `editor` não recebe exclusão nem reabertura de competência: desfazer um
 * fechamento reescreve indicador histórico e fica restrito a administradores.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const EDITOR_PERMISSIONS = [
        'guarantees.view',
        'guarantees.create',
        'guarantees.update',
        'guarantees.generate',
        'guarantees.review_suggestions',
        'guarantees.approve_suggestion',
        'guarantees.reject_suggestion',
        'guarantees.reprocess_documents',
        'guarantees.update_value',
        'guarantees.close_competence',
        'guarantees.manage_valuations',
    ];

    public function up(): void
    {
        $permissionNames = array_values(array_filter(
            AccessPermission::values(),
            static fn (string $permission): bool => str_starts_with($permission, 'guarantees.'),
        ));

        $guardName = (string) config('auth.defaults.guard', 'web');
        $now = now();

        foreach ($permissionNames as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => $guardName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $permissionNames)
            ->where('guard_name', $guardName)
            ->pluck('id', 'name');

        $this->grant('super-admin', $permissionNames, $permissionIds, $guardName);
        $this->grant('admin', $permissionNames, $permissionIds, $guardName);
        $this->grant('editor', self::EDITOR_PERMISSIONS, $permissionIds, $guardName);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionNames = array_values(array_filter(
            AccessPermission::values(),
            static fn (string $permission): bool => str_starts_with($permission, 'guarantees.'),
        ));

        $permissionIds = DB::table('permissions')->whereIn('name', $permissionNames)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $permissions
     * @param  Collection<string, int>  $permissionIds
     */
    private function grant(string $roleName, array $permissions, $permissionIds, string $guardName): void
    {
        $roleId = DB::table('roles')
            ->where('name', $roleName)
            ->where('guard_name', $guardName)
            ->value('id');

        if ($roleId === null) {
            return;
        }

        foreach ($permissions as $permission) {
            $permissionId = $permissionIds[$permission] ?? null;

            if ($permissionId === null) {
                continue;
            }

            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }
};
