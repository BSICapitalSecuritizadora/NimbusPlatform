<?php

use App\Enums\MeasurementResponsibility;
use App\Enums\OperationStatus;
use App\Exceptions\DelegationHistoryException;
use App\Exceptions\OperationLifecycleException;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\ResponsibilityDelegations\Pages\CreateResponsibilityDelegation;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\OperationLifecycleService;
use App\Services\OperationResponsibilityService;
use App\Services\ResponsibilityDelegationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function lifecycleAdmin(string $role = 'admin'): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user;
}

function lifecycleOperation(OperationStatus $status, ?User $participant = null): Operation
{
    $participant ??= lifecycleAdmin();

    return Operation::factory()->create([
        'status' => $status,
        'assigned_user_id' => $participant->getKey(),
        'responsible_user_id' => $participant->getKey(),
    ]);
}

function lifecycleMeasurement(Operation $operation, string $status): Measurement
{
    return Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => $status,
        'current_stage' => 1,
    ]);
}

// ── Enum ──────────────────────────────────────────────────────────────────────

it('exposes exactly the four lifecycle states with their labels', function () {
    expect(array_map(fn (OperationStatus $s): string => $s->value, OperationStatus::cases()))
        ->toBe(['draft', 'active', 'completed', 'canceled'])
        ->and(OperationStatus::options())->toBe([
            'draft' => 'Rascunho',
            'active' => 'Em Andamento',
            'completed' => 'Concluída',
            'canceled' => 'Cancelada',
        ]);
});

it('answers each capability from the status itself', function (
    OperationStatus $status,
    bool $terminal,
    bool $measurements,
    bool $responsibilities,
    bool $delegations,
) {
    expect($status->isTerminal())->toBe($terminal)
        ->and($status->allowsNewMeasurements())->toBe($measurements)
        ->and($status->allowsResponsibilityChanges())->toBe($responsibilities)
        ->and($status->allowsNewDelegations())->toBe($delegations);
})->with([
    'draft' => [OperationStatus::Draft, false, false, true, false],
    'active' => [OperationStatus::Active, false, true, true, true],
    'completed' => [OperationStatus::Completed, true, false, false, false],
    'canceled' => [OperationStatus::Canceled, true, false, false, false],
]);

it('casts the persisted string into the enum without changing stored values', function () {
    $operation = lifecycleOperation(OperationStatus::Draft);

    expect($operation->fresh()->status)->toBe(OperationStatus::Draft)
        ->and($operation->fresh()->getRawOriginal('status'))->toBe('draft');
});

// ── Transições ────────────────────────────────────────────────────────────────

it('allows the six lifecycle transitions', function (OperationStatus $from, OperationStatus $to) {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation($from, $admin);

    app(OperationLifecycleService::class)->transitionTo($operation, $to, $admin, 'motivo da transição');

    expect($operation->fresh()->status)->toBe($to);
})->with([
    'draft → active' => [OperationStatus::Draft, OperationStatus::Active],
    'draft → canceled' => [OperationStatus::Draft, OperationStatus::Canceled],
    'active → completed' => [OperationStatus::Active, OperationStatus::Completed],
    'active → canceled' => [OperationStatus::Active, OperationStatus::Canceled],
    'completed → active' => [OperationStatus::Completed, OperationStatus::Active],
    'canceled → active' => [OperationStatus::Canceled, OperationStatus::Active],
]);

it('refuses every transition outside the lifecycle table', function (OperationStatus $from, OperationStatus $to) {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation($from, $admin);

    expect(fn () => app(OperationLifecycleService::class)->transitionTo($operation, $to, $admin, 'motivo'))
        ->toThrow(OperationLifecycleException::class);

    expect($operation->fresh()->status)->toBe($from);
})->with([
    'completed → draft' => [OperationStatus::Completed, OperationStatus::Draft],
    'canceled → draft' => [OperationStatus::Canceled, OperationStatus::Draft],
    'completed → canceled' => [OperationStatus::Completed, OperationStatus::Canceled],
    'canceled → completed' => [OperationStatus::Canceled, OperationStatus::Completed],
    'draft → completed' => [OperationStatus::Draft, OperationStatus::Completed],
    'active → draft' => [OperationStatus::Active, OperationStatus::Draft],
    'draft → draft' => [OperationStatus::Draft, OperationStatus::Draft],
]);

