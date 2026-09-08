<?php

use App\Enums\AccessPermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function registrySynchronizationPermissionNames(): array
{
    return [
        AccessPermission::MeasurementsExport->value,
        AccessPermission::AuditImportRunsView->value,
    ];
}

function registrySynchronizationMigration(): Migration
{
    return require database_path('migrations/2026_09_04_130000_synchronize_missing_access_permissions.php');
}

function removeRegistrySynchronizationPermissions(): void
{
    Permission::query()
        ->whereIn('name', registrySynchronizationPermissionNames())
        ->get()
        ->each(function (Permission $permission): void {
            $permission->roles()->detach();
            $permission->delete();
        });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('materializa as permissions canônicas ausentes com guard correto e de forma idempotente', function () {
    removeRegistrySynchronizationPermissions();

    $migration = registrySynchronizationMigration();
    $migration->up();
    $migration->up();

    $permissions = Permission::query()
        ->whereIn('name', registrySynchronizationPermissionNames())
        ->orderBy('name')
        ->get();

    $expectedNames = registrySynchronizationPermissionNames();
    sort($expectedNames);

    expect(array_diff(registrySynchronizationPermissionNames(), AccessPermission::values()))->toBe([])
        ->and($permissions->pluck('name')->all())->toBe($expectedNames)
        ->and($permissions->pluck('guard_name')->unique()->values()->all())->toBe([
            (string) config('auth.defaults.guard', 'web'),
        ])
        ->and($permissions)->toHaveCount(2);
});

it('concede somente o contrato das roles padrão e preserva custom roles', function () {
    removeRegistrySynchronizationPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    $customRole = Role::firstOrCreate([
        'name' => 'custom-operations',
        'guard_name' => $guardName,
    ]);

    registrySynchronizationMigration()->up();

    $superAdmin = Role::findByName('super-admin', $guardName);
    $admin = Role::findByName('admin', $guardName);
    $editor = Role::findByName('editor', $guardName);
    $commercialRepresentative = Role::findByName('commercial-representative', $guardName);

    expect($superAdmin->hasPermissionTo(AccessPermission::MeasurementsExport->value))->toBeTrue()
        ->and($superAdmin->hasPermissionTo(AccessPermission::AuditImportRunsView->value))->toBeTrue()
        ->and($admin->hasPermissionTo(AccessPermission::MeasurementsExport->value))->toBeTrue()
        ->and($admin->hasPermissionTo(AccessPermission::AuditImportRunsView->value))->toBeTrue()
        ->and($editor->hasPermissionTo(AccessPermission::MeasurementsExport->value))->toBeTrue()
        ->and($editor->hasPermissionTo(AccessPermission::AuditImportRunsView->value))->toBeFalse()
        ->and($commercialRepresentative->hasPermissionTo(AccessPermission::MeasurementsExport->value))->toBeFalse()
        ->and($commercialRepresentative->hasPermissionTo(AccessPermission::AuditImportRunsView->value))->toBeFalse()
        ->and($customRole->fresh()->permissions)->toHaveCount(0);
});

it('mantém migration e seeder convergentes para as duas permissions', function () {
    removeRegistrySynchronizationPermissions();

    registrySynchronizationMigration()->up();

    $guardName = (string) config('auth.defaults.guard', 'web');
    $beforeSeeder = Role::query()
        ->where('guard_name', $guardName)
        ->whereIn('name', ['super-admin', 'admin', 'editor', 'commercial-representative'])
        ->with('permissions')
        ->get()
        ->mapWithKeys(fn (Role $role): array => [
            $role->name => $role->permissions
                ->whereIn('name', registrySynchronizationPermissionNames())
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ])
        ->sortKeys()
        ->all();

    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $afterSeeder = Role::query()
        ->where('guard_name', $guardName)
        ->whereIn('name', ['super-admin', 'admin', 'editor', 'commercial-representative'])
        ->with('permissions')
        ->get()
        ->mapWithKeys(fn (Role $role): array => [
            $role->name => $role->permissions
                ->whereIn('name', registrySynchronizationPermissionNames())
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ])
        ->sortKeys()
        ->all();

    expect($afterSeeder)->toBe($beforeSeeder);
});

it('permite associar explicitamente as permissions materializadas a uma custom role', function () {
    removeRegistrySynchronizationPermissions();
    registrySynchronizationMigration()->up();

    $guardName = (string) config('auth.defaults.guard', 'web');
    $customRole = Role::firstOrCreate([
        'name' => 'custom-reporting',
        'guard_name' => $guardName,
    ]);

    expect($customRole->hasPermissionTo(AccessPermission::MeasurementsExport->value))->toBeFalse();

    $customRole->givePermissionTo(AccessPermission::MeasurementsExport->value);

    expect($customRole->hasPermissionTo(AccessPermission::MeasurementsExport->value))->toBeTrue()
        ->and(
            Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', AccessPermission::values())
                ->whereIn('name', registrySynchronizationPermissionNames())
                ->count()
        )->toBe(2);
});
