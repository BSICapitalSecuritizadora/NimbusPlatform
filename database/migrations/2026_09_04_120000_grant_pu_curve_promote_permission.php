<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Promoção operacional da curva de PU (2B.5.18).
 *
 * `pu.curve.homologate` já representa homologação/review — decisão sobre o
 * conteúdo da candidate. Promover troca a curva vigente e por isso é uma
 * autoridade distinta, concedida apenas às roles que já detêm o conjunto
 * completo de permissões (`super-admin`, `admin`). `editor` fica de fora, em
 * linha com `pu.curve.homologate` e `pu.curve.invalidate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => AccessPermission::PuCurvePromote->value,
        ]);

        Role::query()
            ->whereIn('name', ['super-admin', 'admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', AccessPermission::PuCurvePromote->value)
            ->first();

        if ($permission instanceof Permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