it('requires a reason to cancel and to reopen, but not to activate or complete', function () {
    $admin = lifecycleAdmin();
    $service = app(OperationLifecycleService::class);

    $draft = lifecycleOperation(OperationStatus::Draft, $admin);
    expect(fn () => $service->cancel($draft, $admin, '   '))
        ->toThrow(OperationLifecycleException::class, 'Informe o motivo desta alteração de situação da operação.');

    $service->activate($draft, $admin);
    expect($draft->fresh()->status)->toBe(OperationStatus::Active);

    $service->complete($draft, $admin);
    expect($draft->fresh()->status)->toBe(OperationStatus::Completed);

    expect(fn () => $service->reopen($draft, $admin, ''))
        ->toThrow(OperationLifecycleException::class);
});

// ── Portão do encerramento ────────────────────────────────────────────────────

it('refuses to close an operation that still has an open measurement', function (string $measurementStatus) {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    lifecycleMeasurement($operation, $measurementStatus);
    $service = app(OperationLifecycleService::class);

    expect(fn () => $service->complete($operation, $admin))
        ->toThrow(OperationLifecycleException::class, 'medição em andamento')
        ->and(fn () => $service->cancel($operation, $admin, 'motivo'))
        ->toThrow(OperationLifecycleException::class, 'medição em andamento');

    expect($operation->fresh()->status)->toBe(OperationStatus::Active);
})->with(Measurement::OPEN_STATUSES);

it('closes an operation whose measurements are all finished', function (string $measurementStatus) {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    lifecycleMeasurement($operation, $measurementStatus);

    app(OperationLifecycleService::class)->complete($operation, $admin);

    expect($operation->fresh()->status)->toBe(OperationStatus::Completed);
})->with(Measurement::CLOSED_STATUSES);

it('names how many measurements are blocking the closure', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    lifecycleMeasurement($operation, 'in_review');
    lifecycleMeasurement($operation, 'awaiting_payment');
    lifecycleMeasurement($operation, 'finalized');

    expect(fn () => app(OperationLifecycleService::class)->complete($operation, $admin))
        ->toThrow(OperationLifecycleException::class, 'A operação possui 2 medições em andamento');
});

// ── Capacidades por estado ────────────────────────────────────────────────────

it('accepts new measurements only while the operation is active', function (OperationStatus $status, bool $allowed) {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation($status, $admin);

    expect(app(MeasurementAuthorizationService::class)->canCreateMeasurement($admin, $operation))->toBe($allowed);
})->with([
    'draft' => [OperationStatus::Draft, false],
    'active' => [OperationStatus::Active, true],
    'completed' => [OperationStatus::Completed, false],
    'canceled' => [OperationStatus::Canceled, false],
]);

it('accepts responsibility changes while preparing and operating, never after closing', function (
    OperationStatus $status,
    bool $allowed,
) {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation($status, $admin);
    $newReviewer = User::factory()->create();
    $this->actingAs($admin);

    $change = fn () => app(OperationResponsibilityService::class)->assertCanChange(
        $admin,
        $operation,
        ['stage2_reviewer_user_id' => $newReviewer->getKey()],
    );

    if ($allowed) {
        $change();
        expect(true)->toBeTrue();

        return;
    }

    expect($change)->toThrow(OperationLifecycleException::class, 'não podem ser alterados');
})->with([
    'draft' => [OperationStatus::Draft, true],
    'active' => [OperationStatus::Active, true],
    'completed' => [OperationStatus::Completed, false],
    'canceled' => [OperationStatus::Canceled, false],
]);

