<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Enums\OperationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\OperationLifecycleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementPlanVersionFixture;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // As medições destes testes guardam o arquivo no disco privado falso.
    config()->set('filesystems.private_disk', 'local');
});

/**
 * Operação em andamento de uma emissão com duas obras e o mesmo administrador
 * em todos os papéis do fluxo: quem planeja também envia e aprova a medição,
 * e a autorização só é assunto onde o teste troca o ator.
 *
 * @param  array<string, mixed>  $operationAttributes
 * @return array{actor: User, operation: Operation, construction: Construction, otherConstruction: Construction}
 */
function planVersionLifecycleScenario(array $operationAttributes = []): array
{
    $actor = makeAdminUser();
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Aurora']);
    $otherConstruction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Boreal']);
    $operation = Operation::factory()->forEmission($emission)->create([
        ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
        ...$operationAttributes,
    ]);

    return compact('actor', 'operation', 'construction', 'otherConstruction');
}

/**
 * Cronograma de 10% ao mês nas competências dadas, como o formulário o envia.
 *
 * @param  list<string>  $months
 * @return list<array{sequence_number: int, planned_monthly_percent: string, planned_cumulative_percent: string, measurement_date: string}>
 */
function planVersionLifecycleSchedule(array $months): array
{
    $schedule = [];

    foreach (array_values($months) as $index => $month) {
        $schedule[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => '10.00',
            'planned_cumulative_percent' => sprintf('%d.00', 10 * ($index + 1)),
            'measurement_date' => $month,
        ];
    }

    return $schedule;
}

/**
 * Plano da Torre Aurora criado pelo serviço: a V1 nasce em rascunho com o
 * Fundo de Obra e o cronograma informados.
 *
 * @param  array{actor: User, operation: Operation, construction: Construction, otherConstruction: Construction}  $scenario
 * @param  list<string>  $months
 */
function planVersionLifecycleCreatePlan(array $scenario, array $months = ['2026-05', '2026-06', '2026-07'], string $fund = '1000000.00'): MeasurementPlanSet
{
    return app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['actor'], [
        'name' => 'Plano Torre Aurora',
        'construction_id' => $scenario['construction']->id,
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => $fund], planVersionLifecycleSchedule($months));
}

function planVersionLifecycleVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Ativa pelo serviço o rascunho como a pessoa o viu: o contador atual.
 */
function planVersionLifecycleActivate(MeasurementPlanVersion $draft, User $actor): MeasurementPlanVersion
{
    $seen = $draft->fresh();

    return app(MeasurementPlanVersionService::class)->activate($seen, $actor, (int) $seen->revision);
}

/**
 * Plano com a V1 vigente desde 05/2026, ativada pelo serviço em 15/05/2026.
 *
 * @param  list<string>  $months
 * @return array{actor: User, operation: Operation, construction: Construction, otherConstruction: Construction, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function planVersionLifecycleActivePlan(array $months = ['2026-05', '2026-06', '2026-07']): array
{
    test()->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $scenario = planVersionLifecycleScenario();
    $planSet = planVersionLifecycleCreatePlan($scenario, $months);
    $v1 = planVersionLifecycleActivate(planVersionLifecycleVersion($planSet, 1), $scenario['actor']);

    return [...$scenario, 'planSet' => $planSet->fresh(), 'v1' => $v1];
}

/**
 * Uma versão em cada situação: V1 substituída (valeu em 05/2026), V2
 * cancelada, V3 vigente desde 06/2026 e V4 em rascunho.
 *
 * @return array{actor: User, operation: Operation, construction: Construction, otherConstruction: Construction, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, superseded: MeasurementPlanVersion, cancelled: MeasurementPlanVersion, active: MeasurementPlanVersion, draft: MeasurementPlanVersion}
 */
function planVersionLifecycleHistory(): array
{
    $scenario = planVersionLifecycleActivePlan(['2026-05', '2026-06', '2026-07', '2026-08']);
    $service = app(MeasurementPlanVersionService::class);
    $actor = $scenario['actor'];

    $v2 = $service->createRevision($scenario['planSet'], $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste que não seguiu adiante']);
    $service->cancel($v2, $actor, 'Revisão aberta por engano', (int) $v2->revision);

    $v3 = $service->createRevision($scenario['planSet'], $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Atraso na fundação']);
    test()->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));
    planVersionLifecycleActivate($v3, $actor);

    $v4 = $service->createRevision($scenario['planSet'], $actor, ['revision_category' => 'scope', 'revision_reason' => 'Inclusão do bloco B']);

    return [
        ...$scenario,
        'superseded' => $scenario['v1']->fresh(),
        'cancelled' => $v2->fresh(),
        'active' => $v3->fresh(),
        'draft' => $v4->fresh(),
    ];
}

/**
 * O cronograma do rascunho como o formulário o devolve: uma linha por medição
 * prevista, com o id que a identifica.
 *
 * @return list<array<string, mixed>>
 */
function planVersionLifecycleDraftRows(MeasurementPlanVersion $draft): array
{
    return $draft->lines()
        ->orderBy('sequence_number')
        ->get()
        ->map(fn (MeasurementPlanLine $line): array => [
            'id' => $line->id,
            'sequence_number' => $line->sequence_number,
            'planned_monthly_percent' => $line->planned_monthly_percent,
            'planned_cumulative_percent' => $line->planned_cumulative_percent,
            'measurement_date' => $line->measurement_date->format('Y-m'),
        ])
        ->all();
}

/**
 * O previsto de uma linha e a linhagem que a identifica entre as versões.
 *
 * @return array{0: string, 1: int, 2: string, 3: string, 4: string}
 */
function planVersionLifecyclePlannedLine(MeasurementPlanLine $line): array
{
    return [
        $line->lineage_key,
        $line->sequence_number,
        $line->planned_monthly_percent,
        $line->planned_cumulative_percent,
        $line->measurement_date->toDateString(),
    ];
}

/**
 * A versão e as linhas dela exatamente como estão no banco.
 *
 * @return array{version: array<string, mixed>, lines: list<array<string, mixed>>}
 */
