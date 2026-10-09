<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Autoridades da liquidação e da conciliação do PU (Fase 5).
 *
 * Registrar liquidação, corrigi-la/estorná-la (e decidir conflitos), ver a
 * conciliação e exportá-la são autoridades separadas e distintas de editar a
 * emissão: `emissions.update` não liquida nada. Concedidas só às roles que já
 * detêm o catálogo inteiro (`super-admin`, `admin`), como homologar e invalidar a
 * curva; o `RolesAndPermissionsSeeder` dá a elas `AccessPermission::values()`,
 * então seed e migration convergem.
 */
return new class extends Migration
{
    /** @var list<AccessPermission> */
    private const PERMISSIONS = [
        AccessPermission::PuSettlementRecord,
        AccessPermission::PuSettlementCorrect,
        AccessPermission::PuReconciliationView,
        AccessPermission::PuReconciliationExport,
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = Role::query()->whereIn('name', ['super-admin', 'admin'])->get();

        foreach (self::PERMISSIONS as $case) {
            $permission = Permission::firstOrCreate(['name' => $case->value]);

            $roles->each(fn (Role $role) => $role->givePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $case) {
            $permission = Permission::query()->where('name', $case->value)->first();

            if ($permission instanceof Permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