it('keeps the recorded responsibilities untouched after the operation closes', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);

    app(OperationLifecycleService::class)->complete($operation, $admin);

    expect($operation->fresh()->responsible_user_id)->toBe($admin->getKey())
        ->and($operation->fresh()->assigned_user_id)->toBe($admin->getKey());
});

it('accepts new scoped delegations only while the operation is active', function (
    OperationStatus $status,
    bool $allowed,
) {
    $admin = lifecycleAdmin();
    $delegator = User::factory()->create();
    $delegate = User::factory()->create();
    $delegator->givePermissionTo('measurements.review');
    $delegate->givePermissionTo('measurements.review');

    $operation = Operation::factory()->create([
        'status' => $status,
        'responsible_user_id' => $delegator->getKey(),
    ]);

    $create = fn () => app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => 'operation',
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias da Engenharia.',
    ], $admin);

    if ($allowed) {
        expect($create()->scope_operation_id)->toBe($operation->getKey());

        return;
    }

    expect($create)->toThrow(ValidationException::class)
        ->and(ResponsibilityDelegation::query()->count())->toBe(0);
})->with([
    'draft' => [OperationStatus::Draft, false],
    'active' => [OperationStatus::Active, true],
    'completed' => [OperationStatus::Completed, false],
    'canceled' => [OperationStatus::Canceled, false],
]);

it('never revokes or rewrites an existing delegation when the operation closes', function () {
    $admin = lifecycleAdmin();
    $delegator = User::factory()->create();
    $delegate = User::factory()->create();
    $delegator->givePermissionTo('measurements.review');
    $delegate->givePermissionTo('measurements.review');
    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'assigned_user_id' => $admin->getKey(),
        'responsible_user_id' => $delegator->getKey(),
    ]);

    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => 'operation',
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias da Engenharia.',
    ], $admin);
    $before = $delegation->fresh()->getAttributes();

    app(OperationLifecycleService::class)->cancel($operation, $admin, 'Obra suspensa pelo cedente.');

    expect($delegation->fresh()->getAttributes())->toBe($before)
        ->and($delegation->fresh()->revoked_at)->toBeNull()
        ->and($delegation->fresh()->scope_operation_id)->toBe($operation->getKey());
});

it('keeps a closed operation readable and its history reachable', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    $measurement = lifecycleMeasurement($operation, 'finalized');

    app(OperationLifecycleService::class)->cancel($operation, $admin, 'Obra suspensa.');

    expect(Operation::query()->visibleTo($admin)->whereKey($operation->getKey())->exists())->toBeTrue()
        ->and($operation->fresh()->measurements()->count())->toBe(1)
        ->and($measurement->fresh()->operation_id)->toBe($operation->getKey())
        ->and(app(MeasurementAuthorizationService::class)->canViewOperation($admin, $operation->fresh()))->toBeTrue();
});

// ── Reabertura ────────────────────────────────────────────────────────────────

it('lets administrators reopen a closed operation with a reason', function (string $role) {
    $actor = lifecycleAdmin($role);
    $operation = lifecycleOperation(OperationStatus::Completed, $actor);

    app(OperationLifecycleService::class)->reopen($operation, $actor, 'Encerramento prematuro: faltou a medição de agosto.');

    expect($operation->fresh()->status)->toBe(OperationStatus::Active);
})->with(['admin', 'super-admin']);

it('refuses reopening for an editor who can otherwise update the operation', function () {
    $editor = User::factory()->withTwoFactor()->create();
    $editor->assignRole('editor');
    $operation = lifecycleOperation(OperationStatus::Canceled, $editor);
    $this->actingAs($editor);

    expect($editor->can('operations.update'))->toBeTrue()
        ->and($editor->can('update', $operation))->toBeTrue()
        ->and(fn () => app(OperationLifecycleService::class)->reopen($operation, $editor, 'quero reabrir'))
        ->toThrow(OperationLifecycleException::class, 'restrita a administradores');

    expect($operation->fresh()->status)->toBe(OperationStatus::Canceled);
});