function planVersionLifecycleVersionRows(MeasurementPlanVersion $version): array
{
    return [
        'version' => (array) DB::table('measurement_plan_versions')->where('id', $version->id)->first(),
        'lines' => DB::table('measurement_plan_lines')
            ->where('plan_version_id', $version->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all(),
    ];
}

/**
 * As tabelas que uma escrita do plano toca, linha a linha, e o tamanho da
 * trilha: depois de uma recusa nada pode ter mudado, nem pela metade.
 *
 * @return array<string, mixed>
 */
function planVersionLifecycleDatabaseState(): array
{
    $rows = fn (string $table): array => DB::table($table)
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return [
        'operations' => $rows('operations'),
        'constructions' => $rows('constructions'),
        'measurement_plan_sets' => $rows('measurement_plan_sets'),
        'measurement_plan_versions' => $rows('measurement_plan_versions'),
        'measurement_plan_lines' => $rows('measurement_plan_lines'),
        'measurement_assets' => $rows('measurement_assets'),
        'activity_log' => DB::table('activity_log')->count(),
    ];
}

/**
 * A recusa que a escrita lança, capturada para o teste conferir o banco antes
 * da mensagem.
 */
function planVersionLifecycleRefusal(Closure $write): Throwable
{
    try {
        $write();
    } catch (Throwable $refusal) {
        return $refusal;
    }

    test()->fail('A escrita deveria ter sido recusada.');
}

/**
 * O evento de ciclo de vida na trilha protegida `measurements`.
 */
function planVersionLifecycleEvent(string $event, Model $subject): Activity
{
    return Activity::query()
        ->where('log_name', 'measurements')
        ->where('event', $event)
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->sole();
}

/**
 * Ordena as chaves em todos os níveis, mantendo a ordem das listas: a coluna
 * JSON do MySQL devolve os objetos com as chaves reordenadas.
 */
function planVersionLifecycleSortedKeys(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $sorted = array_map(fn (mixed $item): mixed => planVersionLifecycleSortedKeys($item), $value);

    if (! array_is_list($sorted)) {
        ksort($sorted);
    }

    return $sorted;
}

/**
 * Compara, em valor e tipo, as propriedades informadas do evento.
 *
 * @param  array<string, mixed>  $expected
 */
function planVersionLifecycleExpectProperties(Activity $activity, array $expected): void
{
    $actual = Arr::only($activity->properties->all(), array_keys($expected));

    expect(planVersionLifecycleSortedKeys($actual))->toBe(planVersionLifecycleSortedKeys($expected));
}

/**
 * Quem não pode alterar os planos da operação: o participante que só lê a
 * operação (`participant`) ou o editor de operações que não participa desta
 * (`editor`). Nenhum dos dois é autenticado: o serviço confere o ator que
 * recebe, não a sessão.
 */
function planVersionLifecycleOutsider(Operation $operation, string $profile): User
{
    $outsider = User::factory()->withTwoFactor()->create();

    if ($profile === 'participant') {
        $outsider->givePermissionTo('operations.view');
        $operation->update(['assigned_user_id' => $outsider->id]);

        return $outsider;
    }

    $outsider->assignRole('editor');

    return $outsider;
}

// ── Criação do plano ─────────────────────────────────────────────────────────

it('creates the plan with the V1 draft holding the fund and the schedule, authored and audited as the actor', function () {
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();

    $planSet = app(MeasurementPlanVersionService::class)->createPlan($operation, $actor, [
        'name' => 'Plano Torre Aurora',
        'construction_id' => $construction->id,
        'is_default' => true,
        'initial_incurred_amount' => '250000.00',
    ], ['construction_fund_amount' => '1234567.89'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '12.50', 'planned_cumulative_percent' => '12.50', 'measurement_date' => '2026-05'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '7.25', 'planned_cumulative_percent' => '19.75', 'measurement_date' => '2026-06-20'],
        ['sequence_number' => 3, 'planned_monthly_percent' => '0', 'planned_cumulative_percent' => '19.75', 'measurement_date' => '2026-07'],
    ]);

    $planSet->refresh();
    $version = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->sole();
    $lines = $version->lines()->orderBy('sequence_number')->get();

    expect($planSet->operation_id)->toBe($operation->id)
        ->and($planSet->name)->toBe('Plano Torre Aurora')
        ->and($planSet->construction_id)->toBe($construction->id)
        ->and($planSet->is_default)->toBeTrue()
        ->and($planSet->initial_incurred_amount)->toBe('250000.00')
        ->and($planSet->currentConstructionFundAmount())->toBe('1234567.89')
        ->and($planSet->activeVersion)->toBeNull()
        ->and($version->version_number)->toBe(1)
        ->and($version->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($version->revision)->toBe(0)
        ->and($version->operation_id)->toBe($operation->id)
        ->and($version->created_by)->toBe($actor->id)
        ->and($version->construction_fund_amount)->toBe('1234567.89')
        ->and($version->previous_version_id)->toBeNull()
        ->and($version->effective_from)->toBeNull()
        ->and($version->activated_at)->toBeNull()
        // O mês vale pela competência: 'Y-m' e 'Y-m-d' viram o primeiro dia.
        ->and($lines->map(fn (MeasurementPlanLine $line): array => [
            $line->sequence_number,
            $line->planned_monthly_percent,
            $line->planned_cumulative_percent,
            $line->measurement_date->toDateString(),
        ])->all())->toBe([
            [1, '12.50', '12.50', '2026-05-01'],
            [2, '7.25', '19.75', '2026-06-01'],
            [3, '0.00', '19.75', '2026-07-01'],
        ])
        ->and($lines->pluck('plan_set_id')->unique()->values()->all())->toBe([$planSet->id])
        ->and($lines->pluck('operation_id')->unique()->values()->all())->toBe([$operation->id])
        // Cada medição prevista nasce com a própria linhagem: é ela que a
        // revisão copia para dizer que é a mesma competência.
        ->and($lines->every(fn (MeasurementPlanLine $line): bool => strlen($line->lineage_key) === 26 && Str::isUlid($line->lineage_key)))->toBeTrue()
        ->and($lines->pluck('lineage_key')->unique())->toHaveCount(3);

    $created = planVersionLifecycleEvent('plan_version_created', $version);

    expect($created->causer_id)->toBe($actor->id)
        ->and($created->causer_type)->toBe($actor->getMorphClass());

    planVersionLifecycleExpectProperties($created, [
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'construction_id' => $construction->id,
        'plan_version_id' => $version->id,
        'version_number' => 1,
        'status' => 'draft',
        'previous_version_id' => null,
        'construction_fund_amount' => '1234567.89',
        'previous_construction_fund_amount' => null,
        'construction_fund_variation_amount' => null,
        'line_count' => 3,
        'revision' => 0,
        'actor_user_id' => $actor->id,
    ]);
});

it('gives the plan made by the factory its V1 draft authored by the signed-in user, with the fund kept off the plan', function () {
    $this->actingAs($author = makeAdminUser());

    $planSet = MeasurementPlanSet::factory()->withConstructionFund('500000.00')->create();
    $version = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->sole();

    expect($version->version_number)->toBe(1)
        ->and($version->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($version->revision)->toBe(0)
        ->and($version->operation_id)->toBe($planSet->operation_id)
        ->and($version->created_by)->toBe($author->id)
        ->and($version->construction_fund_amount)->toBe('500000.00')
        ->and($version->lines()->count())->toBe(0)
        ->and($planSet->fresh()->draftVersion?->id)->toBe($version->id)
        ->and($planSet->fresh()->activeVersion)->toBeNull()
        ->and($planSet->fresh()->currentConstructionFundAmount())->toBe('500000.00')
        // O fundo saiu do plano: ler pelo nome antigo falha alto, em vez de
        // devolver nulo e desligar a referência financeira em silêncio.
        ->and(fn () => $planSet->fresh()->construction_fund_amount)->toThrow(LogicException::class);
});

it('refuses to create a plan in a finished operation without writing anything', function (OperationStatus $status, string $statusLabel) {
    // Operação encerrada não planeja até ser reaberta; o histórico continua legível.
    $scenario = planVersionLifecycleScenario(['status' => $status]);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => planVersionLifecycleCreatePlan($scenario));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersionService::TERMINAL_OPERATION_MESSAGE, $statusLabel));
})->with([
    'concluída' => [OperationStatus::Completed, 'concluída'],
    'cancelada' => [OperationStatus::Canceled, 'cancelada'],
]);

it('refuses to create a plan for an actor who cannot update the operation', function (string $profile) {
    $outsider = User::factory()->withTwoFactor()->create();
    $scenario = planVersionLifecycleScenario($profile === 'participant' ? ['assigned_user_id' => $outsider->id] : []);

    if ($profile === 'editor') {
        $outsider->assignRole('editor');
    }

    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => planVersionLifecycleCreatePlan([...$scenario, 'actor' => $outsider]));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($refusal->getMessage())->toBe('Você não pode alterar os planos de medição desta operação.');
})->with([
    // Pode editar operações, mas não participa desta.
    'editor de fora da operação' => ['editor'],
    // Participa da operação, mas não pode editá-la.
    'participante sem permissão de edição' => ['participant'],
]);

it('refuses an invalid schedule keyed by line and field, without leaving the plan half created', function (array $lines, array $errors) {
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();
    $before = planVersionLifecycleDatabaseState();

    // O plano e a V1 já foram gravados quando o cronograma é conferido: só a
    // transação única impede que fiquem sem ele.
    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->createPlan($operation, $actor, [
        'name' => 'Plano Torre Aurora',
        'construction_id' => $construction->id,
    ], ['construction_fund_amount' => '1000000.00'], $lines));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe($errors);
})->with([
    'sequência repetida' => [
        [
            ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-05'],
            ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2026-06'],
        ],
        ['lines.1.sequence_number' => ['A medição prevista 1 aparece mais de uma vez no cronograma.']],
    ],
    'previsto mensal acima de 100%' => [
        [
            ['sequence_number' => 1, 'planned_monthly_percent' => '100.01', 'planned_cumulative_percent' => '100.00', 'measurement_date' => '2026-05'],
        ],
        ['lines.0.planned_monthly_percent' => ['Informe o previsto mensal entre 0% e 100%, com no máximo duas casas decimais.']],
    ],
    'mês inválido' => [
        [
            ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-05'],
            ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2026-13'],
        ],
        ['lines.1.measurement_date' => ['Informe um mês válido (mm/aaaa).']],
    ],
]);

it('refuses a second plan for a construction already planned in the operation', function () {
    $scenario = planVersionLifecycleScenario();
    planVersionLifecycleCreatePlan($scenario);
    $before = planVersionLifecycleDatabaseState();

    // Um plano por obra: replanejar é revisar o plano existente, nunca abrir
    // um segundo avanço inicial e um segundo teto de 100%.
    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['actor'], [
        'name' => 'Plano Torre Aurora replanejado',
        'construction_id' => $scenario['construction']->id,
    ], ['construction_fund_amount' => '2000000.00']));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['construction_id' => [MeasurementPlanSet::CONSTRUCTION_ALREADY_PLANNED_REFUSAL]]);
});

it('keeps one plan per construction in the operation even for a write that skips the model', function () {
    $scenario = planVersionLifecycleScenario();
    $planSet = planVersionLifecycleCreatePlan($scenario);

    // Nome diferente: quem recusa é a unique (operação, obra), não a do nome.
    expect(fn () => DB::table('measurement_plan_sets')->insert([
        'operation_id' => $scenario['operation']->id,
        'construction_id' => $scenario['construction']->id,
        'name' => 'Plano paralelo',
        'is_default' => false,
        'initial_physical_progress_percent' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(MeasurementPlanSet::query()
        ->where('operation_id', $scenario['operation']->id)
        ->where('construction_id', $scenario['construction']->id)
        ->pluck('id')
        ->all())->toBe([$planSet->id]);
});

// ── Revisão ──────────────────────────────────────────────────────────────────

it('opens the revision as the next draft, copied from the active version with the same lineage and without execution', function () {
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $v1Lines = $v1->lines()->orderBy('sequence_number')->get();

    // Maio medido pelo fluxo real: a Engenharia grava o realizado e a medição
    // na linha da V1.
    $may = MeasurementPhysicalProgressScenario::measured([
        'actor' => $actor,
        'operation' => $operation,
        'planSet' => $planSet,
        'lines' => ['2026-05' => $v1Lines[0]],
    ], '2026-05', 10);

    // Junho com o realizado gravado direto: execução continua gravável numa
    // versão vigente.
    $v1Lines[1]->forceFill(['realized_monthly_percent' => '4.00', 'realized_cumulative_percent' => '14.00'])->save();

    $v1Before = planVersionLifecycleVersionRows($v1);

    $v2 = app(MeasurementPlanVersionService::class)->createRevision($planSet, $actor, [
        'revision_category' => 'schedule',
        'revision_reason' => '  Atraso na fundação  ',
    ], $v1->id);

    $v2Lines = $v2->lines()->orderBy('sequence_number')->get();

    expect($v1Lines[0]->fresh()->measurement_id)->toBe($may->id)
        ->and($v1Lines[0]->fresh()->realized_cumulative_percent)->toBe('10.00')
        ->and($v1Lines[1]->fresh()->realized_cumulative_percent)->toBe('14.00')
        // A V1 continua vigente e intocada: a revisão só lê dela.
        ->and(planVersionLifecycleVersionRows($v1))->toBe($v1Before)
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->version_number)->toBe(2)
        ->and($v2->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($v2->previous_version_id)->toBe($v1->id)
        ->and($v2->operation_id)->toBe($operation->id)
        ->and($v2->construction_fund_amount)->toBe('1000000.00')
        ->and($v2->revision_category)->toBe(MeasurementPlanRevisionCategory::Schedule)
        ->and($v2->revision_reason)->toBe('Atraso na fundação')
        ->and($v2->revision)->toBe(0)
        ->and($v2->created_by)->toBe($actor->id)
        ->and($v2->effective_from)->toBeNull()
        ->and($v2->activated_at)->toBeNull()
        // Mesma medição prevista (linhagem), mesmo previsto, em linhas novas.
        ->and($v2Lines->map(fn (MeasurementPlanLine $line): array => planVersionLifecyclePlannedLine($line))->all())
        ->toBe($v1Lines->map(fn (MeasurementPlanLine $line): array => planVersionLifecyclePlannedLine($line))->all())
        ->and($v2Lines->pluck('id')->intersect($v1Lines->pluck('id'))->all())->toBe([])
        ->and($v2Lines->pluck('plan_version_id')->unique()->values()->all())->toBe([$v2->id])
        // Execução não é planejamento: nada do realizado nem da medição vem junto.
        ->and($v2Lines->pluck('realized_monthly_percent')->all())->toBe(['0.00', '0.00', '0.00'])
        ->and($v2Lines->pluck('realized_cumulative_percent')->all())->toBe(['0.00', '0.00', '0.00'])
        ->and($v2Lines->pluck('measurement_id')->all())->toBe([null, null, null]);

    $created = planVersionLifecycleEvent('plan_version_created', $v2);

    expect($created->causer_id)->toBe($actor->id);

    planVersionLifecycleExpectProperties($created, [
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'construction_id' => $construction->id,
        'plan_version_id' => $v2->id,
        'version_number' => 2,
        'status' => 'draft',
        'previous_version_id' => $v1->id,
        'previous_version_number' => 1,
        'revision_category' => 'schedule',
        'revision_reason' => 'Atraso na fundação',
        'construction_fund_amount' => '1000000.00',
        'previous_construction_fund_amount' => '1000000.00',
        'construction_fund_variation_amount' => '0.00',
        'construction_fund_variation_percent' => '0.00',
        'line_count' => 3,
        'actor_user_id' => $actor->id,
    ]);
});

it('refuses a second revision while the plan already has a draft', function () {
    // Um rascunho por plano: duas revisões paralelas disputariam a mesma vigência.
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);
    $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Atraso na fundação'], $v1->id);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $service->createRevision($planSet, $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste'], $v1->id));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('O plano já tem a V2 em rascunho: conclua, ative ou cancele esse rascunho antes de abrir outra revisão.');
});

