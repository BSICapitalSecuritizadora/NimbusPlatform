<?php

use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\Operations\Pages\CreateOperation;
use App\Filament\Resources\Operations\Pages\EditOperation;
use App\Filament\Resources\ResponsibilityDelegations\Pages\CreateResponsibilityDelegation;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use App\Services\OperationResponsibilityService;
use App\Services\ResponsibilityDelegationService;
use App\Services\UserLifecycleService;
use App\Support\Users\UserReactivationPreflight;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Tirar alguém da operação sem destruir o que essa pessoa fez.
 *
 * `is_active` já era o mecanismo -- derruba login, torna delegação inefetiva,
 * exclui de destinatário de SLA. O que faltava era a borda de entrada: o
 * cadastro continuava oferecendo o desligado para responsabilidade nova, a
 * gravação aceitava, a notificação continuava saindo, e reativar devolvia toda a
 * autoridade em silêncio.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    CarbonImmutable::setTestNow('2026-09-01 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function lifecycleUser(bool $active = true, bool $approved = true): User
{
    $user = User::factory()->create([
        'is_active' => $active,
        'approved_at' => $approved ? now() : null,
    ]);
    $user->givePermissionTo([
        'delegations.create', 'delegations.revoke', 'measurements.view', 'operations.view',
        'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize',
    ]);

    return $user;
}

function lifecycleSuperAdmin(): User
{
    $admin = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
        'is_active' => true,
        'approved_at' => now(),
    ]);
    $admin->assignRole('super-admin');

    return $admin;
}

function lifecycleOperationOf(User $holder): Operation
{
    return Operation::factory()->create(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $r): string => $r->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        $holder->getKey(),
    ));
}

// ── Elegibilidade operacional ────────────────────────────────────────────────

it('counts as operational only who is both active and provisioned', function () {
    $operational = lifecycleUser();
    $inactive = lifecycleUser(active: false);
    $unapproved = lifecycleUser(approved: false);
    $neither = lifecycleUser(active: false, approved: false);

    $eligible = User::query()->operational()->pluck('id')->all();

    expect($eligible)->toContain($operational->getKey())
        ->and($eligible)->not->toContain($inactive->getKey())
        ->and($eligible)->not->toContain($unapproved->getKey())
        ->and($eligible)->not->toContain($neither->getKey())
        ->and($operational->isOperational())->toBeTrue()
        ->and($inactive->isOperational())->toBeFalse()
        ->and($unapproved->isOperational())->toBeFalse();
});

it('keeps ineligible users reachable outside the operational scope', function () {
    $inactive = lifecycleUser(active: false);

    // Sem global scope: o cadastro continua existindo para auditoria, histórico
    // e qualquer relação já gravada.
    expect(User::query()->whereKey($inactive->getKey())->exists())->toBeTrue()
        ->and(User::query()->count())->toBeGreaterThan(User::query()->operational()->count());
});

// ── Atribuição direta ────────────────────────────────────────────────────────

it('accepts a new responsibility for an operational user', function () {
    $actor = lifecycleSuperAdmin();
    $this->actingAs($actor);
    $operation = Operation::factory()->create();
    $eligible = lifecycleUser();

    app(OperationResponsibilityService::class)->assertCanChange($actor, $operation, [
        'responsible_user_id' => $eligible->getKey(),
    ]);

    expect(true)->toBeTrue();
});

it('refuses a new responsibility for an inactive or unprovisioned user', function (bool $active, bool $approved) {
    $actor = lifecycleSuperAdmin();
    $this->actingAs($actor);
    $operation = Operation::factory()->create();
    $ineligible = lifecycleUser(active: $active, approved: $approved);

    expect(fn () => app(OperationResponsibilityService::class)->assertCanChange($actor, $operation, [
        'responsible_user_id' => $ineligible->getKey(),
    ]))->toThrow(ValidationException::class, 'não está disponível para novas responsabilidades');
})->with([
    'inativo' => [false, true],
    'não provisionado' => [true, false],
]);