it('refuses a lifecycle transition for someone who cannot update the operation', function () {
    $editor = User::factory()->withTwoFactor()->create();
    $editor->assignRole('editor');
    $stranger = lifecycleOperation(OperationStatus::Draft);
    $this->actingAs($editor);

    expect(fn () => app(OperationLifecycleService::class)->activate($stranger, $editor))
        ->toThrow(AuthorizationException::class);
});

// ── Preflight ─────────────────────────────────────────────────────────────────

it('lists what the reopening restores without touching a single row', function () {
    $admin = lifecycleAdmin();
    $delegator = User::factory()->create(['name' => 'Eng. Marina']);
    $delegate = User::factory()->create(['name' => 'Eng. Paulo']);
    $delegator->givePermissionTo('measurements.review');
    $delegate->givePermissionTo('measurements.review');

    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'assigned_user_id' => $admin->getKey(),
        'responsible_user_id' => $delegator->getKey(),
    ]);
    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => 'operation',
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias da Engenharia.',
    ], $admin);
    app(OperationLifecycleService::class)->complete($operation, $admin);

    $snapshot = [
        'operation' => $operation->fresh()->getAttributes(),
        'delegation' => $delegation->fresh()->getAttributes(),
    ];

    $preflight = app(OperationLifecycleService::class)->reopenPreflight($operation->fresh());

    expect($preflight->restoresAuthority())->toBeTrue()
        ->and($preflight->responsibilityCount())->toBe(2)
        ->and($preflight->responsibilityLines())->toContain(
            MeasurementResponsibility::EngineeringReviewer->label().' — Eng. Marina',
            'Coordenação da operação — '.$admin->name,
        )
        ->and($preflight->delegationCount())->toBe(1)
        ->and($preflight->delegationLines()[0])->toContain('Eng. Marina → Eng. Paulo')
        // Nada foi tocado: nem a operação, nem a delegação.
        ->and($operation->fresh()->getAttributes())->toBe($snapshot['operation'])
        ->and($delegation->fresh()->getAttributes())->toBe($snapshot['delegation']);
});

it('omits from the preflight the delegations that would stay ineffective', function () {
    $admin = lifecycleAdmin();
    $delegator = User::factory()->create();
    $delegate = User::factory()->create();
    $delegator->givePermissionTo('measurements.review');
    $delegate->givePermissionTo('measurements.review');
    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'assigned_user_id' => $admin->getKey(),
        'responsible_user_id' => $delegator->getKey(),
    ]);
    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => 'operation',
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias da Engenharia.',
    ], $admin);
    app(OperationLifecycleService::class)->complete($operation, $admin);
    app(ResponsibilityDelegationService::class)->revokeDelegation($delegation, $admin, 'Não é mais necessária.');

    $preflight = app(OperationLifecycleService::class)->reopenPreflight($operation->fresh());

    expect($preflight->delegationCount())->toBe(0)
        ->and($preflight->responsibilityCount())->toBe(2);
});

// ── Hard delete ───────────────────────────────────────────────────────────────

it('refuses to erase an operation that has any measurement at all', function (string $measurementStatus) {
    $operation = lifecycleOperation(OperationStatus::Draft);
    lifecycleMeasurement($operation, $measurementStatus);

    expect(fn () => $operation->delete())
        ->toThrow(OperationLifecycleException::class, 'não pode ser excluída');

    expect(Operation::query()->whereKey($operation->getKey())->exists())->toBeTrue()
        ->and(Measurement::query()->where('operation_id', $operation->getKey())->count())->toBe(1);
})->with(['pending', 'in_review', 'approved', 'rejected', 'finalized']);

