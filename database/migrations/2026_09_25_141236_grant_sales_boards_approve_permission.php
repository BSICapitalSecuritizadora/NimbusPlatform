<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Segregação de funções no Quadro de Vendas.
 *
 * `sales-boards.update` continua sendo de quem opera a competência: gerar,
 * recalcular, abrir e enviar a validação da construtora, abrir a análise e
 * conduzir a homologação. Decidir não conformidades, aprovar e publicar, devolver
 * à construtora, atestar os impactos da homologação, aprová-la, ativar a
 * automação e retornar ao legado passam a exigir `sales-boards.approve`, que é a
 * autoridade da Gestão.
 *
 * Concedida apenas a `super-admin` e `admin`. `editor` fica de fora de
 * propósito: é o perfil operacional, e dar a ele as duas pontas do fluxo
 * desfaria a separação que esta permissão existe para impor. O seeder chega ao
 * mesmo estado, porque sincroniza o catálogo inteiro nas duas roles
 * administrativas e não lista esta permissão para o editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => AccessPermission::SalesBoardsApprove->value,
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
            ->where('name', AccessPermission::SalesBoardsApprove->value)
            ->first();

        if ($permission instanceof Permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
