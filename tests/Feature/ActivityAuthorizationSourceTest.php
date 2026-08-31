<?php

use App\Enums\WorkflowAuthorizationSource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ManageActivities;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A P2.3 passou a gravar a origem da autorização na Activity, mas ela vivia em
 * dois booleanos dentro do JSON: distinguir uma ação do responsável direto de um
 * override administrativo exigia ler `properties` a olho. Estes testes fixam que
 * a auditoria agora diz isso em português, e que a leitura vem dos mesmos campos
 * que a P2.3 grava -- nenhuma origem é recalculada na tela.
 *
 * Grupo `parity`: o filtro consulta a origem dentro do JSON, e é o banco que
 * decide o que `properties->admin_override = true` significa.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/**
 * @return array{operation: Operation, measurement: Measurement, responsible: User}
 */
function auditSourceScenario(?User $responsible = null): array
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

function auditSourceActivity(Measurement $measurement, User $actor): Activity
{
    app(MeasurementWorkflow::class)->pause($measurement, $actor, 'Motivo de auditoria');

    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->latest('id')
        ->firstOrFail();
}

it('reads a direct action as the responsible acting for themselves', function () {
    $scenario = auditSourceScenario();
    $activity = auditSourceActivity($scenario['measurement'], $scenario['responsible']);

    expect(ActivityResource::authorizationSource($activity))->toBe(WorkflowAuthorizationSource::Direct)
        ->and(ActivityResource::authorizationSource($activity)->auditLabel())->toBe('Responsável direto')
        ->and(ActivityResource::authorizationDetail($activity))->toBeNull();
});

it('reads a delegated action and says whose responsibility it was', function () {
    $scenario = auditSourceScenario();
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
        'delegator_user_id' => $scenario['responsible']->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $activity = auditSourceActivity($scenario['measurement'], $delegate);

    expect(ActivityResource::authorizationSource($activity))->toBe(WorkflowAuthorizationSource::Delegated)
        ->and(ActivityResource::authorizationSource($activity)->auditLabel())->toBe('Delegação')
        ->and(ActivityResource::authorizationDetail($activity))
        ->toContain($scenario['responsible']->name)
        ->and(ActivityResource::authorizationDetail($activity))->toContain('Operação específica');
});

it('reads an administrative override as what it is, not as an error', function () {
    $scenario = auditSourceScenario();
    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole('admin');

    $activity = auditSourceActivity($scenario['measurement'], $admin);
    $source = ActivityResource::authorizationSource($activity);

    expect($source)->toBe(WorkflowAuthorizationSource::AdminOverride)
        ->and($source->auditLabel())->toBe('Override administrativo')
        // Sensível e auditável, não falha: nada de vermelho de erro.
        ->and($source->auditColor())->toBe('warning')
        ->and($source->auditColor())->not->toBe('danger');
});

it('attributes no source to an activity that is not a workflow action', function () {
    $actor = User::factory()->create();
    activity('default')->causedBy($actor)->withProperties(['whatever' => true])->log('created');
    $activity = Activity::query()->where('log_name', 'default')->latest('id')->firstOrFail();

    expect(ActivityResource::authorizationSource($activity))->toBeNull()
        ->and(ActivityResource::authorizationDetail($activity))->toBeNull();
});

it('keeps the actor as the person who acted, whatever authorized them', function () {
    $scenario = auditSourceScenario();
    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole('admin');

    $activity = auditSourceActivity($scenario['measurement'], $admin);

    expect((int) $activity->causer_id)->toBe((int) $admin->getKey())
        ->and(ActivityResource::authorizationSource($activity))->toBe(WorkflowAuthorizationSource::AdminOverride);
});

it('filters the audit listing down to the administrative overrides', function () {
    $direct = auditSourceScenario();
    auditSourceActivity($direct['measurement'], $direct['responsible']);

    $overridden = auditSourceScenario();
    $admin = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $admin->assignRole('admin');
    $overrideActivity = auditSourceActivity($overridden['measurement'], $admin);

    $auditor = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $auditor->assignRole('super-admin');
    $this->actingAs($auditor);

    Livewire::test(ManageActivities::class)
        ->filterTable('authorization_source', WorkflowAuthorizationSource::AdminOverride->value)
        ->assertCanSeeTableRecords([$overrideActivity])
        ->assertCanNotSeeTableRecords(
            Activity::query()
                ->where('log_name', 'measurement_workflow')
                ->whereKeyNot($overrideActivity->getKey())
                ->get()
                ->all(),
        );
});