it('refuses to revise a plan that never took effect, pointing to the activation of the V1', function () {
    $scenario = planVersionLifecycleScenario();
    $planSet = planVersionLifecycleCreatePlan($scenario);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->createRevision($planSet, $scenario['actor'], [
        'revision_category' => 'schedule',
        'revision_reason' => 'Revisar antes de valer',
    ]));

    // A V1 em rascunho não se cancela; o caminho é ativá-la.
    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('O plano ainda não tem versão vigente: ative a V1 antes de revisá-lo.');
});

it('refuses a revision opened on a version that another person has since replaced', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);

    // Enquanto o formulário da revisão aberto sobre a V1 está na tela, outra
    // pessoa revisa e ativa a V2: o motivo digitado não se refere mais à vigente.
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Atraso na fundação'], $v1->id);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));
    planVersionLifecycleActivate($v2, $actor);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $service->createRevision($planSet, $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste sobre a V1'], $v1->id));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersionService::STALE_BASE_MESSAGE, 'V2'))
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);
});

// ── Edição do rascunho ───────────────────────────────────────────────────────

it('saves the fund, category, reason and schedule of the draft, counting each save', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Atraso na fundação'], $v1->id);
    [$may, $june, $july] = $v2->lines()->orderBy('sequence_number')->get()->all();
    $v1Before = planVersionLifecycleVersionRows($v1);
    $v1Lineages = $v1->lines()->pluck('lineage_key')->all();

    $saved = $service->updateDraft($v2, $actor, [
        'construction_fund_amount' => '1150000.00',
        'revision_category' => 'cost',
        'revision_reason' => '  Reajuste do orçamento da obra  ',
    ], [
        // Maio fica igual, junho muda de previsto, julho sai e agosto entra.
        ['id' => $may->id, 'sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-05'],
        ['id' => $june->id, 'sequence_number' => 2, 'planned_monthly_percent' => '15.00', 'planned_cumulative_percent' => '25.00', 'measurement_date' => '2026-06'],
        ['sequence_number' => 3, 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '30.00', 'measurement_date' => '2026-08'],
    ], 0);

    $lines = $saved->lines()->orderBy('sequence_number')->get();
    $august = $lines->firstWhere('sequence_number', 3);

    expect($saved->revision)->toBe(1)
        ->and($saved->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($saved->construction_fund_amount)->toBe('1150000.00')
        ->and($saved->revision_category)->toBe(MeasurementPlanRevisionCategory::Cost)
        ->and($saved->revision_reason)->toBe('Reajuste do orçamento da obra')
        ->and($lines->map(fn (MeasurementPlanLine $line): array => [$line->id, ...planVersionLifecyclePlannedLine($line)])->all())->toBe([
            [$may->id, $may->lineage_key, 1, '10.00', '10.00', '2026-05-01'],
            [$june->id, $june->lineage_key, 2, '15.00', '25.00', '2026-06-01'],
            [$august->id, $august->lineage_key, 3, '5.00', '30.00', '2026-08-01'],
        ])
        ->and(MeasurementPlanLine::query()->whereKey($july->id)->exists())->toBeFalse()
        // Linha nova é medição prevista nova: linhagem própria, de nenhuma versão.
        ->and($august->id)->not->toBeIn([$may->id, $june->id, $july->id])
        ->and(strlen($august->lineage_key))->toBe(26)
        ->and($august->lineage_key)->not->toBeIn([...$v1Lineages, $july->lineage_key])
        // Editar a V2 não muda a V1.
        ->and(planVersionLifecycleVersionRows($v1))->toBe($v1Before);

    // A segunda gravação parte do contador da primeira; sem cronograma, as
    // linhas ficam como estão.
    $resaved = $service->updateDraft($saved, $actor, ['revision_reason' => 'Reajuste do orçamento e do prazo'], null, 1);

    expect($resaved->revision)->toBe(2)
        ->and($resaved->revision_reason)->toBe('Reajuste do orçamento e do prazo')
        ->and($resaved->construction_fund_amount)->toBe('1150000.00')
        ->and($resaved->revision_category)->toBe(MeasurementPlanRevisionCategory::Cost)
        ->and($resaved->lines()->orderBy('sequence_number')->pluck('id')->all())->toBe($lines->pluck('id')->all());
});

it('swaps two sequence numbers in a single save of the draft', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Inverter a ordem'], $v1->id);
    [$may, $june, $july] = $v2->lines()->orderBy('sequence_number')->get()->all();
    $rows = planVersionLifecycleDraftRows($v2);
    $rows[0]['sequence_number'] = 2;
    $rows[1]['sequence_number'] = 1;

    // A troca colidiria com a unique (versão, sequência) se as linhas fossem
    // gravadas uma a uma sem passar por um número provisório.
    $saved = $service->updateDraft($v2, $actor, [], $rows, 0);

    expect($saved->revision)->toBe(1)
        ->and($saved->lines()->orderBy('id')->get()->map(fn (MeasurementPlanLine $line): array => [$line->id, $line->lineage_key, $line->sequence_number])->all())->toBe([
            [$may->id, $may->lineage_key, 2],
            [$june->id, $june->lineage_key, 1],
            [$july->id, $july->lineage_key, 3],
        ]);
});

it('refuses a draft save based on a revision another person already saved over', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste'], $v1->id);
    $openedRows = planVersionLifecycleDraftRows($v2);

    // Outra pessoa grava o rascunho primeiro.
    $service->updateDraft($v2, $actor, ['construction_fund_amount' => '1100000.00'], null, 0);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $service->updateDraft($v2->fresh(), $actor, ['construction_fund_amount' => '1200000.00'], $openedRows, 0));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersionService::STALE_DRAFT_MESSAGE, 'V2'))
        ->and($v2->fresh()->construction_fund_amount)->toBe('1100000.00')
        ->and($v2->fresh()->revision)->toBe(1);
});

it('refuses a schedule row that does not belong to the draft', function (string $case, string $errorKey) {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Atraso na fundação'], $v1->id);
    $rows = planVersionLifecycleDraftRows($v2);

    if ($case === 'active-version-line') {
        // A linha da V1 vigente nunca é editada pelo rascunho da V2.
        $rows[0]['id'] = (int) $v1->lines()->orderBy('sequence_number')->value('id');
    } else {
        $rows[2]['id'] = $rows[1]['id'];
    }

    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $service->updateDraft($v2, $actor, [], $rows, 0));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe([$errorKey => ['A medição prevista não pertence a este rascunho. Recarregue a página.']]);
})->with([
    'linha da versão vigente' => ['active-version-line', 'lines.0'],
    'a mesma linha duas vezes' => ['repeated-line', 'lines.2'],
]);

it('refuses to edit a version that is no longer a draft', function (string $state, string $label, string $statusLabel) {
    // Quem abriu o rascunho antes da ativação ou do cancelamento não grava por cima do histórico.
    $history = planVersionLifecycleHistory();
    $version = $history[$state];
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->updateDraft(
        $version,
        $history['actor'],
        ['construction_fund_amount' => '1500000.00'],
        null,
        (int) $version->revision,
    ));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersionService::NOT_A_DRAFT_MESSAGE, $label, $statusLabel));
})->with([
    'vigente' => ['active', 'V3', 'vigente'],
    'substituída' => ['superseded', 'V1', 'substituída'],
    'cancelada' => ['cancelled', 'V2', 'cancelada'],
]);

// ── Medição prevista passada que saiu do rascunho ────────────────────────────