it('refuses an ineligible responsibility assigned at creation', function () {
    $actor = lifecycleSuperAdmin();
    $this->actingAs($actor);
    $ineligible = lifecycleUser(active: false);

    expect(fn () => app(OperationResponsibilityService::class)->assertCanAssignOnCreation($actor, [
        'responsible_user_id' => $ineligible->getKey(),
    ]))->toThrow(ValidationException::class);
});

it('lets an operation keep a responsible who was deactivated after being assigned', function () {
    $actor = lifecycleSuperAdmin();
    $this->actingAs($actor);
    $responsible = lifecycleUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $responsible->getKey()]);
    $responsible->forceFill(['is_active' => false])->save();

    // Permanecer não é escolher de novo: editar outro campo da operação não pode
    // esbarrar num responsável que a operação já tinha.
    app(OperationResponsibilityService::class)->assertCanChange($actor->fresh(), $operation->fresh(), [
        'responsible_user_id' => $responsible->getKey(),
        'title' => 'Outro título',
    ]);

    expect($operation->fresh()->responsible_user_id)->toBe($responsible->getKey());
});

// ── Notificações de workflow ─────────────────────────────────────────────────

it('notifies only the recipients who are eligible right now', function () {
    $eligible = lifecycleUser();
    $inactive = lifecycleUser(active: false);
    $unapproved = lifecycleUser(approved: false);

    $operation = Operation::factory()->create([
        'responsible_user_id' => $eligible->getKey(),
        'assigned_user_id' => $inactive->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
        'uploaded_by' => $unapproved->getKey(),
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    app(MeasurementWorkflow::class)->reject($measurement, $eligible, 'Recusa para teste');

    Notification::assertNothingSentTo($inactive);
    Notification::assertNothingSentTo($unapproved);
});

// ── Desativação ──────────────────────────────────────────────────────────────

it('removes the user from the operation without touching a single link', function () {
    $actor = lifecycleSuperAdmin();
    $delegator = lifecycleUser();
    $delegate = lifecycleUser();
    $operation = lifecycleOperationOf($delegator);
    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(30),
        'reason' => 'Cobertura',
    ], $delegator);

    app(UserLifecycleService::class)->deactivate($delegate, $actor);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $fresh = $delegation->fresh();

    expect($delegate->fresh()->isActive())->toBeFalse()
        // Nada de auto-revogação: a delegação continua gravada e não revogada.
        ->and($fresh)->not->toBeNull()
        ->and($fresh->revoked_at)->toBeNull()
        ->and($fresh->delegate_user_id)->toBe($delegate->getKey())
        // Assignments diretos permanecem exatamente onde estavam.
        ->and($operation->fresh()->responsible_user_id)->toBe($delegator->getKey())
        // E a autoridade some, porque a efetividade lê o estado atual.
        ->and((new ResponsibilityDelegationService)->effectiveness($fresh)->status)->toBe('ineffective')
        ->and((new ResponsibilityDelegationService)->effectiveness($fresh)->reason?->value)->toBe('delegate_inactive');
});

it('audits the deactivation and the reactivation through the existing model log', function () {
    $actor = lifecycleSuperAdmin();
    $user = lifecycleUser();

    app(UserLifecycleService::class)->deactivate($user, $actor);
    app(UserLifecycleService::class)->reactivate($user->fresh(), $actor);

    $changes = Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->getKey())
        // Só as alterações: o evento de criação também registra `is_active`.
        ->where('event', 'updated')
        ->orderBy('id')
        ->get()
        ->map(fn (Activity $activity): mixed => $activity->properties['attributes']['is_active'] ?? null)
        ->filter(fn (mixed $value): bool => $value !== null)
        ->map(fn (mixed $value): bool => (bool) $value)
        ->values()
        ->all();

    expect($changes)->toBe([false, true]);
});

it('refuses to let an administrator deactivate themselves', function () {
    $actor = lifecycleSuperAdmin();

    expect(fn () => app(UserLifecycleService::class)->deactivate($actor, $actor))
        ->toThrow(ValidationException::class, 'seu próprio usuário')
        ->and($actor->fresh()->isActive())->toBeTrue();
});

// ── Reativação e preflight ───────────────────────────────────────────────────

/**
 * @return array{delegator: User, delegate: User, operation: Operation, delegation: ResponsibilityDelegation}
 */
