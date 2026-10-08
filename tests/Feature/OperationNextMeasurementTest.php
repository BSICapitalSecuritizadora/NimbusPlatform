<?php

use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationNextMeasurementResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;
use Tests\Support\MeasurementPlanVersionFixture;

uses(RefreshDatabase::class);

/**
 * Medição prevista no rascunho da V1 do plano. A próxima competência sai só do
 * cronograma vigente: o teste cria as linhas e depois ativa o plano
 * ({@see MeasurementPlanVersionFixture::activate()}).
 *
 * @param  array<string, mixed>  $attributes
 */
function makeNextMeasurementLine(
    MeasurementPlanSet $plan,
    int $sequence,
    ?string $date,
    array $attributes = [],
): MeasurementPlanLine {
    return MeasurementPlanLine::factory()->create([
        'operation_id' => $plan->operation_id,
        'plan_set_id' => $plan->id,
        'sequence_number' => $sequence,
        'measurement_date' => $date,
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
        ...$attributes,
    ]);
}

function resolveNextMeasurementMonth(Operation $operation): ?string
{
    return app(OperationNextMeasurementResolver::class)
        ->addNextMeasurementDate(Operation::query())
        ->findOrFail($operation->id)
        ->next_pending_measurement_at?->format('m/Y');
}

/**
 * Aprovação da Engenharia anterior ao snapshot: a linha que ela gravou é o
 * único registro do que foi aprovado, então continua consumindo a competência.
 */
function makeNextMeasurementLegacyClaimant(MeasurementPlanSet $plan, string $referenceMonth): Measurement
{
    $measurement = Measurement::factory()->create([
        'operation_id' => $plan->operation_id,
        'reference_month' => $referenceMonth,
        'status' => 'in_review',
        'current_stage' => 2,
        'engineering_snapshot' => null,
        'filename' => null,
        'storage_path' => null,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'approved']);

    return $measurement;
}

/**
 * Os cenários que passam pelo fluxo real da Engenharia precisam das permissões
 * semeadas, do disco falso e das notificações contidas.
 */
function prepareNextMeasurementWorkflow(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
}

it('suggests May after finalized April even when the pending competence is overdue', function () {
    $this->travelTo(now()->setDate(2026, 9, 8));
    $operation = Operation::factory()->create(['next_measurement_at' => '2027-01-15']);
    $plan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-04-01',
        'status' => 'finalized',
        'filename' => null,
        'storage_path' => null,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'approved']);
    makeNextMeasurementLine($plan, 1, '2026-04-01', [
        'planned_monthly_percent' => 3.01,
        'planned_cumulative_percent' => 8.04,
        'realized_monthly_percent' => 3.01,
        'realized_cumulative_percent' => 3.01,
        'measurement_id' => $measurement->id,
    ]);
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    makeNextMeasurementLine($plan, 3, '2026-06-01');
    MeasurementPlanVersionFixture::activate($plan);

    expect(resolveNextMeasurementMonth($operation))->toBe('05/2026')
        ->and($operation->fresh()->next_measurement_at->toDateString())->toBe('2027-01-15');
})->group('parity');

it('returns no next competence when every line is claimed by a current Engineering approval', function (float $monthly, float $cumulative) {
    $plan = MeasurementPlanSet::factory()->default()->create();

    foreach ([1 => '2026-04-01', 2 => '2026-05-01', 3 => '2026-06-01'] as $sequence => $date) {
        makeNextMeasurementLine($plan, $sequence, $date, [
            'realized_monthly_percent' => $monthly,
            'realized_cumulative_percent' => $cumulative,
            'measurement_id' => makeNextMeasurementLegacyClaimant($plan, $date)->id,
        ]);
    }

    // Plano vigente: sem a ativação, o resultado seria nulo só por não haver
    // cronograma em vigor, e não pelas reivindicações.
    MeasurementPlanVersionFixture::activate($plan);

    expect(resolveNextMeasurementMonth($plan->operation))->toBeNull();
})->with([
    'monthly progress' => [3.01, 3.01],
    'cumulative progress only' => [0.0, 3.01],
])->group('parity');

it('does not treat realized columns without a current Engineering approval as a consumed competence', function (float $monthly, float $cumulative) {
    $plan = MeasurementPlanSet::factory()->default()->create();

    foreach ([1 => '2026-04-01', 2 => '2026-05-01', 3 => '2026-06-01'] as $sequence => $date) {
        makeNextMeasurementLine($plan, $sequence, $date, [
            'realized_monthly_percent' => $monthly,
            'realized_cumulative_percent' => $cumulative,
        ]);
    }

    MeasurementPlanVersionFixture::activate($plan);

    expect(resolveNextMeasurementMonth($plan->operation))->toBe('04/2026');
})->with([
    'orphan left by a deleted measurement' => [3.01, 3.01],
    'cumulative progress only' => [0.0, 3.01],
])->group('parity');