/**
 * Plano da Torre Aurora com a V1 vigente desde 01/2027, ativada pelo serviço
 * em 10/01/2027: 5% previstos por mês, de 01/2027 a 12/2027.
 *
 * @return array{actor: User, operation: Operation, construction: Construction, otherConstruction: Construction, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function planVersionLifecycleYearPlan(): array
{
    test()->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00'));

    $scenario = planVersionLifecycleScenario();
    $schedule = [];

    foreach (range(1, 12) as $month) {
        $schedule[] = [
            'sequence_number' => $month,
            'planned_monthly_percent' => '5.00',
            'planned_cumulative_percent' => sprintf('%d.00', 5 * $month),
            'measurement_date' => sprintf('2027-%02d', $month),
        ];
    }

    $planSet = app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['actor'], [
        'name' => 'Plano Torre Aurora',
        'construction_id' => $scenario['construction']->id,
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '1000000.00'], $schedule);
    $v1 = planVersionLifecycleActivate(planVersionLifecycleVersion($planSet, 1), $scenario['actor']);

    return [...$scenario, 'planSet' => $planSet->fresh(), 'v1' => $v1];
}

/**
 * O cronograma do rascunho como o formulário o devolve, por competência ('Y-m').
 *
 * @return array<string, array<string, mixed>>
 */
function planVersionLifecycleRowsByMonth(MeasurementPlanVersion $draft): array
{
    return collect(planVersionLifecycleDraftRows($draft))->keyBy('measurement_date')->all();
}

/**
 * Em 20/06/2027 a revisão do plano do ano tira do rascunho a medição prevista
 * de junho -- que, ativada ainda em junho, seria o mês da própria vigência --
 * e passa os 5% dela para julho. Devolve o rascunho gravado (contador 1).
 *
 * @param  array{actor: User, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}  $scenario
 */
function planVersionLifecycleDropJune(array $scenario): MeasurementPlanVersion
{
    test()->travelTo(CarbonImmutable::parse('2027-06-20 12:00:00'));

    $service = app(MeasurementPlanVersionService::class);
    $draft = $service->createRevision($scenario['planSet'], $scenario['actor'], [
        'revision_category' => 'schedule',
        'revision_reason' => 'O previsto de junho passou para julho.',
    ], $scenario['v1']->id);
    $rows = planVersionLifecycleRowsByMonth($draft);
    unset($rows['2027-06']);
    $rows['2027-07']['planned_monthly_percent'] = '10.00';

    return $service->updateDraft($draft, $scenario['actor'], [], array_values($rows), 0);
}

/**
 * Tenta ativar o rascunho e desfaz tudo em seguida: diz, sem deixar nada
 * gravado, se o serviço o aceitaria agora (`null`) ou com que erros o
 * recusaria.
 *
 * @return array<string, list<string>>|null
 */
function planVersionLifecycleActivationTrial(MeasurementPlanVersion $draft, User $actor): ?array
{
    DB::beginTransaction();

    try {
        planVersionLifecycleActivate($draft, $actor);

        return null;
    } catch (ValidationException $refusal) {
        return $refusal->errors();
    } finally {
        DB::rollBack();
    }
}

it('brings back the lineage of a past planned measurement that left the draft when it is added again with the same sequence and month', function () {
    $scenario = planVersionLifecycleYearPlan();
    ['actor' => $actor, 'v1' => $v1] = $scenario;
    $service = app(MeasurementPlanVersionService::class);
    $v1June = $v1->lines()->where('sequence_number', 6)->sole();
    $v2 = planVersionLifecycleDropJune($scenario);

    // Em junho a remoção vale: ativada ainda no mês, a revisão valeria desde
    // 06/2027, e junho seria planejamento da própria vigência.
    expect($v2->revision)->toBe(1)
        ->and($v2->lines()->where('lineage_key', $v1June->lineage_key)->exists())->toBeFalse()
        ->and($v2->lines()->where('sequence_number', 7)->value('planned_monthly_percent'))->toBe('10.00')
        ->and(planVersionLifecycleActivationTrial($v2, $actor))->toBeNull()
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft);

    // A ativação fica para 01/07/2027: junho virou competência passada, que a
    // revisão não reescreve. A recusa diz como trazer a linha de volta.
    $this->travelTo(CarbonImmutable::parse('2027-07-01 12:00:00'));
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => planVersionLifecycleActivate($v2, $actor));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe([
            'lines' => ['A medição prevista 06 (06/2027) é anterior à vigência 07/2027 e precisa continuar igual à da V1: a revisão não reescreve competências passadas. Se ela saiu do rascunho, inclua-a de novo com a mesma sequência e o mesmo mês.'],
        ]);

    // A pessoa faz o que a recusa pede: inclui de novo a medição prevista 06
    // em 06/2027, como na V1, e devolve a julho o previsto que ele tinha.
    $rows = planVersionLifecycleRowsByMonth($v2);
    $rows['2027-07']['planned_monthly_percent'] = '5.00';
    $rows['2027-06'] = ['sequence_number' => 6, 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '30.00', 'measurement_date' => '2027-06'];
    $v2 = $service->updateDraft($v2, $actor, [], array_values($rows), 1);
    $restored = $v2->lines()->where('sequence_number', 6)->sole();

    // A linha do rascunho é nova, mas a medição prevista é a mesma: volta com
    // a linhagem da junho da V1.
    expect($v2->revision)->toBe(2)
        ->and($restored->id)->not->toBe($v1June->id)
        ->and($restored->plan_version_id)->toBe($v2->id)
        ->and($restored->measurement_date->toDateString())->toBe('2027-06-01')
        ->and($restored->lineage_key)->toBe($v1June->lineage_key);

    $activated = planVersionLifecycleActivate($v2, $actor);
    $comparison = $service->compare($v1->fresh(), $activated);

    // A V2 vale desde 07/2027 com o passado da V1 intacto, linhagem por
    // linhagem: a comparação não vê junho removido nem acrescentado.
    expect($activated->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($activated->effective_from->toDateString())->toBe('2027-07-01')
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v1->fresh()->superseded_by_version_id)->toBe($activated->id)
        ->and($activated->lines()->orderBy('sequence_number')->get()->map(fn (MeasurementPlanLine $line): array => planVersionLifecyclePlannedLine($line))->all())
        ->toBe($v1->lines()->orderBy('sequence_number')->get()->map(fn (MeasurementPlanLine $line): array => planVersionLifecyclePlannedLine($line))->all())
        ->and([$comparison->addedLines, $comparison->removedLines, $comparison->changedLines, $comparison->unchangedLines])->toBe([[], [], [], 12]);
});

it('gives a new lineage to a past planned measurement added again with another sequence or month, and keeps refusing the activation', function (int $sequence, string $month) {
    $scenario = planVersionLifecycleYearPlan();
    ['actor' => $actor, 'v1' => $v1] = $scenario;
    $v2 = planVersionLifecycleDropJune($scenario);
    $this->travelTo(CarbonImmutable::parse('2027-07-01 12:00:00'));

    // Só a mesma sequência no mesmo mês identifica a medição prevista que saiu
    // do rascunho: com outra, a linha é medição prevista nova.
    $rows = planVersionLifecycleRowsByMonth($v2);
    $rows['2027-07']['planned_monthly_percent'] = '5.00';
    $rows[] = ['sequence_number' => $sequence, 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '30.00', 'measurement_date' => $month];
    $v2 = app(MeasurementPlanVersionService::class)->updateDraft($v2, $actor, [], array_values($rows), 1);
    $readded = $v2->lines()->where('sequence_number', $sequence)->sole();
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => planVersionLifecycleActivate($v2, $actor));

    // A junho da V1 continua fora do rascunho, e a recusa continua a mesma.
    expect($readded->measurement_date->format('Y-m'))->toBe($month)
        ->and(Str::isUlid($readded->lineage_key))->toBeTrue()
        ->and($readded->lineage_key)->not->toBeIn($v1->lines()->pluck('lineage_key')->all())
        ->and(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe([
            'lines' => ['A medição prevista 06 (06/2027) é anterior à vigência 07/2027 e precisa continuar igual à da V1: a revisão não reescreve competências passadas. Se ela saiu do rascunho, inclua-a de novo com a mesma sequência e o mesmo mês.'],
        ])
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft);
})->with([
    'mesma sequência em outro mês' => [6, '2027-08'],
    'outra sequência no mesmo mês' => [13, '2027-06'],
]);

// ── Imutabilidade no modelo ──────────────────────────────────────────────────

it('refuses content changes on a version that already took effect', function (string $state, array $change) {
    $history = planVersionLifecycleHistory();
    $version = $history[$state];
    $before = planVersionLifecycleDatabaseState();

    // A medição criada sob a versão continua ligada a ela: o que ela planejou
    // não muda por nenhuma gravação Eloquent.
    $refusal = planVersionLifecycleRefusal(fn () => $version->fresh()->update($change));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersion::IMMUTABLE_HISTORY_REFUSAL, $version->label()));
})->with([
    'vigente' => ['active'],
    'substituída' => ['superseded'],
])->with([
    'Fundo de Obra' => [['construction_fund_amount' => '1500000.00']],
    'categoria' => [['revision_category' => 'other']],
    'justificativa' => [['revision_reason' => 'Justificativa reescrita depois']],
    'vigência' => [['effective_from' => '2026-09-01']],
]);

it('keeps the lifecycle record of a version that already left the draft', function (string $state, string $column) {
    $history = planVersionLifecycleHistory();
    $version = $history[$state]->fresh();
    $rewrites = [
        'activated_by' => makeAdminUser()->id,
        'superseded_at' => CarbonImmutable::parse('2026-01-02 08:00:00'),
        'cancelled_by' => makeAdminUser()->id,
        'cancellation_reason' => 'Motivo reescrito depois do cancelamento',
    ];
    $before = planVersionLifecycleDatabaseState();

    // Quem ativou, quando foi substituída, quem cancelou e por quê são o
    // registro do ciclo de vida: a trilha de atributos nem os acompanha, e
    // reescrevê-los depois apagaria o histórico sem deixar rastro.
    $refusal = planVersionLifecycleRefusal(fn () => $version->forceFill([$column => $rewrites[$column]])->save());

    // A cancelada nunca valeu: a recusa diz que ela foi cancelada.
    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(
            $state === 'cancelled' ? MeasurementPlanVersion::CANCELLED_VERSION_REFUSAL : MeasurementPlanVersion::IMMUTABLE_HISTORY_REFUSAL,
            $version->label(),
        ));
})->with([
    'vigente: quem a ativou' => ['active', 'activated_by'],
    'substituída: quem a ativou' => ['superseded', 'activated_by'],
    'substituída: quando foi substituída' => ['superseded', 'superseded_at'],
    'cancelada: quem a cancelou' => ['cancelled', 'cancelled_by'],
    'cancelada: o motivo' => ['cancelled', 'cancellation_reason'],
]);

