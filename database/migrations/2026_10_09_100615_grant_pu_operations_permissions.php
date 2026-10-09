<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Autoridades do monitoramento operacional do PU (Fase 6).
 *
 * Ver os incidentes (e recebê-los no sino) e agir sobre eles -- reconhecer,
 * retomar uma atualização de obrigações esgotada ou bloqueada -- são autoridades
 * separadas de ver o painel do PU: o incidente carrega divergência de
 * liquidação e conflito, que hoje só `super-admin` e `admin` veem
 * (`pu.reconciliation.view`). Concedidas só a essas roles; o
 * `RolesAndPermissionsSeeder` já lhes dá `AccessPermission::values()`, então
 * seed e migration convergem. Nenhuma delas homologa, liquida ou corrige nada.
 */
return new class extends Migration
{
    /** @var list<AccessPermission> */
    private const PERMISSIONS = [
        AccessPermission::PuOperationsMonitor,
        AccessPermission::PuOperationsRecover,
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