function reactivationScenario(): array
{
    $delegator = lifecycleUser();
    $delegate = lifecycleUser();
    $operation = lifecycleOperationOf($delegator);
    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(30),
        'reason' => 'Cobertura',
    ], $delegator);

    return compact('delegator', 'delegate', 'operation', 'delegation');
}

function preflightFor(User $user): UserReactivationPreflight
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return app(UserLifecycleService::class)->reactivationPreflight($user->fresh());
}

it('announces the delegation that returns when the delegate comes back', function () {
    $scenario = reactivationScenario();
    app(UserLifecycleService::class)->deactivate($scenario['delegate'], lifecycleSuperAdmin());

    $preflight = preflightFor($scenario['delegate']);

    expect($preflight->restoresAuthority())->toBeTrue()
        ->and($preflight->delegationCount())->toBe(1)
        ->and($preflight->delegationLines()[0])->toContain($scenario['delegator']->name)
        ->and($preflight->directResponsibilityCount())->toBe(0);
});

it('announces both the delegation and the direct responsibilities when the delegator comes back', function () {
    $scenario = reactivationScenario();
    app(UserLifecycleService::class)->deactivate($scenario['delegator'], lifecycleSuperAdmin());

    $preflight = preflightFor($scenario['delegator']);

    expect($preflight->delegationCount())->toBe(1)
        ->and($preflight->directResponsibilityCount())->toBeGreaterThan(0)
        ->and($preflight->directResponsibilityLines()[0])->toContain((string) $scenario['operation']->code);
});

it('leaves out of the preflight every delegation that would not come back', function (string $case) {
    $scenario = reactivationScenario();
    $admin = lifecycleSuperAdmin();
    $delegation = $scenario['delegation'];

    match ($case) {
        'revogada' => app(ResponsibilityDelegationService::class)
            ->revokeDelegation($delegation, $scenario['delegator'], 'Encerrada'),
        'expirada' => $delegation->forceFill(['ends_at' => now()->subDay()])->save(),
        'futura' => $delegation->forceFill([
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(20),
        ])->save(),
        'outro participante inativo' => app(UserLifecycleService::class)
            ->deactivate($scenario['delegator'], $admin),
        'delegante sem permissão' => $scenario['delegator']->revokePermissionTo([
            'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize',
        ]),
        'delegante sem responsabilidade' => $scenario['operation']->forceFill(array_fill_keys(
            array_map(fn (MeasurementResponsibility $r): string => $r->operationColumn(), MeasurementResponsibility::cases()),
            lifecycleUser()->getKey(),
        ))->save(),
    };

    app(UserLifecycleService::class)->deactivate($scenario['delegate']->fresh(), $admin);

    expect(preflightFor($scenario['delegate'])->delegationCount())->toBe(0);
})->with([
    'revogada',
    'expirada',
    'futura',
    'outro participante inativo',
    'delegante sem permissão',
    'delegante sem responsabilidade',
]);

it('says plainly when reactivation restores no authority at all', function () {
    $user = lifecycleUser();
    app(UserLifecycleService::class)->deactivate($user, lifecycleSuperAdmin());

    $preflight = preflightFor($user);

    expect($preflight->restoresAuthority())->toBeFalse()
        ->and($preflight->delegationCount())->toBe(0)
        ->and($preflight->directResponsibilityCount())->toBe(0);
});

it('restores the authority on reactivation without rewriting any link', function () {
    $scenario = reactivationScenario();
    $admin = lifecycleSuperAdmin();
    app(UserLifecycleService::class)->deactivate($scenario['delegate'], $admin);

    $before = $scenario['delegation']->fresh()->only(['delegator_user_id', 'delegate_user_id', 'scope_type', 'scope_operation_id', 'starts_at', 'ends_at', 'revoked_at']);

    app(UserLifecycleService::class)->reactivate($scenario['delegate']->fresh(), $admin);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $after = $scenario['delegation']->fresh();

    expect($after->only(array_keys($before)))->toEqual($before)
        ->and((new ResponsibilityDelegationService)->effectiveness($after)->status)->toBe('active')
        ->and($scenario['operation']->fresh()->responsible_user_id)->toBe($scenario['delegator']->getKey());
});