it('refuses a status change outside the version lifecycle', function (string $state, MeasurementPlanVersionStatus $target, string $message) {
    // Nada volta a rascunho e nada sai de substituída ou de cancelada.
    $history = planVersionLifecycleHistory();
    $version = $history[$state]->fresh();
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $version->forceFill(['status' => $target])->save());

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe($message);
})->with([
    'vigente de volta a rascunho' => ['active', MeasurementPlanVersionStatus::Draft, 'A versão V3 não pode passar de "Vigente" para "Rascunho".'],
    'vigente cancelada' => ['active', MeasurementPlanVersionStatus::Cancelled, 'A versão V3 não pode passar de "Vigente" para "Cancelada".'],
    'substituída de volta a vigente' => ['superseded', MeasurementPlanVersionStatus::Active, 'A versão V1 não pode passar de "Substituída" para "Vigente".'],
    'substituída de volta a rascunho' => ['superseded', MeasurementPlanVersionStatus::Draft, 'A versão V1 não pode passar de "Substituída" para "Rascunho".'],
    'cancelada de volta a rascunho' => ['cancelled', MeasurementPlanVersionStatus::Draft, 'A versão V2 não pode passar de "Cancelada" para "Rascunho".'],
    'cancelada ativada' => ['cancelled', MeasurementPlanVersionStatus::Active, 'A versão V2 não pode passar de "Cancelada" para "Vigente".'],
    'rascunho direto a substituída' => ['draft', MeasurementPlanVersionStatus::Superseded, 'A versão V4 não pode passar de "Rascunho" para "Substituída".'],
    // A ativação é a do serviço: sem vigência e sem o registro dela, não há vigente.
    'rascunho vigente sem vigência' => ['draft', MeasurementPlanVersionStatus::Active, 'A versão só fica vigente com a data de vigência e o registro da ativação.'],
]);

it('keeps the identity of a version, even of a draft', function (string $column) {
    // As medições e a trilha se referem à versão pelo plano e pelo número.
    $history = planVersionLifecycleHistory();
    $draft = $history['draft']->fresh();
    $value = match ($column) {
        'version_number' => 9,
        'plan_set_id' => MeasurementPlanSet::factory()->create(['operation_id' => $history['operation']->id])->id,
        'operation_id' => Operation::factory()->create()->id,
        'created_by' => makeAdminUser()->id,
        'previous_version_id' => $history['superseded']->id,
    };
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $draft->forceFill([$column => $value])->save());

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('A identidade da versão do plano não pode ser alterada.');
})->with(['version_number', 'plan_set_id', 'operation_id', 'created_by', 'previous_version_id']);

it('refuses to delete a version in any state', function (string $state, string $label) {
    $history = planVersionLifecycleHistory();
    $before = planVersionLifecycleDatabaseState();

    // O número é histórico (V2 cancelada, V3 vigente): apagar a última versão
    // abriria o número para outra.
    $refusal = planVersionLifecycleRefusal(fn () => $history[$state]->fresh()->delete());

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf('A versão %s do plano não pode ser excluída: o rascunho se cancela, e o plano sem medição se exclui inteiro.', $label));
})->with([
    'rascunho' => ['draft', 'V4'],
    'vigente' => ['active', 'V3'],
    'substituída' => ['superseded', 'V1'],
    'cancelada' => ['cancelled', 'V2'],
]);

it('refuses a new scheduled measurement inside a version that already took effect', function (string $state) {
    // Linha nova só nasce em rascunho: a vigente e a substituída são o retrato do que valeu.
    $history = planVersionLifecycleHistory();
    $version = $history[$state];
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $version->lines()->create([
        'sequence_number' => 9,
        'planned_monthly_percent' => '5.00',
        'planned_cumulative_percent' => '35.00',
        'measurement_date' => '2026-09-01',
    ]));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersion::IMMUTABLE_HISTORY_REFUSAL, $version->label()));
})->with([
    'vigente' => ['active'],
    'substituída' => ['superseded'],
]);

it('refuses a new scheduled measurement for a plan without a draft', function () {
    // Sem rascunho não há onde a linha nova entrar: a mudança começa por uma revisão.
    ['planSet' => $planSet] = planVersionLifecycleActivePlan();
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'sequence_number' => 4,
        'measurement_date' => '2026-08-01',
    ]));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('O cronograma só recebe medição prevista nova num rascunho do plano: crie uma revisão do plano para alterá-lo.');
});

it('refuses changes to the planned columns of a line of the active version', function (string $column, int|string $value) {
    // O previsto da vigente é a régua das medições criadas sob ela.
    $history = planVersionLifecycleHistory();
    $line = $history['active']->lines()->orderBy('sequence_number')->firstOrFail();
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $line->update([$column => $value]));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersion::IMMUTABLE_HISTORY_REFUSAL, 'V3'));
})->with([
    'sequência' => ['sequence_number', 7],
    'previsto mensal' => ['planned_monthly_percent', '12.00'],
    'previsto acumulado' => ['planned_cumulative_percent', '12.00'],
    'realizado inicial' => ['initial_realized_cumulative_percent', '3.00'],
    'competência' => ['measurement_date', '2026-12-01'],
]);

it('refuses to delete a scheduled measurement of a version that already took effect', function (string $state) {
    // Apagar a linha tiraria do histórico a competência que a versão planejou.
    $history = planVersionLifecycleHistory();
    $line = $history[$state]->lines()->orderBy('sequence_number')->firstOrFail();
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $line->delete());

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('A linha 01 pertence a uma versão do plano que já valeu e não pode ser removida: crie uma revisão do plano.');
})->with([
    'vigente' => ['active'],
    'substituída' => ['superseded'],
]);

it('keeps the execution columns of a line of the active version writable', function () {
    $history = planVersionLifecycleHistory();
    $line = $history['active']->lines()->orderBy('sequence_number')->firstOrFail();

    // O realizado é o que a Engenharia grava na aprovação: execução, não
    // planejamento, e por isso vale em qualquer versão.
    $line->forceFill(['realized_monthly_percent' => '7.50', 'realized_cumulative_percent' => '7.50'])->save();
    $line->refresh();

    expect($line->realized_monthly_percent)->toBe('7.50')
        ->and($line->realized_cumulative_percent)->toBe('7.50')
        ->and($line->planned_cumulative_percent)->toBe('10.00')
        ->and($line->evolution_diff_percent)->toBe('-2.50')
        ->and($line->evolution_trend)->toBe(MeasurementPlanLine::TREND_BEHIND)
        ->and($line->plan_version_id)->toBe($history['active']->id);
});

// ── Cancelamento do rascunho ─────────────────────────────────────────────────

it('cancels a revision draft keeping its number and content, and the next revision starts again from the active version', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste do orçamento'], $v1->id);
    $rows = planVersionLifecycleDraftRows($v2);
    $rows[] = ['sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2026-08'];

    // O rascunho foi mexido antes de ser abandonado: nada disso pode vazar
    // para a próxima revisão.
    $v2 = $service->updateDraft($v2, $actor, ['construction_fund_amount' => '1500000.00'], $rows, 0);
    $abandonedLineage = $v2->lines()->where('sequence_number', 4)->value('lineage_key');
    $v1Before = planVersionLifecycleVersionRows($v1);
    $this->travelTo(CarbonImmutable::parse('2026-05-20 15:30:00'));

    $cancelled = $service->cancel($v2, $actor, '  Orçamento recusado pela diretoria  ', 1);

    expect($cancelled->status)->toBe(MeasurementPlanVersionStatus::Cancelled)
        ->and($cancelled->version_number)->toBe(2)
        ->and($cancelled->cancellation_reason)->toBe('Orçamento recusado pela diretoria')
        ->and($cancelled->cancelled_by)->toBe($actor->id)
        ->and($cancelled->cancelled_at?->toDateTimeString())->toBe('2026-05-20 15:30:00')
        ->and($cancelled->construction_fund_amount)->toBe('1500000.00')
        ->and($cancelled->lines()->count())->toBe(4)
        ->and($cancelled->effective_from)->toBeNull()
        ->and($cancelled->activated_at)->toBeNull()
        ->and(planVersionLifecycleVersionRows($v1))->toBe($v1Before);

    $event = planVersionLifecycleEvent('plan_version_cancelled', $cancelled);

    expect($event->causer_id)->toBe($actor->id);

    planVersionLifecycleExpectProperties($event, [
        'plan_version_id' => $v2->id,
        'version_number' => 2,
        'status' => 'cancelled',
        'previous_version_id' => $v1->id,
        'cancellation_reason' => 'Orçamento recusado pela diretoria',
        'construction_fund_amount' => '1500000.00',
        'previous_construction_fund_amount' => '1000000.00',
        'construction_fund_variation_amount' => '500000.00',
        'construction_fund_variation_percent' => '50.00',
        'line_count' => 4,
        'revision' => 1,
        'actor_user_id' => $actor->id,
    ]);

    // O número da cancelada não volta: a próxima é a V3, de novo a partir da
    // V1 que continua vigente.
    $v3 = $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Novo cronograma'], $v1->id);

    expect($v3->version_number)->toBe(3)
        ->and($v3->previous_version_id)->toBe($v1->id)
        ->and($v3->construction_fund_amount)->toBe('1000000.00')
        ->and($v3->lines()->orderBy('sequence_number')->get()->map(fn (MeasurementPlanLine $line): array => planVersionLifecyclePlannedLine($line))->all())
        ->toBe($v1->lines()->orderBy('sequence_number')->get()->map(fn (MeasurementPlanLine $line): array => planVersionLifecyclePlannedLine($line))->all())
        ->and($v3->lines()->where('lineage_key', $abandonedLineage)->exists())->toBeFalse()
        ->and(MeasurementPlanVersion::query()
            ->where('plan_set_id', $planSet->id)
            ->orderBy('version_number')
            ->get()
            ->map(fn (MeasurementPlanVersion $version): array => [$version->version_number, $version->status])
            ->all())->toBe([
                [1, MeasurementPlanVersionStatus::Active],
                [2, MeasurementPlanVersionStatus::Cancelled],
                [3, MeasurementPlanVersionStatus::Draft],
            ]);
});

