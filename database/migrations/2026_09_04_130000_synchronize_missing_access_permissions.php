<?php

use App\Enums\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();

        $guardName = (string) config('auth.defaults.guard', 'web');
        $permissions = [];

        foreach ([
            AccessPermission::MeasurementsExport,
            AccessPermission::AuditImportRunsView,
        ] as $accessPermission) {
            $permissions[$accessPermission->value] = Permission::firstOrCreate([
                'name' => $accessPermission->value,
                'guard_name' => $guardName,
            ]);
        }

        $permissionsByRole = [
            'super-admin' => [
                AccessPermission::MeasurementsExport,
                AccessPermission::AuditImportRunsView,
            ],
            'admin' => [
                AccessPermission::MeasurementsExport,
                AccessPermission::AuditImportRunsView,
            ],
            'editor' => [
                AccessPermission::MeasurementsExport,
            ],
        ];

        foreach ($permissionsByRole as $roleName => $accessPermissions) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guardName)
                ->first();

            if (! $role instanceof Role) {
                continue;
            }

            foreach ($accessPermissions as $accessPermission) {
                $role->givePermissionTo($permissions[$accessPermission->value]);
            }
        }

        $permissionRegistrar->forgetCachedPermissions();
    }

    /**
     * As permissions canônicas são mantidas porque podem ser anteriores a esta correção.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