// ── Interface ────────────────────────────────────────────────────────────────

it('offers deactivate for the active user and reactivate for the inactive one', function () {
    $this->actingAs(lifecycleSuperAdmin());
    $active = lifecycleUser();
    $inactive = lifecycleUser(active: false);

    Livewire::test(ListUsers::class)
        ->assertActionVisible(TestAction::make('deactivate_user')->table($active))
        ->assertActionHidden(TestAction::make('reactivate_user')->table($active))
        ->assertActionVisible(TestAction::make('reactivate_user')->table($inactive))
        ->assertActionHidden(TestAction::make('deactivate_user')->table($inactive));
});

it('deactivates a user from the listing', function () {
    $this->actingAs(lifecycleSuperAdmin());
    $user = lifecycleUser();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deactivate_user')->table($user));

    expect($user->fresh()->isActive())->toBeFalse();
});

it('reactivates a user from the listing', function () {
    $this->actingAs(lifecycleSuperAdmin());
    $user = lifecycleUser(active: false);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('reactivate_user')->table($user));

    expect($user->fresh()->isActive())->toBeTrue();
});

it('does not offer deactivation of the signed-in administrator', function () {
    $admin = lifecycleSuperAdmin();
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertActionDisabled(TestAction::make('deactivate_user')->table($admin));

    expect(UserResource::canDelete($admin))->toBeFalse();
});

// ── Selects operacionais ─────────────────────────────────────────────────────

/**
 * As opções que o select realmente oferece, por id.
 *
 * @return list<int>
 */
function optionIdsOf(Select $field): array
{
    return array_map('intval', array_keys($field->getOptions()));
}

it('offers only eligible users as new operation responsibles', function () {
    $this->actingAs(lifecycleSuperAdmin());
    $eligible = lifecycleUser();
    $inactive = lifecycleUser(active: false);
    $unapproved = lifecycleUser(approved: false);

    Livewire::test(CreateOperation::class)
        ->assertFormFieldExists('responsible_user_id', function (Select $field) use ($eligible, $inactive, $unapproved): bool {
            $options = optionIdsOf($field);

            return in_array($eligible->getKey(), $options, true)
                && ! in_array($inactive->getKey(), $options, true)
                && ! in_array($unapproved->getKey(), $options, true);
        });
});

it('still renders the responsible who was deactivated after being assigned', function () {
    $this->actingAs(lifecycleSuperAdmin());
    $responsible = lifecycleUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $responsible->getKey()]);
    $responsible->forceFill(['is_active' => false])->save();

    Livewire::test(EditOperation::class, ['record' => $operation->getRouteKey()])
        // O responsável atual continua na lista, senão o campo apareceria vazio e
        // pareceria que a operação perdeu a responsabilidade.
        ->assertFormFieldExists('responsible_user_id', fn (Select $field): bool => in_array(
            $responsible->getKey(),
            optionIdsOf($field),
            true,
        ))
        ->assertFormSet(['responsible_user_id' => $responsible->getKey()]);
});

it('offers only eligible users as delegation principals', function () {
    $this->actingAs(lifecycleSuperAdmin());
    $eligible = lifecycleUser();
    $inactive = lifecycleUser(active: false);
    $unapproved = lifecycleUser(approved: false);

    $check = function (Select $field) use ($eligible, $inactive, $unapproved): bool {
        $options = optionIdsOf($field);

        return in_array($eligible->getKey(), $options, true)
            && ! in_array($inactive->getKey(), $options, true)
            && ! in_array($unapproved->getKey(), $options, true);
    };

    Livewire::test(CreateResponsibilityDelegation::class)
        ->assertFormFieldExists('delegator_user_id', $check)
        ->assertFormFieldExists('delegate_user_id', $check);
});

it('keeps the delegation service refusing an ineligible principal sent by hand', function () {
    $delegator = lifecycleUser();
    $inactive = lifecycleUser(active: false);
    lifecycleOperationOf($delegator);

    // O filtro do select é conforto; a integridade continua sendo do serviço.
    expect(fn () => app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $inactive->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Payload manipulado',
    ], $delegator))->toThrow(ValidationException::class, 'Delegado deve estar ativo e aprovado.');
});