it('refuses to cancel the V1 draft, without which the plan would have no version', function () {
    // Plano cadastrado por engano se exclui; a V1 sozinha não se cancela.
    $scenario = planVersionLifecycleScenario();
    $planSet = planVersionLifecycleCreatePlan($scenario);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->cancel(
        planVersionLifecycleVersion($planSet, 1),
        $scenario['actor'],
        'Plano criado por engano',
        0,
    ));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe('O rascunho da V1 não é cancelado: sem ele o plano ficaria sem versão. Para desistir do plano, exclua-o.');
});

it('requires a reason to cancel a draft', function (string $reason) {
    // O cancelamento fica na trilha; sem o motivo, ninguém o reconstitui depois.
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $v2 = app(MeasurementPlanVersionService::class)->createRevision($planSet, $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste'], $v1->id);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->cancel($v2, $actor, $reason, 0));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['cancellation_reason' => ['Informe o motivo do cancelamento do rascunho.']]);
})->with([
    'vazio' => [''],
    'só espaços' => ['   '],
]);

it('lets a draft be cancelled in a completed operation while refusing to replan it there', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste'], $v1->id);
    app(OperationLifecycleService::class)->complete($operation, $actor);
    $terminal = sprintf(MeasurementPlanVersionService::TERMINAL_OPERATION_MESSAGE, 'concluída');
    $before = planVersionLifecycleDatabaseState();

    $revision = planVersionLifecycleRefusal(fn () => $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Outra revisão'], $v1->id));
    $edit = planVersionLifecycleRefusal(fn () => $service->updateDraft($v2, $actor, ['construction_fund_amount' => '1100000.00'], null, 0));
    $activation = planVersionLifecycleRefusal(fn () => $service->activate($v2, $actor, 0));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and([$revision::class, $revision->getMessage()])->toBe([MeasurementWorkflowException::class, $terminal])
        ->and([$edit::class, $edit->getMessage()])->toBe([MeasurementWorkflowException::class, $terminal])
        ->and([$activation::class, $activation->getMessage()])->toBe([MeasurementWorkflowException::class, $terminal]);

    // Cancelar o rascunho é arrumação, não plano novo: continua possível.
    $cancelled = $service->cancel($v2, $actor, 'Obra encerrada antes da revisão', 0);

    expect($cancelled->status)->toBe(MeasurementPlanVersionStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Obra encerrada antes da revisão')
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and(planVersionLifecycleEvent('plan_version_cancelled', $cancelled)->causer_id)->toBe($actor->id);
});

it('refuses to cancel a revision draft for an actor who cannot update the operation, leaving no cancellation in the trail', function (string $profile) {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $v2 = app(MeasurementPlanVersionService::class)->createRevision($planSet, $actor, ['revision_category' => 'cost', 'revision_reason' => 'Reajuste do orçamento'], $v1->id);
    $outsider = planVersionLifecycleOutsider($operation, $profile);
    $before = planVersionLifecycleDatabaseState();

    // A tela esconde o cancelamento de quem não pode alterar a operação; o
    // serviço, a única porta de escrita do plano, recusa de novo sob o lock.
    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->cancel($v2, $outsider, 'Revisão aberta por engano', 0));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($refusal->getMessage())->toBe('Você não pode alterar os planos de medição desta operação.')
        ->and($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and(Activity::query()->where('event', 'plan_version_cancelled')->exists())->toBeFalse();
})->with([
    // Participa da operação, mas só pode vê-la.
    'participante só leitor' => ['participant'],
    // Pode editar operações, mas não participa desta.
    'editor de fora da operação' => ['editor'],
]);

// ── Dados do plano ───────────────────────────────────────────────────────────

it('updates the name and the default flag of a plan that already took effect', function () {
    // Nome e plano padrão não mudam nada do que as medições conferem.
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $v1Before = planVersionLifecycleVersionRows($v1);

    app(MeasurementPlanVersionService::class)->updatePlan($planSet, $actor, [
        'name' => 'Torre Aurora, fase 1',
        'is_default' => false,
    ]);

    expect($planSet->fresh()->name)->toBe('Torre Aurora, fase 1')
        ->and($planSet->fresh()->is_default)->toBeFalse()
        ->and(planVersionLifecycleVersionRows($v1))->toBe($v1Before);
});

it('moves the plan to another construction and corrects the initial incurred amount while it never took effect', function () {
    // Antes da primeira ativação nenhuma medição nem versão depende da obra e do incorrido.
    $scenario = planVersionLifecycleScenario();
    $this->actingAs($scenario['actor']);
    $planSet = planVersionLifecycleCreatePlan($scenario);

    app(MeasurementPlanVersionService::class)->updatePlan($planSet, $scenario['actor'], [
        'construction_id' => $scenario['otherConstruction']->id,
        'initial_incurred_amount' => '320000.50',
    ]);

    expect($planSet->fresh()->construction_id)->toBe($scenario['otherConstruction']->id)
        ->and($planSet->fresh()->initial_incurred_amount)->toBe('320000.50');
});

it('locks the construction and the initial incurred amount once the plan took effect', function (string $field) {
    // Depois de valer, as versões e as medições dependem da obra e do incorrido inicial.
    $scenario = planVersionLifecycleActivePlan();
    $this->actingAs($scenario['actor']);
    $value = $field === 'construction_id' ? $scenario['otherConstruction']->id : '320000.50';
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->updatePlan($scenario['planSet'], $scenario['actor'], [$field => $value]));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(MeasurementPlanSet::PLAN_CONTEXT_LOCKED_REFUSAL);
})->with([
    'obra' => ['construction_id'],
    'incorrido inicial' => ['initial_incurred_amount'],
]);

it('refuses to change the data of a plan for an actor who cannot update the operation', function (string $profile) {
    // Plano que nunca valeu: nome, padrão, obra e incorrido ainda mudariam
    // todos -- só a permissão conferida pelo serviço segura a gravação.
    $scenario = planVersionLifecycleScenario();
    $planSet = planVersionLifecycleCreatePlan($scenario);
    $outsider = planVersionLifecycleOutsider($scenario['operation'], $profile);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->updatePlan($planSet, $outsider, [
        'name' => 'Plano Torre Boreal',
        'is_default' => false,
        'construction_id' => $scenario['otherConstruction']->id,
        'initial_incurred_amount' => '320000.50',
    ]));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($refusal->getMessage())->toBe('Você não pode alterar os planos de medição desta operação.')
        ->and($planSet->fresh()->only(['name', 'is_default', 'construction_id', 'initial_incurred_amount']))->toBe([
            'name' => 'Plano Torre Aurora',
            'is_default' => true,
            'construction_id' => $scenario['construction']->id,
            'initial_incurred_amount' => '0.00',
        ]);
})->with([
    'participante só leitor' => ['participant'],
    'editor de fora da operação' => ['editor'],
]);

// ── Exclusão do plano ────────────────────────────────────────────────────────

it('deletes a plan that never took effect together with its V1 draft and schedule', function () {
    $scenario = planVersionLifecycleScenario();
    $this->actingAs($scenario['actor']);
    $planSet = planVersionLifecycleCreatePlan($scenario);
    $v1 = planVersionLifecycleVersion($planSet, 1);

    expect($planSet->fresh()->delete())->toBeTrue()
        ->and(MeasurementPlanSet::query()->whereKey($planSet->id)->exists())->toBeFalse()
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->exists())->toBeFalse()
        ->and(MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->exists())->toBeFalse();

    // As versões descem pela FK sem passar pelos ganchos delas: a trilha do
    // plano guarda o que foi junto.
    $deletion = planVersionLifecycleEvent('plan_versions_deleted_with_plan', $planSet);

    expect($deletion->causer_id)->toBe($scenario['actor']->id);

    planVersionLifecycleExpectProperties($deletion, [
        'operation_id' => $scenario['operation']->id,
        'plan_set_id' => $planSet->id,
        'construction_id' => $scenario['construction']->id,
        'versions' => [
            ['plan_version_id' => $v1->id, 'version_number' => 1, 'status' => 'draft', 'effective_from' => null, 'construction_fund_amount' => '1000000.00', 'line_count' => 3],
        ],
        'actor_user_id' => $scenario['actor']->id,
    ]);
});

it('deletes an active plan without measurements together with every version', function () {
    // Plano ativado por engano, ainda sem medição, sai inteiro com as versões.
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $this->actingAs($actor);
    $v2 = app(MeasurementPlanVersionService::class)->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Atraso na fundação'], $v1->id);

    expect($planSet->fresh()->delete())->toBeTrue()
        ->and(MeasurementPlanVersion::query()->whereKey([$v1->id, $v2->id])->exists())->toBeFalse()
        ->and(MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->exists())->toBeFalse();

    planVersionLifecycleExpectProperties(planVersionLifecycleEvent('plan_versions_deleted_with_plan', $planSet), [
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'construction_id' => $construction->id,
        'versions' => [
            ['plan_version_id' => $v1->id, 'version_number' => 1, 'status' => 'active', 'effective_from' => '2026-05-01', 'construction_fund_amount' => '1000000.00', 'line_count' => 3],
            ['plan_version_id' => $v2->id, 'version_number' => 2, 'status' => 'draft', 'effective_from' => null, 'construction_fund_amount' => '1000000.00', 'line_count' => 3],
        ],
        'actor_user_id' => $actor->id,
    ]);
});

