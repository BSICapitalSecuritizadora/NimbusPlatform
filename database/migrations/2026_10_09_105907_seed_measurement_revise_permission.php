<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Autoridade para revisar uma medição: criar, editar, enviar e cancelar a
 * revisão R1, R2... de uma medição já aprovada ou finalizada.
 *
 * Separada de `measurements.create` porque reabre um registro que já foi
 * aprovado -- e, depois do pagamento, um registro financeiro --: a organização
 * pode concedê-la a menos gente que o envio. As decisões de cada etapa da
 * revisão continuam com os responsáveis da operação, como em qualquer medição.
 * Concedida a quem opera as medições (`editor`), além das roles com o catálogo
 * inteiro; o `RolesAndPermissionsSeeder` repete o editor, para seed e
 * migration convergirem.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => AccessPermission::MeasurementsRevise->value]);

        Role::query()
            ->whereIn('name', ['super-admin', 'admin', 'editor'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->where('name', AccessPermission::MeasurementsRevise->value)->first();

        if ($permission instanceof Permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
