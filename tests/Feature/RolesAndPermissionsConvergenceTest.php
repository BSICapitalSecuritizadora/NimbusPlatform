<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\ResponsibilityDelegations\ResponsibilityDelegationResource;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\ResponsibilityDelegationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Contrato declarativo de Delegações por role. Reproduz exatamente o que a
 * migration publicada `2026_08_27_122724_seed_delegation_permissions` concede:
 * `delegations.manage` é deliberadamente negada ao editor.
 *
 * @return array<string, list<string>>
 */
function delegationRoleContract(): array
{
    return [
        'super-admin' => ['delegations.create', 'delegations.manage', 'delegations.revoke', 'delegations.view'],
        'admin' => ['delegations.create', 'delegations.manage', 'delegations.revoke', 'delegations.view'],
        'editor' => ['delegations.create', 'delegations.revoke', 'delegations.view'],
        'commercial-representative' => [],
    ];
}

/**
 * Estado completo role -> permissions, ordenado, para comparação de snapshots.
 *
 * @return array<string, list<string>>
 */
function rbacSnapshot(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::query()
        ->with('permissions')
        ->orderBy('name')
        ->get()
        ->mapWithKeys(fn (Role $role): array => [
            $role->name => $role->permissions->pluck('name')->sort()->values()->all(),
        ])
        ->all();
}

/**
 * @return list<string>
 */
function delegationPermissionsOf(string $role): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::findByName($role)
        ->permissions
        ->pluck('name')
        ->filter(fn (string $name): bool => str_starts_with($name, 'delegations.'))
        ->sort()
        ->values()
        ->all();
}

function syncRolesAndPermissions(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function activeUserWithRole(string $role): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->assignRole($role);

    return $user;
}

it('mantém as permissions de Delegações que a migration publicada concedeu ao editor', function () {
    // Estado produzido apenas pelas migrations, antes de qualquer sync.
    $fromMigration = delegationPermissionsOf('editor');

    expect($fromMigration)->toBe(['delegations.create', 'delegations.revoke', 'delegations.view']);

    syncRolesAndPermissions();

    expect(delegationPermissionsOf('editor'))->toBe($fromMigration);
});

it('converge todas as roles para o mesmo contrato de Delegações após o sync', function () {
    syncRolesAndPermissions();

    foreach (delegationRoleContract() as $role => $expected) {
        expect([$role => delegationPermissionsOf($role)])->toBe([$role => $expected]);
    }
});

it('restaura permission de Delegações ausente no banco ao rodar o sync', function () {
    Role::findByName('editor')->revokePermissionTo([
        'delegations.view',
        'delegations.create',
        'delegations.revoke',
    ]);

    expect(delegationPermissionsOf('editor'))->toBe([]);

    syncRolesAndPermissions();

    expect(delegationPermissionsOf('editor'))->toBe(['delegations.create', 'delegations.revoke', 'delegations.view']);
});

it('remove permission de Delegações concedida fora do contrato da role', function () {
    Role::findByName('editor')->givePermissionTo(['delegations.manage', 'operations.delete']);

    expect(delegationPermissionsOf('editor'))->toContain('delegations.manage');

    syncRolesAndPermissions();

    $editor = Role::findByName('editor');

    expect(delegationPermissionsOf('editor'))->not->toContain('delegations.manage')
        ->and($editor->hasPermissionTo('operations.delete'))->toBeFalse();
});

it('termina no mesmo estado ao executar o sync repetidas vezes', function () {
    syncRolesAndPermissions();

    $afterFirstRun = rbacSnapshot();
    $permissionCount = Permission::query()->count();

    syncRolesAndPermissions();
    syncRolesAndPermissions();

    expect(rbacSnapshot())->toBe($afterFirstRun)
        ->and(Permission::query()->count())->toBe($permissionCount)
        ->and(Permission::query()->distinct()->count('name'))->toBe($permissionCount);
});

it('mantém o AccessPermission como catálogo único das permissions persistidas', function () {
    syncRolesAndPermissions();

    $persisted = Permission::query()->orderBy('name')->pluck('name')->all();
    $catalog = AccessPermission::values();
    sort($catalog);

    expect(array_diff($persisted, $catalog))->toBe([]);
});

it('não amplia o privilégio da role editor ao corrigir a divergência', function () {
    syncRolesAndPermissions();

    $editor = Role::findByName('editor');

    expect($editor->hasPermissionTo('delegations.manage'))->toBeFalse()
        ->and($editor->hasPermissionTo('operations.manage-responsibilities'))->toBeFalse()
        ->and($editor->hasPermissionTo('operations.delete'))->toBeFalse()
        ->and($editor->hasPermissionTo('measurements.delete'))->toBeFalse()
        ->and($editor->hasPermissionTo('users.update'))->toBeFalse()
        ->and($editor->hasPermissionTo('roles.update'))->toBeFalse();
});

it('não concede update genérico de Operation ou Measurement ao editor sem participação direta', function () {
    syncRolesAndPermissions();

    $editor = activeUserWithRole('editor');
    $operation = Operation::factory()->create([
        'status' => 'active',
        'responsible_user_id' => User::factory()->create()->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'pending',
    ]);

    expect($editor->can('operations.update'))->toBeTrue()
        ->and($editor->can('measurements.update'))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $operation))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $operation))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('manageResponsibilities', $operation))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $measurement))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $measurement))->toBeFalse();
});

it('continua exigindo responsabilidade direta para delegar mesmo com a permission vinda da role', function () {
    syncRolesAndPermissions();

    $editor = activeUserWithRole('editor');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $operation = Operation::factory()->create([
        'responsible_user_id' => User::factory()->create()->getKey(),
    ]);

    expect($editor->can('delegations.create'))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('create', ResponsibilityDelegation::class))->toBeTrue();

    expect(fn () => app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $editor->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now(),
        'ends_at' => now()->addDays(3),
        'reason' => 'Delegação sem responsabilidade atribuída',
    ], $editor))->toThrow(ValidationException::class);
});

it('preserva a autorização do ResponsibilityDelegationResource para a role editor', function () {
    syncRolesAndPermissions();

    $editor = activeUserWithRole('editor');
    $own = ResponsibilityDelegation::factory()->active()->create([
        'delegator_user_id' => $editor->getKey(),
        'delegate_user_id' => User::factory()->create()->getKey(),
    ]);
    $foreign = ResponsibilityDelegation::factory()->active()->create();

    $this->actingAs($editor);

    expect(ResponsibilityDelegationResource::canViewAny())->toBeTrue()
        ->and(ResponsibilityDelegationResource::canCreate())->toBeTrue()
        ->and(ResponsibilityDelegationResource::canView($own))->toBeTrue()
        ->and(ResponsibilityDelegationResource::canView($foreign))->toBeFalse()
        ->and(ResponsibilityDelegationResource::canEdit($own))->toBeFalse()
        ->and(ResponsibilityDelegationResource::canDelete($own))->toBeFalse();
});

it('mantém o módulo de Delegações fora do alcance do commercial-representative', function () {
    syncRolesAndPermissions();

    $representative = activeUserWithRole('commercial-representative');

    $this->actingAs($representative);

    expect(delegationPermissionsOf('commercial-representative'))->toBe([])
        ->and(Gate::forUser($representative)->allows('viewAny', ResponsibilityDelegation::class))->toBeFalse()
        ->and(Gate::forUser($representative)->allows('create', ResponsibilityDelegation::class))->toBeFalse()
        ->and(ResponsibilityDelegationResource::canViewAny())->toBeFalse();
});