it('keeps refusing to erase an operation that has delegation history', function () {
    $admin = lifecycleAdmin();
    $delegator = User::factory()->create();
    $delegate = User::factory()->create();
    $delegator->givePermissionTo('measurements.review');
    $delegate->givePermissionTo('measurements.review');
    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'responsible_user_id' => $delegator->getKey(),
    ]);
    app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => 'operation',
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias da Engenharia.',
    ], $admin);

    expect(fn () => $operation->delete())->toThrow(DelegationHistoryException::class);
});

it('offers no lifecycle deletion path on the view page', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Draft, $admin);
    $this->actingAs($admin);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionExists('activate_operation')
        ->assertActionExists('cancel_operation');
});

// ── Ações da interface ────────────────────────────────────────────────────────

it('activates and completes an operation through the page actions', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Draft, $admin);
    $this->actingAs($admin);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->callAction('activate_operation')
        ->assertNotified('Operação ativada.');

    expect($operation->fresh()->status)->toBe(OperationStatus::Active);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->callAction('complete_operation')
        ->assertNotified('Operação concluída.');

    expect($operation->fresh()->status)->toBe(OperationStatus::Completed);
});

it('cancels through the page action and keeps the typed reason in the audit trail', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    $this->actingAs($admin);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->callAction('cancel_operation', ['reason' => 'Obra embargada pela prefeitura.'])
        ->assertHasNoActionErrors()
        ->assertNotified('Operação cancelada.');

    expect($operation->fresh()->status)->toBe(OperationStatus::Canceled)
        ->and(Activity::query()
            ->where('description', 'operation_lifecycle_transition')
            ->where('subject_id', $operation->getKey())
            ->value('properties')['reason'])->toBe('Obra embargada pela prefeitura.');
});

it('refuses the cancel action without a reason', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    $this->actingAs($admin);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->callAction('cancel_operation', ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($operation->fresh()->status)->toBe(OperationStatus::Active);
});

it('reopens through the page action only for administrators', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Completed, $admin);
    $this->actingAs($admin);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->assertActionVisible('reopen_operation')
        ->callAction('reopen_operation', ['reason' => 'Faltou a medição de agosto.'])
        ->assertHasNoActionErrors()
        ->assertNotified('Operação reaberta.');

    expect($operation->fresh()->status)->toBe(OperationStatus::Active);

    $editor = User::factory()->withTwoFactor()->create();
    $editor->assignRole('editor');
    $closed = lifecycleOperation(OperationStatus::Canceled, $editor);
    $this->actingAs($editor);

    Livewire::test(ViewOperation::class, ['record' => $closed->getKey()])
        ->assertActionHidden('reopen_operation');
});

it('hides the lifecycle actions that do not apply to the current state', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    $this->actingAs($admin);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->assertActionHidden('activate_operation')
        ->assertActionVisible('complete_operation')
        ->assertActionVisible('cancel_operation')
        ->assertActionHidden('reopen_operation');
});

it('refuses the closure action while a measurement is still open', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);
    lifecycleMeasurement($operation, 'in_review');
    $this->actingAs($admin);

    Livewire::test(ViewOperation::class, ['record' => $operation->getKey()])
        ->callAction('complete_operation')
        ->assertNotified('Situação não alterada.');

    expect($operation->fresh()->status)->toBe(OperationStatus::Active)
        ->and(Activity::query()->where('description', 'operation_lifecycle_transition')->count())->toBe(0);
});

// ── Payload manipulado ────────────────────────────────────────────────────────

