<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissões do dossiê documental, concedidas aos perfis já provisionados.
 *
 * `editor` não recebe exclusão de instrumento nem remoção de documento do
 * dossiê: as duas descartam a base probatória de garantias confirmadas.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const EDITOR_PERMISSIONS = [
        'legal-instruments.view',
        'legal-instruments.create',
        'legal-instruments.update',
        'legal-instruments.attach_document',
        'legal-instruments.process_document',
        'legal-instruments.review_changes',
        'legal-instruments.confirm_change',
        'legal-instruments.reject_change',
    ];

    public function up(): void
    {
        $permissionNames = $this->permissionNames();
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
        $permissionIds = DB::table('permissions')->whereIn('name', $this->permissionNames())->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        return array_values(array_filter(
            AccessPermission::values(),
            static fn (string $permission): bool => str_starts_with($permission, 'legal-instruments.'),
        ));
    }

    /**
     * @param  list<string>  $permissions
     * @param  Collection<string, int>  $permissionIds
     */
    private function grant(string $roleName, array $permissions, Collection $permissionIds, string $guardName): void
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