it('refuses to delete a plan that already received a measurement file', function () {
    ['actor' => $actor, 'operation' => $operation, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $this->actingAs($actor);

    // Medição de maio enviada pelo fluxo real e aguardando a Engenharia.
    $measurement = MeasurementPhysicalProgressScenario::measurement([
        'actor' => $actor,
        'operation' => $operation,
        'planSet' => $planSet,
        'lines' => ['2026-05' => $v1->lines()->orderBy('sequence_number')->firstOrFail()],
    ], '2026-05');
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $planSet->fresh()->delete());

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(MeasurementPlanSet::MEASUREMENT_HISTORY_DELETION_REFUSAL)
        ->and($measurement->assets()->sole()->plan_version_id)->toBe($v1->id);
});

it('deletes through the plan service a plan whose versions took effect but were never measured, logging what went with it', function () {
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $this->actingAs($actor);
    $service = app(MeasurementPlanVersionService::class);

    // V1 valeu em 05/2026 e foi substituída pela V2 em 06/2026; nenhuma
    // medição: o plano cadastrado por engano ainda sai inteiro.
    $v2 = $service->createRevision($planSet, $actor, ['revision_category' => 'schedule', 'revision_reason' => 'Atraso na fundação'], $v1->id);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));
    planVersionLifecycleActivate($v2, $actor);

    expect($service->deletePlan($planSet, $actor))->toBeTrue()
        ->and(MeasurementPlanSet::query()->whereKey($planSet->id)->exists())->toBeFalse()
        ->and(MeasurementPlanVersion::query()->whereKey([$v1->id, $v2->id])->exists())->toBeFalse()
        ->and(MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->exists())->toBeFalse();

    $deletion = planVersionLifecycleEvent('plan_versions_deleted_with_plan', $planSet);

    expect($deletion->causer_id)->toBe($actor->id);

    planVersionLifecycleExpectProperties($deletion, [
        'operation_id' => $operation->id,
        'plan_set_id' => $planSet->id,
        'construction_id' => $construction->id,
        'versions' => [
            ['plan_version_id' => $v1->id, 'version_number' => 1, 'status' => 'superseded', 'effective_from' => '2026-05-01', 'construction_fund_amount' => '1000000.00', 'line_count' => 3],
            ['plan_version_id' => $v2->id, 'version_number' => 2, 'status' => 'active', 'effective_from' => '2026-06-01', 'construction_fund_amount' => '1000000.00', 'line_count' => 3],
        ],
        'actor_user_id' => $actor->id,
    ]);

    // Plano que outra pessoa já excluiu não é recusa: o pedido está cumprido,
    // e a trilha não ganha uma segunda exclusão.
    expect($service->deletePlan($planSet, $actor))->toBeTrue()
        ->and(Activity::query()->where('event', 'plan_versions_deleted_with_plan')->count())->toBe(1);
});

it('names in the trail of a plan deleted through the service the actor the service authorized', function () {
    // Como na criação do plano, o autor é o ator que o serviço recebe e
    // autoriza, não a sessão: sem ninguém autenticado -- uma rotina, um
    // comando --, a exclusão não pode ficar anônima na trilha protegida.
    ['actor' => $actor, 'planSet' => $planSet] = planVersionLifecycleActivePlan();

    expect(app(MeasurementPlanVersionService::class)->deletePlan($planSet, $actor))->toBeTrue();

    $deletion = planVersionLifecycleEvent('plan_versions_deleted_with_plan', $planSet);

    expect($deletion->causer_id)->toBe($actor->id)
        ->and($deletion->properties['actor_user_id'])->toBe($actor->id);
});

it('refuses to delete a plan of a finished operation, keeping the history of its versions', function (OperationStatus $status, string $statusLabel) {
    // V1 substituída, V2 cancelada, V3 vigente e V4 em rascunho, nenhuma
    // medição: na operação encerrada o histórico das versões fica como está.
    $history = planVersionLifecycleHistory();
    $lifecycle = app(OperationLifecycleService::class);

    $status === OperationStatus::Completed
        ? $lifecycle->complete($history['operation'], $history['actor'])
        : $lifecycle->cancel($history['operation'], $history['actor'], 'Operação encerrada pelo comitê.');

    $this->actingAs($history['actor']);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->deletePlan($history['planSet'], $history['actor']));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersionService::TERMINAL_OPERATION_MESSAGE, $statusLabel))
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $history['planSet']->id)->count())->toBe(4)
        ->and(Activity::query()->where('event', 'plan_versions_deleted_with_plan')->exists())->toBeFalse()
        ->and($history['operation']->fresh()->status)->toBe($status);
})->with([
    'concluída' => [OperationStatus::Completed, 'concluída'],
    'cancelada' => [OperationStatus::Canceled, 'cancelada'],
]);

it('refuses to delete a plan for an actor who cannot update the operation', function (string $profile) {
    ['operation' => $operation, 'planSet' => $planSet] = planVersionLifecycleActivePlan();
    $outsider = planVersionLifecycleOutsider($operation, $profile);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => app(MeasurementPlanVersionService::class)->deletePlan($planSet, $outsider));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(AuthorizationException::class)
        ->and($refusal->getMessage())->toBe('Você não pode alterar os planos de medição desta operação.')
        ->and(MeasurementPlanSet::query()->whereKey($planSet->id)->exists())->toBeTrue();
})->with([
    'participante só leitor' => ['participant'],
    'editor de fora da operação' => ['editor'],
]);

// ── Formulário da operação ───────────────────────────────────────────────────

it('creates the plan of a new development with the V1 draft carrying the fund of the operation form', function () {
    // O fundo digitado no formulário da operação é o da V1, não do plano.
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();

    $operation->syncDevelopmentPlans([
        ['construction_id' => $construction->id, 'construction_fund_amount' => '2500000.00'],
    ], $actor);

    $planSet = $operation->planSets()->sole();
    $version = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->sole();

    expect($planSet->construction_id)->toBe($construction->id)
        ->and($planSet->name)->toBe('Torre Aurora')
        ->and($planSet->is_default)->toBeTrue()
        ->and($version->version_number)->toBe(1)
        ->and($version->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($version->construction_fund_amount)->toBe('2500000.00')
        ->and($version->revision)->toBe(0)
        ->and($version->created_by)->toBe($actor->id)
        ->and($operation->fresh()->title)->toBe('Torre Aurora');

    $created = planVersionLifecycleEvent('plan_version_created', $version);

    expect($created->causer_id)->toBe($actor->id)
        ->and($created->properties['construction_fund_amount'])->toBe('2500000.00')
        ->and($created->properties['line_count'])->toBe(0);
});

it('updates the fund of the V1 draft from the operation form when the draft is the one the form showed', function () {
    // Antes de valer, o fundo da V1 ainda é planejamento e muda pelo formulário.
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();
    $operation->syncDevelopmentPlans([['construction_id' => $construction->id, 'construction_fund_amount' => '2500000.00']], $actor);
    $planSet = $operation->planSets()->sole();

    $operation->syncDevelopmentPlans([[
        'construction_id' => $construction->id,
        'construction_fund_amount' => '2750000.00',
        'construction_fund_original' => '2500000.00',
        'construction_fund_revision' => 0,
    ]], $actor);

    $version = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->sole();

    expect($version->version_number)->toBe(1)
        ->and($version->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($version->construction_fund_amount)->toBe('2750000.00')
        ->and($version->revision)->toBe(1);
});

it('treats the fund the form showed as no change, even after the plan took effect', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();
    $operation->syncDevelopmentPlans([['construction_id' => $construction->id, 'construction_fund_amount' => '2500000.00']], $actor);
    $planSet = $operation->planSets()->sole();

    // O formulário da operação foi aberto aqui: fundo de 2.500.000,00 sobre o
    // rascunho no contador 0. Depois o plano ganhou cronograma e foi ativado.
    $v1 = app(MeasurementPlanVersionService::class)->updateDraft(planVersionLifecycleVersion($planSet, 1), $actor, [], planVersionLifecycleSchedule(['2026-05', '2026-06']), 0);
    planVersionLifecycleActivate($v1, $actor);
    $before = planVersionLifecycleDatabaseState();

    $operation->fresh()->syncDevelopmentPlans([[
        'construction_id' => $construction->id,
        'construction_fund_amount' => '2500000.00',
        'construction_fund_original' => '2500000.00',
        'construction_fund_revision' => 0,
    ]], $actor);

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v1->fresh()->construction_fund_amount)->toBe('2500000.00');
});

it('refuses a different fund for a plan that already took effect without creating the other developments of the form', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction, 'otherConstruction' => $otherConstruction] = planVersionLifecycleScenario();
    $operation->syncDevelopmentPlans([['construction_id' => $construction->id, 'construction_fund_amount' => '2500000.00']], $actor);
    $planSet = $operation->planSets()->sole();
    $v1 = app(MeasurementPlanVersionService::class)->updateDraft(planVersionLifecycleVersion($planSet, 1), $actor, [], planVersionLifecycleSchedule(['2026-05', '2026-06']), 0);
    planVersionLifecycleActivate($v1, $actor);
    $before = planVersionLifecycleDatabaseState();

    // O custo de um plano em vigor muda por revisão; a recusa desfaz também a
    // obra nova enviada no mesmo formulário.
    $refusal = planVersionLifecycleRefusal(fn () => $operation->fresh()->syncDevelopmentPlans([
        ['construction_id' => $otherConstruction->id, 'construction_fund_amount' => '900000.00'],
        [
            'construction_id' => $construction->id,
            'construction_fund_amount' => '2600000.00',
            'construction_fund_original' => '2500000.00',
            'construction_fund_revision' => 1,
        ],
    ], $actor));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(MeasurementPlanSet::FUND_BELONGS_TO_VERSION_REFUSAL);
});