it('refuses a measurement aimed at an operation the select never offered', function (OperationStatus $status) {
    $admin = lifecycleAdmin();
    $this->actingAs($admin);
    $operation = lifecycleOperation($status, $admin);

    // O select não oferece esta operação, mas o id chega pela requisição e pode
    // ser trocado: quem recusa de verdade é o backend, sob o lock da operação.
    Livewire::test(CreateMeasurement::class)
        ->fillForm([
            'operation_id' => $operation->getKey(),
            'reference_month' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['operation_id']);

    expect(Measurement::query()->where('operation_id', $operation->getKey())->count())->toBe(0);
})->with([
    'draft' => [OperationStatus::Draft],
    'completed' => [OperationStatus::Completed],
    'canceled' => [OperationStatus::Canceled],
]);

it('fails closed when the column holds a status the lifecycle does not know', function () {
    $operation = lifecycleOperation(OperationStatus::Active);

    // Valor gravado fora da aplicação: nada de voltar para "active"
    // silenciosamente -- ler a operação passa a falhar, e é isso que se quer.
    DB::table('operations')->where('id', $operation->getKey())->update(['status' => 'settled']);

    expect(fn () => Operation::query()->findOrFail($operation->getKey())->status)
        ->toThrow(ValueError::class);
});

// ── Activitylog ───────────────────────────────────────────────────────────────

it('records each transition once, under the operations log, with origin, target and reason', function () {
    $admin = lifecycleAdmin();
    $operation = lifecycleOperation(OperationStatus::Active, $admin);

    app(OperationLifecycleService::class)->cancel($operation, $admin, 'Obra embargada pela prefeitura.');

    $activities = Activity::query()
        ->where('subject_type', Operation::class)
        ->where('subject_id', $operation->getKey())
        ->where('description', 'operation_lifecycle_transition')
        ->get();

    expect($activities)->toHaveCount(1);

    $activity = $activities->first();

    expect($activity->log_name)->toBe('operations')
        ->and($activity->event)->toBe('lifecycle_transition')
        ->and($activity->causer_id)->toBe($admin->getKey())
        ->and($activity->properties['from'])->toBe('active')
        ->and($activity->properties['to'])->toBe('canceled')
        ->and($activity->properties['reason'])->toBe('Obra embargada pela prefeitura.')
        // Uma linha por transição: o `updated` automático não duplica o evento.
        ->and(Activity::query()
            ->where('subject_type', Operation::class)
            ->where('subject_id', $operation->getKey())
            ->where('description', 'updated')
            ->whereJsonContains('properties->attributes->status', 'canceled')
            ->count())->toBe(0);
});

it('writes the remaining operation history under the operations log too', function () {
    $admin = lifecycleAdmin();
    $this->actingAs($admin);
    $operation = lifecycleOperation(OperationStatus::Active, $admin);

    $operation->update(['title' => 'Título revisado']);

    expect(Activity::query()
        ->where('subject_type', Operation::class)
        ->where('subject_id', $operation->getKey())
        ->where('description', 'updated')
        ->value('log_name'))->toBe('operations');
});

// ── Selects ───────────────────────────────────────────────────────────────────

it('offers only active operations when sending a new measurement', function () {
    $admin = lifecycleAdmin();
    $this->actingAs($admin);

    $active = lifecycleOperation(OperationStatus::Active, $admin);
    $draft = lifecycleOperation(OperationStatus::Draft, $admin);
    $completed = lifecycleOperation(OperationStatus::Completed, $admin);
    $canceled = lifecycleOperation(OperationStatus::Canceled, $admin);

    Livewire::test(CreateMeasurement::class)
        ->assertFormFieldExists('operation_id', function (Select $field) use ($active, $draft, $completed, $canceled): bool {
            $options = array_keys($field->getOptions());

            expect($options)->toContain($active->getKey())
                ->and($options)->not->toContain($draft->getKey())
                ->and($options)->not->toContain($completed->getKey())
                ->and($options)->not->toContain($canceled->getKey());

            return true;
        });
});

it('offers only active operations as a new delegation scope', function () {
    $admin = lifecycleAdmin();
    $this->actingAs($admin);

    $active = lifecycleOperation(OperationStatus::Active, $admin);
    $completed = lifecycleOperation(OperationStatus::Completed, $admin);

    Livewire::test(CreateResponsibilityDelegation::class)
        ->assertFormFieldExists('scope_operation_id', function (Select $field) use ($active, $completed): bool {
            $options = array_keys($field->getOptions());

            expect($options)->toContain($active->getKey())
                ->and($options)->not->toContain($completed->getKey());

            return true;
        });
});
