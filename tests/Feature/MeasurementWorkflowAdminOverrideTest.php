<?php

use App\Enums\MeasurementResponsibility;
use App\Enums\WorkflowAuthorizationSource;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/**
 * Medição parada na Engenharia, cuja responsabilidade direta é de outro usuário
 * a menos que o próprio ator seja indicado.
 *
 * @return array{operation: Operation, measurement: Measurement, responsible: User}
 */
function overrideScenario(?User $responsible = null): array
{
    $responsible ??= tap(
        User::factory()->create(['is_active' => true, 'approved_at' => now()]),
        fn (User $user) => $user->givePermissionTo('measurements.review'),
    );

    $operation = Operation::factory()->create(['responsible_user_id' => $responsible->getKey()]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    return compact('operation', 'measurement', 'responsible');
}

function pauseAndReadAudit(Measurement $measurement, User $actor): Activity
{
    app(MeasurementWorkflow::class)->pause($measurement, $actor, 'Motivo de auditoria');

    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->latest('id')
        ->firstOrFail();
}

it('não marca override para o responsável direto', function () {
    $scenario = overrideScenario();
    $activity = pauseAndReadAudit($scenario['measurement'], $scenario['responsible']);

    expect($activity->properties['admin_override'])->toBeFalse()
        ->and($activity->properties['delegated'])->toBeFalse()
        ->and((int) $activity->properties['actual_actor_user_id'])->toBe((int) $scenario['responsible']->getKey());
});

it('não marca override para o delegado efetivo', function () {
    $scenario = overrideScenario();
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $delegation = ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
        'delegator_user_id' => $scenario['responsible']->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $activity = pauseAndReadAudit($scenario['measurement'], $delegate);

    expect($activity->properties['admin_override'])->toBeFalse()
        ->and($activity->properties['delegated'])->toBeTrue()
        ->and((int) $activity->properties['delegation_id'])->toBe((int) $delegation->getKey())
        ->and((int) $activity->properties['delegator_user_id'])->toBe((int) $scenario['responsible']->getKey())
        ->and((int) $activity->properties['actual_actor_user_id'])->toBe((int) $delegate->getKey());
});

it('marca override quando só o bypass administrativo autoriza', function (string $role) {
    $scenario = overrideScenario();
    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole($role);

    $activity = pauseAndReadAudit($scenario['measurement'], $admin);

    expect($activity->properties['admin_override'])->toBeTrue()
        ->and($activity->properties['delegated'])->toBeFalse()
        ->and($activity->properties['delegation_id'])->toBeNull()
        // O ator registrado continua sendo o próprio administrador.
        ->and((int) $activity->properties['actual_actor_user_id'])->toBe((int) $admin->getKey())
        ->and((int) $activity->causer_id)->toBe((int) $admin->getKey());
})->with(['admin', 'super-admin']);

it('não marca override para o administrador que é o responsável direto', function () {
    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole('admin');
    $scenario = overrideScenario($admin);

    $activity = pauseAndReadAudit($scenario['measurement'], $admin);

    expect($activity->properties['admin_override'])->toBeFalse()
        ->and($activity->properties['delegated'])->toBeFalse();
});

it('não marca override para o administrador que age por delegação efetiva', function () {
    $scenario = overrideScenario();
    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole('admin');
    $delegation = ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
        'delegator_user_id' => $scenario['responsible']->getKey(),
        'delegate_user_id' => $admin->getKey(),
    ]);

    $activity = pauseAndReadAudit($scenario['measurement'], $admin);

    expect($activity->properties['admin_override'])->toBeFalse()
        ->and($activity->properties['delegated'])->toBeTrue()
        ->and((int) $activity->properties['delegation_id'])->toBe((int) $delegation->getKey());
});

it('nunca registra ação simultaneamente delegada e por override', function () {
    $scenario = overrideScenario();
    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole('admin');
    ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
        'delegator_user_id' => $scenario['responsible']->getKey(),
        'delegate_user_id' => $admin->getKey(),
    ]);

    $activity = pauseAndReadAudit($scenario['measurement'], $admin);

    expect($activity->properties['delegated'] && $activity->properties['admin_override'])->toBeFalse();
});

it('preserva as propriedades de auditoria já existentes ao lado de admin_override', function () {
    $scenario = overrideScenario();
    $activity = pauseAndReadAudit($scenario['measurement'], $scenario['responsible']);

    expect(array_keys($activity->properties->all()))
        ->toContain(
            'actual_actor_user_id',
            'delegated',
            'delegation_id',
            'delegator_user_id',
            'delegation_scope',
            'workflow_revision',
            'admin_override',
        );
});

it('resolve a origem da autorização a partir da mesma decisão que autoriza a ação', function () {
    $scenario = overrideScenario();
    $authorization = app(MeasurementAuthorizationService::class);

    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
        'delegator_user_id' => $scenario['responsible']->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole('admin');

    $stranger = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $stranger->givePermissionTo('measurements.review');

    $sources = [
        'direto' => $authorization->resolveAuthorization($scenario['responsible'], $scenario['measurement'], MeasurementResponsibility::EngineeringReviewer),
        'delegado' => $authorization->resolveAuthorization($delegate, $scenario['measurement'], MeasurementResponsibility::EngineeringReviewer),
        'override' => $authorization->resolveAuthorization($admin, $scenario['measurement'], MeasurementResponsibility::EngineeringReviewer),
        'nenhuma' => $authorization->resolveAuthorization($stranger, $scenario['measurement'], MeasurementResponsibility::EngineeringReviewer),
    ];

    expect(array_map(fn ($result): string => $result->source->value, $sources))->toBe([
        'direto' => WorkflowAuthorizationSource::Direct->value,
        'delegado' => WorkflowAuthorizationSource::Delegated->value,
        'override' => WorkflowAuthorizationSource::AdminOverride->value,
        'nenhuma' => WorkflowAuthorizationSource::None->value,
    ])
        // A mesma resolução responde pela autorização da ação.
        ->and($authorization->canDecideStage($scenario['responsible'], $scenario['measurement'], 1))->toBeTrue()
        ->and($authorization->canDecideStage($delegate, $scenario['measurement'], 1))->toBeTrue()
        ->and($authorization->canDecideStage($admin, $scenario['measurement'], 1))->toBeTrue()
        ->and($authorization->canDecideStage($stranger, $scenario['measurement'], 1))->toBeFalse();
});