it('suggests the first pending line in schedule sequence', function () {
    $plan = MeasurementPlanSet::factory()->default()->create();
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    makeNextMeasurementLine($plan, 1, '2026-04-01');
    MeasurementPlanVersionFixture::activate($plan);

    expect(resolveNextMeasurementMonth($plan->operation))->toBe('04/2026');
});

it('respects competence occupation by measurement assets before engineering consumes the line', function (string $status, string $expectedMonth) {
    $plan = MeasurementPlanSet::factory()->default()->create();
    $line = makeNextMeasurementLine($plan, 1, '2026-04-01');
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    // O plano só recebe arquivo de medição depois de vigente.
    MeasurementPlanVersionFixture::activate($plan);
    $measurement = Measurement::factory()->create([
        'operation_id' => $plan->operation_id,
        'reference_month' => '2026-04-01',
        'status' => $status,
        'filename' => null,
        'storage_path' => null,
    ]);
    $path = "nimbus_docs/measurements/assets/next-{$measurement->id}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.7\nnext competence\n%%EOF");
    $measurement->assets()->create([
        'plan_set_id' => $plan->id,
        'plan_line_id' => $line->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);

    expect($line->fresh()->measurement_id)->toBeNull()
        ->and(resolveNextMeasurementMonth($plan->operation))->toBe($expectedMonth);
})->with([
    ...array_map(fn (string $status): array => [$status, '05/2026'], Measurement::OPEN_STATUSES),
    'finalized asset' => ['finalized', '05/2026'],
    'rejected without realization can be resubmitted' => ['rejected', '04/2026'],
]);

it('keeps occupied the competence of a refused measurement that still holds a payment or a current approval', function (string $legacyState) {
    $plan = MeasurementPlanSet::factory()->default()->create();
    $line = makeNextMeasurementLine($plan, 1, '2026-04-01');
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    MeasurementPlanVersionFixture::activate($plan);
    $refused = Measurement::factory()->create([
        'operation_id' => $plan->operation_id,
        'reference_month' => '2026-04-01',
        'status' => 'rejected',
        'filename' => null,
        'storage_path' => null,
    ]);
    $path = "nimbus_docs/measurements/assets/refusal-{$refused->id}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.7\nlegacy refusal\n%%EOF");
    $refused->assets()->create([
        'plan_set_id' => $plan->id,
        'plan_line_id' => $line->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);

    match ($legacyState) {
        'payment' => MeasurementPayment::factory()->create([
            'operation_id' => $plan->operation_id,
            'measurement_id' => $refused->id,
            'plan_set_id' => $plan->id,
        ]),
        'current Engineering approval' => $refused->reviews()->create(['stage' => 1, 'status' => 'approved']),
    };

    expect($line->fresh()->measurement_id)->toBeNull()
        ->and(resolveNextMeasurementMonth($plan->operation))->toBe('05/2026');
})->with(['payment', 'current Engineering approval'])->group('parity');

it('suggests again a competence whose Engineering approval was invalidated and then refused', function () {
    prepareNextMeasurementWorkflow();
    $scenario = Scenario::plan();
    $may = Scenario::measured($scenario, '2026-05', 10);

    Scenario::returnToEngineering($scenario, $may);
    app(MeasurementWorkflow::class)->reject($may->fresh(), $scenario['actor'], 'Medição recusada pela Engenharia.');
    $line = $scenario['lines']['2026-05']->fresh();

    expect($may->fresh()->status)->toBe('rejected')
        ->and($line->measurement_id)->toBe($may->id)
        ->and($line->realized_monthly_percent)->toBe('10.00')
        ->and(resolveNextMeasurementMonth($scenario['operation']))->toBe('05/2026');
})->group('parity');

it('keeps the competence of a measurement returned to Engineering while it is still open', function () {
    prepareNextMeasurementWorkflow();
    $scenario = Scenario::plan();
    $may = Scenario::measured($scenario, '2026-05', 10);

    Scenario::returnToEngineering($scenario, $may);

    expect($may->fresh()->isOpen())->toBeTrue()
        ->and($may->fresh()->hasApprovedEngineering())->toBeFalse()
        ->and(resolveNextMeasurementMonth($scenario['operation']))->toBe('06/2026');
})->group('parity');

