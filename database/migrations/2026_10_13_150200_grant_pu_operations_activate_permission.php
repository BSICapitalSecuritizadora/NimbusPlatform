<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Autoridade de ativar ou suspender a operação do PU de uma emissão (Fase 7).
 *
 * Separada de configurar parâmetros (`pu.parameters.configure`), gerar
 * (`pu.curve.generate`), validar, homologar (`pu.curve.homologate`), liquidar
 * (`pu.settlement.record`) e monitorar: ativar não é homologar, e homologar não
 * ativa. Concedida só a `super-admin` e `admin`, como as demais autoridades
 * operacionais do PU; o `RolesAndPermissionsSeeder` já lhes dá
 * `AccessPermission::values()`, então seed e migration convergem (o editor não a
 * recebe). Conceder a permissão não ativa emissão nenhuma.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => AccessPermission::PuOperationsActivate->value]);

        Role::query()
            ->whereIn('name', ['super-admin', 'admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->where('name', AccessPermission::PuOperationsActivate->value)->first();

        if ($permission instanceof Permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