it('refuses a fund change based on a V1 draft that changed after the form was opened', function () {
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();
    $operation->syncDevelopmentPlans([['construction_id' => $construction->id, 'construction_fund_amount' => '2500000.00']], $actor);

    // Outra pessoa grava o fundo primeiro, a partir do mesmo formulário.
    $operation->syncDevelopmentPlans([[
        'construction_id' => $construction->id,
        'construction_fund_amount' => '2750000.00',
        'construction_fund_original' => '2500000.00',
        'construction_fund_revision' => 0,
    ]], $actor);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $operation->fresh()->syncDevelopmentPlans([[
        'construction_id' => $construction->id,
        'construction_fund_amount' => '2600000.00',
        'construction_fund_original' => '2500000.00',
        'construction_fund_revision' => 0,
    ]], $actor));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersionService::STALE_DRAFT_MESSAGE, 'V1'));
});

it('refuses to change the fund of the V1 draft from the form of a finished operation', function () {
    // Operação encerrada não replaneja: o rascunho da V1 espera a reabertura
    // também pelo formulário da operação, como na aba de versões.
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();
    $operation->syncDevelopmentPlans([['construction_id' => $construction->id, 'construction_fund_amount' => '2500000.00']], $actor);
    app(OperationLifecycleService::class)->complete($operation, $actor);
    $before = planVersionLifecycleDatabaseState();

    $refusal = planVersionLifecycleRefusal(fn () => $operation->fresh()->syncDevelopmentPlans([[
        'construction_id' => $construction->id,
        'construction_fund_amount' => '2750000.00',
        'construction_fund_original' => '2500000.00',
        'construction_fund_revision' => 0,
    ]], $actor));

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(sprintf(MeasurementPlanVersionService::TERMINAL_OPERATION_MESSAGE, 'concluída'));
});

it('still saves the form of a finished operation that sends the fund unchanged', function () {
    // O formulário reenvia todos os empreendimentos a cada gravação: fundo igual
    // ao mostrado não é replanejamento e não trava a operação encerrada.
    ['actor' => $actor, 'operation' => $operation, 'construction' => $construction] = planVersionLifecycleScenario();
    $operation->syncDevelopmentPlans([['construction_id' => $construction->id, 'construction_fund_amount' => '2500000.00']], $actor);
    app(OperationLifecycleService::class)->complete($operation, $actor);
    $before = planVersionLifecycleDatabaseState();

    $operation->fresh()->syncDevelopmentPlans([[
        'construction_id' => $construction->id,
        'construction_fund_amount' => '2500000.00',
        'construction_fund_original' => '2500000.00',
        'construction_fund_revision' => 0,
    ]], $actor);

    expect(planVersionLifecycleDatabaseState())->toBe($before);
});

// ── Exclusão da obra ─────────────────────────────────────────────────────────

it('refuses to delete a construction whose plan already took effect', function () {
    ['actor' => $actor, 'construction' => $construction] = planVersionLifecycleActivePlan();
    $this->actingAs($actor);
    $before = planVersionLifecycleDatabaseState();

    // A FK soltaria a obra (SET NULL) de um plano cujas versões dependem dela.
    $refusal = planVersionLifecycleRefusal(fn () => $construction->fresh()->delete());

    expect(planVersionLifecycleDatabaseState())->toBe($before)
        ->and($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(Construction::MEASUREMENT_PLAN_DELETION_REFUSAL);
});

it('deletes a construction whose plan only has the V1 draft, releasing the plan', function () {
    // Plano só com o rascunho da V1 ainda é planejamento: a obra sai e solta o plano.
    $scenario = planVersionLifecycleScenario();
    $this->actingAs($scenario['actor']);
    $planSet = planVersionLifecycleCreatePlan($scenario);
    $v1 = planVersionLifecycleVersion($planSet, 1);
    $v1Before = planVersionLifecycleVersionRows($v1);

    expect($scenario['construction']->fresh()->delete())->toBeTrue()
        ->and(Construction::query()->whereKey($scenario['construction']->id)->exists())->toBeFalse()
        ->and($planSet->fresh()->construction_id)->toBeNull()
        ->and(planVersionLifecycleVersionRows($v1))->toBe($v1Before);
});

// ── Fábrica de versões ───────────────────────────────────────────────────────

it('persists through the factory a cancelled revision beside the V1 draft, taking neither slot of the plan', function () {
    // O plano de fábrica já nasce com a V1 em rascunho; a revisão cancelada
    // não ocupa a vaga do rascunho nem a da vigente.
    $version = MeasurementPlanVersion::factory()->create();
    $planSet = MeasurementPlanSet::query()->findOrFail($version->plan_set_id);
    $slots = fn (MeasurementPlanVersion $saved): array => Arr::only(
        (array) DB::table('measurement_plan_versions')->where('id', $saved->id)->sole(),
        ['active_plan_set_id', 'draft_plan_set_id'],
    );

    expect($version->version_number)->toBe(2)
        ->and($version->status)->toBe(MeasurementPlanVersionStatus::Cancelled)
        ->and($version->operation_id)->toBe($planSet->operation_id)
        ->and($version->cancelled_at)->not->toBeNull()
        ->and($version->cancellation_reason)->toBe('Revisão descartada antes de ser ativada.')
        ->and($version->effective_from)->toBeNull()
        ->and($version->activated_at)->toBeNull()
        ->and($slots($version))->toBe(['active_plan_set_id' => null, 'draft_plan_set_id' => null])
        ->and($planSet->draftVersion?->version_number)->toBe(1)
        ->and($planSet->activeVersion)->toBeNull();

    // Outra cancelada no mesmo plano leva o número seguinte, sem tocar a V1.
    $next = MeasurementPlanVersion::factory()->for($planSet, 'planSet')->create();

    expect($next->version_number)->toBe(3)
        ->and($slots($next))->toBe(['active_plan_set_id' => null, 'draft_plan_set_id' => null])
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->orderBy('version_number')->pluck('status', 'version_number')->all())->toBe([
            1 => MeasurementPlanVersionStatus::Draft,
            2 => MeasurementPlanVersionStatus::Cancelled,
            3 => MeasurementPlanVersionStatus::Cancelled,
        ]);
});

it('opens through the factory the draft of a plan whose V1 draft was activated, and only there', function () {
    $planSet = MeasurementPlanSet::factory()->create();
    $v1 = MeasurementPlanVersionFixture::activeVersion($planSet);

    $draft = MeasurementPlanVersion::factory()->draft()->for($planSet, 'planSet')->create();

    expect($draft->version_number)->toBe(2)
        ->and($draft->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($draft->cancelled_at)->toBeNull()
        ->and($draft->cancellation_reason)->toBeNull()
        ->and($planSet->fresh()->draftVersion?->id)->toBe($draft->id)
        ->and($planSet->fresh()->activeVersion?->id)->toBe($v1->id);

    // Um rascunho por plano: o segundo esbarra na unique do banco, como
    // esbarraria o rascunho pedido para um plano que ainda tem a V1.
    expect(fn () => MeasurementPlanVersion::factory()->draft()->for($planSet, 'planSet')->create())
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => MeasurementPlanVersion::factory()->draft()->for(MeasurementPlanSet::factory()->create(), 'planSet')->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('opens through the factory a revision draft of the active V1 that the plan service activates', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionLifecycleActivePlan();
    $service = app(MeasurementPlanVersionService::class);

    // O rascunho de fábrica parte da vigente, como o da revisão do serviço:
    // sem isso a ativação o recusaria como aberto sobre outra versão.
    $draft = MeasurementPlanVersion::factory()->draft()->for($planSet, 'planSet')->create()->fresh();

    expect($draft->version_number)->toBe(2)
        ->and($draft->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($draft->previous_version_id)->toBe($v1->id)
        ->and($draft->operation_id)->toBe($planSet->operation_id)
        ->and($draft->revision)->toBe(0)
        ->and($draft->lines()->count())->toBe(0);

    // Cronograma e justificativa pela porta de escrita do plano; a ativação
    // passa por todas as conferências do serviço.
    $draft = $service->updateDraft($draft, $actor, ['revision_reason' => 'Revisão montada pela fábrica de versões.'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '20.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2026-06'],
    ], 0);
    $activated = planVersionLifecycleActivate($draft, $actor);

    expect($activated->id)->toBe($draft->id)
        ->and($activated->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($activated->effective_from->toDateString())->toBe('2026-05-01')
        ->and($activated->activated_by)->toBe($actor->id)
        ->and($activated->revision_reason)->toBe('Revisão montada pela fábrica de versões.')
        ->and($activated->lines()->pluck('planned_cumulative_percent')->all())->toBe(['20.00'])
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v1->fresh()->superseded_by_version_id)->toBe($activated->id)
        ->and(planVersionLifecycleEvent('plan_version_activated', $activated)->causer_id)->toBe($actor->id);
});

it('numbers in sequence the cancelled revisions the factory creates in one batch for the same plan', function () {
    $planSet = MeasurementPlanSet::factory()->create();

    // O count() monta todas as versões antes de gravar a primeira: cada uma
    // reserva o próprio número, e nenhuma ocupa a vaga do rascunho da V1.
    $versions = MeasurementPlanVersion::factory()->for($planSet, 'planSet')->count(2)->create();

    expect($versions->map(fn (MeasurementPlanVersion $version): array => [$version->version_number, $version->status, $version->plan_set_id])->all())->toBe([
        [2, MeasurementPlanVersionStatus::Cancelled, $planSet->id],
        [3, MeasurementPlanVersionStatus::Cancelled, $planSet->id],
    ])
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->orderBy('version_number')->pluck('status', 'version_number')->all())->toBe([
            1 => MeasurementPlanVersionStatus::Draft,
            2 => MeasurementPlanVersionStatus::Cancelled,
            3 => MeasurementPlanVersionStatus::Cancelled,
        ]);

    // O lote seguinte continua do maior número já gravado no plano.
    expect(MeasurementPlanVersion::factory()->for($planSet, 'planSet')->count(2)->create()->pluck('version_number')->all())->toBe([4, 5]);
});