it('releases the old line of a measurement approved again on another schedule line', function () {
    prepareNextMeasurementWorkflow();
    $scenario = Scenario::plan();
    Scenario::measured($scenario, '2026-05', 10);
    $june = Scenario::measured($scenario, '2026-06', 5);

    Scenario::returnToEngineering($scenario, $june);
    $june->refresh();
    $june->assets()->sole()->update(['plan_line_id' => $scenario['lines']['2026-07']->id]);
    $june->update(['reference_month' => '2026-07-01']);
    Scenario::approveEngineering($scenario, $june, 5);

    expect($scenario['lines']['2026-06']->fresh()->measurement_id)->toBe($june->id)
        ->and($scenario['lines']['2026-07']->fresh()->measurement_id)->toBe($june->id)
        ->and(resolveNextMeasurementMonth($scenario['operation']))->toBe('06/2026');
})->group('parity');

it('uses the default plan without mixing competences from another plan or operation', function () {
    $operation = Operation::factory()->create();
    $secondary = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $default = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $other = MeasurementPlanSet::factory()->default()->create();
    makeNextMeasurementLine($secondary, 1, '2026-03-01');
    makeNextMeasurementLine($default, 1, '2026-05-01');
    makeNextMeasurementLine($other, 1, '2026-02-01');
    MeasurementPlanVersionFixture::activate($secondary, $default, $other);

    expect(resolveNextMeasurementMonth($operation))->toBe('05/2026')
        ->and(resolveNextMeasurementMonth($other->operation))->toBe('02/2026');
});

it('does not switch to a secondary plan when the default has no pending competence', function () {
    $operation = Operation::factory()->create();
    $default = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $secondary = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($default, 1, '2026-04-01', [
        'realized_cumulative_percent' => 100,
        'measurement_id' => makeNextMeasurementLegacyClaimant($default, '2026-04-01')->id,
    ]);
    makeNextMeasurementLine($secondary, 1, '2026-05-01');
    // Os dois vigentes: o secundário tem competência pendente e mesmo assim
    // não substitui o padrão.
    MeasurementPlanVersionFixture::activate($default, $secondary);

    expect(resolveNextMeasurementMonth($operation))->toBeNull();
});

it('uses the first registered plan when none is explicitly default', function () {
    $operation = Operation::factory()->create();
    $first = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $second = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($first, 1, '2026-05-01');
    makeNextMeasurementLine($second, 1, '2026-04-01');
    MeasurementPlanVersionFixture::activate($first, $second);

    expect($operation->defaultPlanSet()->id)->toBe($first->id)
        ->and(resolveNextMeasurementMonth($operation))->toBe('05/2026');
});

it('returns no competence without a plan or dated schedule and ignores the legacy date', function () {
    $operation = Operation::factory()->create(['next_measurement_at' => '2026-05-01']);

    expect(resolveNextMeasurementMonth($operation))->toBeNull();

    $plan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($plan, 1, null);
    MeasurementPlanVersionFixture::activate($plan);

    expect(resolveNextMeasurementMonth($operation))->toBeNull();
});

it('renders the same derived month in the operations list and detail and sorts by it', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
    $may = Operation::factory()->create(['next_measurement_at' => '2026-09-15']);
    $june = Operation::factory()->create(['next_measurement_at' => '2026-04-15']);
    $mayPlan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $may->id]);
    $junePlan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $june->id]);
    makeNextMeasurementLine($mayPlan, 1, '2026-05-01');
    makeNextMeasurementLine($junePlan, 1, '2026-06-01');
    MeasurementPlanVersionFixture::activate($mayPlan, $junePlan);

    Livewire::test(ListOperations::class)
        ->assertSuccessful()
        ->assertTableColumnFormattedStateSet('next_pending_measurement_at', '05/2026', $may)
        ->assertTableColumnFormattedStateSet('next_pending_measurement_at', '06/2026', $june)
        ->sortTable('next_pending_measurement_at')
        ->assertCanSeeTableRecords([$may, $june], inOrder: true);

    Livewire::test(ViewOperation::class, ['record' => $may->id])
        ->assertSuccessful()
        ->assertSee('05/2026')
        ->assertDontSee('15/09/2026');
});

it('skips the competences already covered by the initial physical progress of the plan', function (string $referenceDate, string $expected) {
    $this->travelTo(now()->setDate(2026, 9, 8));
    $operation = Operation::factory()->create();
    $plan = MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('35.00', $referenceDate)->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($plan, 1, '2026-04-01');
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    makeNextMeasurementLine($plan, 3, '2026-06-01');
    MeasurementPlanVersionFixture::activate($plan);

    expect(resolveNextMeasurementMonth($operation))->toBe($expected);
})->with([
    'covered through the end of May' => ['2026-05-31', '06/2026'],
    'mid-May: May still has progress to measure' => ['2026-05-15', '05/2026'],
])->group('parity');

/**
 * Plano vigente pelo serviço de versões em março de 2026 -- V1 com a medição 01
 * em abril e a 02 em maio -- e o rascunho da revisão, cópia dela, já gravado
 * com o cronograma informado.
 *
 * @param  list<array<string, mixed>>  $revisionLines  cronograma do rascunho; as cópias da V1 entram pelo `copy_of` (sequência na V1)
 * @return array{actor: User, service: MeasurementPlanVersionService, operation: Operation, firstVersion: MeasurementPlanVersion, revision: MeasurementPlanVersion}
 */
function makeNextMeasurementRevision(array $revisionLines): array
{
    prepareNextMeasurementWorkflow();
    test()->travelTo(now()->setDate(2026, 3, 10));
    $actor = makeAdminUser();
    $service = app(MeasurementPlanVersionService::class);
    $operation = Operation::factory()->create(['status' => 'active']);
    $plan = MeasurementPlanSet::factory()->default()->withConstructionFund('1000000.00')->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($plan, 1, '2026-04-01', ['planned_monthly_percent' => 10, 'planned_cumulative_percent' => 10]);
    makeNextMeasurementLine($plan, 2, '2026-05-01', ['planned_monthly_percent' => 10, 'planned_cumulative_percent' => 20]);
    $firstVersion = MeasurementPlanVersion::query()->where('plan_set_id', $plan->id)->draft()->sole();
    $firstVersion = $service->activate($firstVersion, $actor, (int) $firstVersion->revision);

    $revision = $service->createRevision($plan, $actor, [
        'revision_category' => 'schedule',
        'revision_reason' => 'Cronograma replanejado pela construtora.',
    ], (int) $firstVersion->getKey());
    $copies = $revision->lines()->get()->keyBy('sequence_number');
    $revision = $service->updateDraft($revision, $actor, [], array_map(
        fn (array $line): array => [
            'id' => isset($line['copy_of']) ? $copies[$line['copy_of']]->id : null,
            'planned_monthly_percent' => 10,
            'planned_cumulative_percent' => 0,
            ...array_diff_key($line, ['copy_of' => true]),
        ],
        $revisionLines,
    ), (int) $revision->revision);

    return compact('actor', 'service', 'operation', 'firstVersion', 'revision');
}

/**
 * O rascunho da revisão não vale antes de ativado. Ativado, o cronograma é o
 * dele, na ordem da competência e, no mesmo mês, da sequência: a medição
 * prevista que a revisão acrescenta com número maior num mês anterior não fica
 * escondida atrás das que já existiam.
 */
it('ignores a draft revision until it is activated and then orders its schedule by month before sequence', function () {
    ['service' => $service, 'actor' => $actor, 'operation' => $operation, 'revision' => $revision] = makeNextMeasurementRevision([
        ['copy_of' => 1, 'sequence_number' => 1, 'measurement_date' => '2026-04'],
        ['copy_of' => 2, 'sequence_number' => 2, 'measurement_date' => '2026-05'],
        ['sequence_number' => 3, 'measurement_date' => '2026-03'],
    ]);

    expect($revision->isDraft())->toBeTrue()
        ->and(resolveNextMeasurementMonth($operation))->toBe('04/2026');

    $service->activate($revision, $actor, (int) $revision->revision);

    expect(resolveNextMeasurementMonth($operation))->toBe('03/2026');
});

/**
 * A versão substituída é histórico: a medição prevista que a revisão tirou
 * não volta a ser sugerida.
 */
it('stops suggesting the competence that the activated revision removed', function () {
    ['service' => $service, 'actor' => $actor, 'operation' => $operation, 'firstVersion' => $firstVersion, 'revision' => $revision] = makeNextMeasurementRevision([
        ['copy_of' => 2, 'sequence_number' => 2, 'measurement_date' => '2026-05'],
    ]);

    expect(resolveNextMeasurementMonth($operation))->toBe('04/2026');

    $service->activate($revision, $actor, (int) $revision->revision);

    expect($firstVersion->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and(MeasurementPlanLine::query()->where('plan_version_id', $firstVersion->id)->whereDate('measurement_date', '2026-04-01')->exists())->toBeTrue()
        ->and(resolveNextMeasurementMonth($operation))->toBe('05/2026');
});
