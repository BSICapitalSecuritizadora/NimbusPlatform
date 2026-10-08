<?php

use App\Enums\MeasurementPlanVersionStatus;
use App\Enums\OperationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Pages\CreateMeasurement;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;

/*
 * Ordem das instruções das escritas do plano de medição.
 *
 * O SQLite ignora `FOR UPDATE`: estes testes provam só a ORDEM -- a Operation
 * lida como primeira instrução da transação, depois o plano, depois as versões
 * do plano em ordem de id, antes de qualquer gravação --, não que as leituras
 * travam. Um mutante que lê as mesmas linhas sem `lockForUpdate()` passa aqui,
 * porque a instrução sai igual. A prova do lock é
 * MeasurementPlanVersionMysqlConcurrencyTest (grupo `mysql`), que roda no
 * MySQL do job parity do CI (`scripts/parity-check.sh`).
 *
 * Nenhuma leitura comum pode vir antes da Operation: no MySQL em REPEATABLE
 * READ a fotografia da transação nasce na primeira leitura comum, e só nascendo
 * depois do lock ela enxerga o que a escrita anterior da mesma operação
 * commitou. No SQLite a leitura comum e a travada saem iguais; por isso a prova
 * aqui é a posição -- a leitura da Operation é a primeira instrução e não se
 * repete logo depois, como sairia uma leitura comum seguida do lock.
 *
 * Não é do grupo `parity`: as instruções esperadas estão no dialeto do SQLite.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // As medições destes testes guardam o arquivo no disco privado falso.
    config()->set('filesystems.private_disk', 'local');
});

/**
 * Toda instrução da transação aberta por `$action`, na ordem em que sai, com os
 * parâmetros -- são eles que dizem qual operação, qual plano e quais versões
 * foram lidos. O que roda no nível de transação de quem chama (a do
 * RefreshDatabase) fica de fora.
 *
 * @return list<array{0: string, 1: array<int, mixed>}>
 */
function planVersionLockOrderStatements(callable $action): array
{
    $callerLevel = DB::transactionLevel();
    $statements = [];

    DB::listen(function (QueryExecuted $query) use ($callerLevel, &$statements): void {
        if (DB::transactionLevel() > $callerLevel) {
            $statements[] = [$query->sql, $query->bindings];
        }
    });

    $action();

    return $statements;
}

/**
 * @return array{0: string, 1: array<int, mixed>}
 */
function planVersionLockOrderOperationRead(int $operationId): array
{
    return ['select * from "operations" where "operations"."id" = ? limit 1', [$operationId]];
}

/**
 * As três primeiras instruções de toda escrita do serviço sobre um plano que já
 * existe: a Operation, o plano dela e as versões do plano em ordem de id.
 *
 * @return list<array{0: string, 1: array<int, mixed>}>
 */
function planVersionLockOrderCanonicalReads(MeasurementPlanSet $planSet): array
{
    $operationId = (int) $planSet->operation_id;
    $planSetId = (int) $planSet->getKey();

    return [
        planVersionLockOrderOperationRead($operationId),
        ['select * from "measurement_plan_sets" where "operation_id" = ? and "measurement_plan_sets"."id" = ? limit 1', [$operationId, $planSetId]],
        ['select * from "measurement_plan_versions" where "plan_set_id" = ? order by "id" asc', [$planSetId]],
    ];
}

/**
 * O lock das operações que planejam a obra, numa instrução só e em ordem de id,
 * seguido da conferência de que o conjunto não mudou.
 *
 * @param  list<int>  $operationIds
 * @return list<array{0: string, 1: array<int, mixed>}>
 */
function planVersionLockOrderConstructionReads(Construction $construction, array $operationIds): array
{
    return [
        [sprintf('select "id" from "operations" where "operations"."id" in (%s) order by "id" asc', implode(', ', $operationIds)), []],
        ['select distinct "operation_id" from "measurement_plan_sets" where "construction_id" = ? order by "operation_id" asc', [(int) $construction->getKey()]],
    ];
}

/**
 * A primeira gravação da transação ('insert into "x"', 'update "x"' ou
 * 'delete from "x"'), ou null se nada foi gravado.
 *
 * @param  list<array{0: string, 1: array<int, mixed>}>  $statements
 */
function planVersionLockOrderFirstWrite(array $statements): ?string
{
    foreach ($statements as [$sql]) {
        if (preg_match('/^(insert into|update|delete from) "[a-z_]+"/', $sql, $write) === 1) {
            return $write[0];
        }
    }

    return null;
}

/**
 * Verbo e tabela de cada instrução até a primeira gravação, inclusive.
 *
 * @param  list<array{0: string, 1: array<int, mixed>}>  $statements
 * @return list<string>
 */
function planVersionLockOrderUntilFirstWrite(array $statements): array
{
    $labels = [];

    foreach ($statements as [$sql]) {
        if (preg_match('/^(insert into|update|delete from) "([a-z_]+)"/', $sql, $write) === 1) {
            $labels[] = explode(' ', $write[1])[0].' '.$write[2];

            return $labels;
        }

        $labels[] = preg_match('/ from "([a-z_]+)"/', $sql, $read) === 1 ? 'select '.$read[1] : $sql;
    }

    return $labels;
}

/**
 * Operação em andamento, em 15/05/2026, com o administrador em todos os papéis
 * e duas obras da mesma emissão.
 *
 * O ator é autorizado uma vez antes de qualquer medição: a autorização carrega
 * os papéis e as permissões dele -- leituras que não travam nada e que, quando
 * acontecem dentro da escrita, vêm depois do lock da Operation. Carregadas
 * antes, como a página faz ao mostrar as ações, a lista fica só com as
 * instruções do plano.
 *
 * @return array{actor: User, emission: Emission, construction: Construction, otherConstruction: Construction, operation: Operation}
 */
function planVersionLockOrderScenario(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $actor = makeAdminUser();
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Aurora']);
    $otherConstruction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Boreal']);
    $operation = Operation::factory()->forEmission($emission)->create(array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id));

    expect(Gate::forUser($actor)->allows('update', $operation))->toBeTrue();

    return compact('actor', 'emission', 'construction', 'otherConstruction', 'operation');
}

/**
 * Plano da obra criado pelo serviço, com o nome do empreendimento -- como o
 * formulário da operação o grava -- e a V1 em rascunho: Fundo de Obra de
 * R$ 1.000.000,00 e 10% ao mês de 05/2026 a 07/2026.
 *
 * @param  array{actor: User, emission: Emission, construction: Construction, otherConstruction: Construction, operation: Operation}  $scenario
 */
function planVersionLockOrderCreatePlan(array $scenario, ?Operation $operation = null, ?Construction $construction = null): MeasurementPlanSet
{
    $construction ??= $scenario['construction'];
    $schedule = [];

    foreach (['2026-05', '2026-06', '2026-07'] as $index => $month) {
        $schedule[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => '10.00',
            'planned_cumulative_percent' => sprintf('%d.00', 10 * ($index + 1)),
            'measurement_date' => $month,
        ];
    }

    return app(MeasurementPlanVersionService::class)->createPlan($operation ?? $scenario['operation'], $scenario['actor'], [
        'name' => $construction->development_name,
        'construction_id' => $construction->id,
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '1000000.00'], $schedule);
}

/**
 * Plano com a V1 vigente desde 05/2026, ativada pelo serviço.
 *
 * @return array{actor: User, emission: Emission, construction: Construction, otherConstruction: Construction, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function planVersionLockOrderActivePlan(): array
{
    $scenario = planVersionLockOrderScenario();
    $planSet = planVersionLockOrderCreatePlan($scenario);
    $draft = $planSet->draftVersion()->sole();
    $v1 = app(MeasurementPlanVersionService::class)->activate($draft, $scenario['actor'], (int) $draft->revision);

    return [...$scenario, 'planSet' => $planSet, 'v1' => $v1];
}

/**
 * Revisão aberta sobre a V1 vigente: o rascunho da V2.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}  $scenario
 */
function planVersionLockOrderRevision(array $scenario): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->createRevision($scenario['planSet'], $scenario['actor'], [
        'revision_category' => 'schedule',
        'revision_reason' => 'Fundação atrasou um mês.',
    ], $scenario['v1']->id);
}

it('locks the operation before checking and inserting a new plan with its draft V1', function () {
    $scenario = planVersionLockOrderScenario();
    $planSet = null;

    $statements = planVersionLockOrderStatements(function () use ($scenario, &$planSet): void {
        $planSet = planVersionLockOrderCreatePlan($scenario);
    });

    // O plano ainda não existe, então não há plano nem versão a travar: depois
    // da Operation vem a conferência de "um plano por obra na operação", que,
    // lida sob o lock, enxerga o plano que outra gravação acabou de criar.
    expect(array_slice($statements, 0, 2))->toBe([
        planVersionLockOrderOperationRead($scenario['operation']->id),
        ['select exists(select * from "measurement_plan_sets" where "operation_id" = ? and "construction_id" = ?) as "exists"', [$scenario['operation']->id, $scenario['construction']->id]],
    ])
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('insert into "measurement_plan_sets"')
        ->and($planSet->versions()->get()->map(fn (MeasurementPlanVersion $version): array => [$version->version_number, $version->status, $version->construction_fund_amount])->all())
        ->toBe([[1, MeasurementPlanVersionStatus::Draft, '1000000.00']]);
});

it('locks the operation and then the plan before the context guard and the plan update', function () {
    $scenario = planVersionLockOrderScenario();
    $planSet = planVersionLockOrderCreatePlan($scenario);

    $statements = planVersionLockOrderStatements(fn () => app(MeasurementPlanVersionService::class)->updatePlan($planSet, $scenario['actor'], [
        'name' => 'Torre Boreal',
        'construction_id' => $scenario['otherConstruction']->id,
        'initial_incurred_amount' => '25000.00',
    ]));

    // Os dados do plano não são da versão, e nenhuma versão é travada: a guarda
    // do contexto (o plano já valeu? já recebeu arquivo?) lê as versões e os
    // arquivos depois da Operation e do plano, antes de gravar.
    expect(array_slice($statements, 0, 2))->toBe(array_slice(planVersionLockOrderCanonicalReads($planSet), 0, 2))
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('update "measurement_plan_sets"')
        ->and($planSet->fresh()->only(['name', 'construction_id', 'initial_incurred_amount']))->toBe([
            'name' => 'Torre Boreal',
            'construction_id' => $scenario['otherConstruction']->id,
            'initial_incurred_amount' => '25000.00',
        ]);
});

it('locks the operation, the plan and its versions before opening a revision', function () {
    $scenario = planVersionLockOrderActivePlan();
    $revision = null;

    $statements = planVersionLockOrderStatements(function () use ($scenario, &$revision): void {
        $revision = planVersionLockOrderRevision($scenario);
    });

    expect(array_slice($statements, 0, 3))->toBe(planVersionLockOrderCanonicalReads($scenario['planSet']))
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('insert into "measurement_plan_versions"')
        ->and([$revision->version_number, $revision->status, $revision->previous_version_id])
        ->toBe([2, MeasurementPlanVersionStatus::Draft, $scenario['v1']->id]);
});

it('locks the operation, the plan, its versions and then the draft lines before saving the draft schedule', function () {
    $scenario = planVersionLockOrderActivePlan();
    $draft = planVersionLockOrderRevision($scenario);
    $schedule = $draft->lines()->orderBy('sequence_number')->get()
        ->map(fn (MeasurementPlanLine $line): array => [
            'id' => $line->id,
            'sequence_number' => $line->sequence_number,
            'planned_monthly_percent' => $line->planned_monthly_percent,
            'planned_cumulative_percent' => $line->planned_cumulative_percent,
            'measurement_date' => $line->measurement_date->format('Y-m'),
        ])
        ->push(['sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2026-08'])
        ->all();

    $statements = planVersionLockOrderStatements(fn () => app(MeasurementPlanVersionService::class)->updateDraft(
        $draft,
        $scenario['actor'],
        ['construction_fund_amount' => '1150000.00'],
        $schedule,
        (int) $draft->revision,
    ));

    // As linhas do rascunho vêm por último na ordem canônica: travadas depois
    // das versões e antes da primeira gravação do cronograma.
    expect(array_slice($statements, 0, 4))->toBe([
        ...planVersionLockOrderCanonicalReads($scenario['planSet']),
        ['select * from "measurement_plan_lines" where "measurement_plan_lines"."plan_version_id" = ? and "measurement_plan_lines"."plan_version_id" is not null order by "id" asc', [$draft->id]],
    ])
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('insert into "measurement_plan_lines"')
        ->and($draft->fresh()->only(['construction_fund_amount', 'revision']))->toBe(['construction_fund_amount' => '1150000.00', 'revision' => 1])
        ->and($draft->lines()->orderBy('sequence_number')->pluck('sequence_number')->all())->toBe([1, 2, 3, 4]);
});

it('locks the operation, the plan and its versions before generating draft lines', function () {
    $scenario = planVersionLockOrderActivePlan();
    $draft = planVersionLockOrderRevision($scenario);

    $statements = planVersionLockOrderStatements(fn () => app(MeasurementPlanVersionService::class)->addDraftLines($draft, $scenario['actor'], 2, '2026-08', (int) $draft->revision));

    expect(array_slice($statements, 0, 3))->toBe(planVersionLockOrderCanonicalReads($scenario['planSet']))
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('insert into "measurement_plan_lines"')
        ->and($draft->lines()->orderBy('sequence_number')->get()->map(fn (MeasurementPlanLine $line): array => [$line->sequence_number, $line->measurement_date->toDateString()])->all())
        ->toBe([[1, '2026-05-01'], [2, '2026-06-01'], [3, '2026-07-01'], [4, '2026-08-01'], [5, '2026-09-01']]);
});

it('locks the operation, the plan and its versions before superseding the active version and activating the draft', function () {
    $scenario = planVersionLockOrderActivePlan();
    $draft = planVersionLockOrderRevision($scenario);

    $statements = planVersionLockOrderStatements(fn () => app(MeasurementPlanVersionService::class)->activate($draft, $scenario['actor'], (int) $draft->revision));

    // As duas versões já estão travadas, em ordem de id, quando o avanço físico
    // e as medições de pé são lidos; a primeira gravação é a substituição da
    // vigente.
    expect(array_slice($statements, 0, 3))->toBe(planVersionLockOrderCanonicalReads($scenario['planSet']))
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('update "measurement_plan_versions"')
        ->and([$scenario['v1']->fresh()->status, $draft->fresh()->status])
        ->toBe([MeasurementPlanVersionStatus::Superseded, MeasurementPlanVersionStatus::Active]);
});

it('locks the operation, the plan and its versions before cancelling a draft', function () {
    $scenario = planVersionLockOrderActivePlan();
    $draft = planVersionLockOrderRevision($scenario);

    $statements = planVersionLockOrderStatements(fn () => app(MeasurementPlanVersionService::class)->cancel($draft, $scenario['actor'], 'Revisão aberta por engano.', (int) $draft->revision));

    expect(array_slice($statements, 0, 3))->toBe(planVersionLockOrderCanonicalReads($scenario['planSet']))
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('update "measurement_plan_versions"')
        ->and([$draft->fresh()->status, $scenario['v1']->fresh()->status])
        ->toBe([MeasurementPlanVersionStatus::Cancelled, MeasurementPlanVersionStatus::Active]);
});

it('decides the refusal of a completed operation on the locked operation row, reading nothing of the plan', function () {
    $scenario = planVersionLockOrderActivePlan();
    app(OperationLifecycleService::class)->complete($scenario['operation'], $scenario['actor']);
    $refusal = null;

    $statements = planVersionLockOrderStatements(function () use ($scenario, &$refusal): void {
        try {
            planVersionLockOrderRevision($scenario);
        } catch (MeasurementWorkflowException $exception) {
            $refusal = $exception;
        }
    });

    // A situação da operação é conferida na linha travada: uma conclusão
    // commitada enquanto a revisão esperava o lock já aparece aqui.
    expect($statements)->toBe([planVersionLockOrderOperationRead($scenario['operation']->id)])
        ->and($refusal?->getMessage())->toBe(sprintf(MeasurementPlanVersionService::TERMINAL_OPERATION_MESSAGE, mb_strtolower(OperationStatus::Completed->label())))
        ->and($scenario['planSet']->versions()->orderBy('id')->get()->map(fn (MeasurementPlanVersion $version): array => [$version->version_number, $version->status])->all())
        ->toBe([[1, MeasurementPlanVersionStatus::Active]]);
});

it('locks the operation first and then the plan and its versions before changing the V1 draft fund from the operation form', function () {
    $scenario = planVersionLockOrderScenario();
    $planSet = planVersionLockOrderCreatePlan($scenario);
    $draft = $planSet->draftVersion()->sole();

    $statements = planVersionLockOrderStatements(fn () => app(MeasurementPlanVersionService::class)->syncDevelopmentPlans($scenario['operation'], $scenario['actor'], [[
        'construction_id' => $scenario['construction']->id,
        'construction_fund_amount' => '1300000.00',
        'construction_fund_original' => '1000000.00',
        'construction_fund_revision' => (int) $draft->revision,
    ]]));

    // Entre a Operation e o plano só há leituras comuns do que o formulário
    // enviou -- a emissão, as obras e se a operação já tem plano padrão --,
    // que não travam nada e já leem depois do lock. O plano da obra é achado
    // por leitura comum (um FOR UPDATE que não acha nada travaria um intervalo
    // do índice compartilhado com outras operações) e travado pela chave; as
    // versões dele em seguida, antes da primeira gravação.
    expect(planVersionLockOrderUntilFirstWrite($statements))->toBe([
        'select operations',
        'select emissions',
        'select constructions',
        'select measurement_plan_sets',
        'select constructions',
        'select measurement_plan_sets',
        'select measurement_plan_sets',
        'select measurement_plan_versions',
        'update measurement_plan_versions',
    ])
        ->and($statements[0])->toBe(planVersionLockOrderOperationRead($scenario['operation']->id))
        ->and(array_slice($statements, 5, 3))->toBe([
            ['select "id" from "measurement_plan_sets" where "measurement_plan_sets"."operation_id" = ? and "measurement_plan_sets"."operation_id" is not null and "construction_id" = ? order by "id" asc limit 1', [$scenario['operation']->id, $scenario['construction']->id]],
            planVersionLockOrderCanonicalReads($planSet)[1],
            planVersionLockOrderCanonicalReads($planSet)[2],
        ])
        ->and($draft->fresh()->only(['construction_fund_amount', 'revision']))->toBe(['construction_fund_amount' => '1300000.00', 'revision' => 1]);
});

it('locks every operation that plans the construction, in id order, before changing what Engineering freezes', function (string $field) {
    $scenario = planVersionLockOrderScenario();
    $second = Operation::factory()->forEmission($scenario['emission'])->create(array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $scenario['actor']->id));
    planVersionLockOrderCreatePlan($scenario);
    planVersionLockOrderCreatePlan($scenario, $second);
    $value = $field === 'emission_id' ? Emission::factory()->create()->id : '11222333000181';
    $construction = $scenario['construction']->fresh();

    $statements = planVersionLockOrderStatements(function () use ($construction, $field, $value): void {
        $construction->{$field} = $value;
        $construction->save();
    });

    // As operações são lidas antes da transação; o lock delas é a primeira
    // instrução dela, e a conferência de que o conjunto não mudou e as guardas
    // da obra já leem depois dele.
    expect(array_slice($statements, 0, 2))->toBe(planVersionLockOrderConstructionReads($construction, [$scenario['operation']->id, $second->id]))
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('update "constructions"')
        ->and($construction->fresh()->{$field})->toBe($value);
})->with([
    'CNPJ do empreendimento' => ['development_cnpj'],
    'emissão' => ['emission_id'],
]);

it('locks the operation of a draft-only plan before deleting its construction', function () {
    $scenario = planVersionLockOrderScenario();
    $planSet = planVersionLockOrderCreatePlan($scenario, construction: $scenario['otherConstruction']);
    $construction = $scenario['otherConstruction']->fresh();

    $statements = planVersionLockOrderStatements(fn () => $construction->delete());

    // Plano só com o rascunho da V1 ainda é planejamento e solta a obra pela
    // FK; a guarda que confere isso lê depois do lock da Operation.
    expect(array_slice($statements, 0, 2))->toBe(planVersionLockOrderConstructionReads($construction, [$scenario['operation']->id]))
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('delete from "constructions"')
        ->and(Construction::query()->whereKey($construction->id)->exists())->toBeFalse()
        ->and($planSet->fresh()->construction_id)->toBeNull()
        ->and($planSet->versions()->pluck('status')->all())->toBe([MeasurementPlanVersionStatus::Draft]);
});

it('locks the chosen operation as the first statement of the submission, before validating the planned measurement', function () {
    $scenario = MeasurementPhysicalProgressScenario::plan();
    $this->actingAs($scenario['actor']);
    $page = Livewire::test(CreateMeasurement::class)->fillForm(['operation_id' => $scenario['operation']->id]);
    $key = array_key_first($page->get('data.assets'));
    $page->fillForm([
        'reference_month' => '2026-05-01',
        'assets' => [$key => [
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-05']->id,
            'storage_path' => [UploadedFile::fake()->createWithContent('medicao.pdf', '%PDF-1.7 medição de maio')],
        ]],
    ]);

    $statements = planVersionLockOrderStatements(fn () => $page->call('create')->assertHasNoFormErrors());
    $lock = planVersionLockOrderOperationRead($scenario['operation']->id);

    // A validação confere a medição prevista contra as opções do formulário --
    // as linhas livres da versão vigente -- e lê o plano, a versão e as linhas
    // depois do lock: uma ativação ou outra aba que terminou antes já aparece.
    expect($statements[0])->toBe($lock)
        ->and($statements[1])->not->toBe($lock)
        ->and(planVersionLockOrderFirstWrite($statements))->toBe('insert into "measurements"')
        ->and($scenario['operation']->measurements()->sole()->assets()->sole()->plan_version_id)
        ->toBe($scenario['planSet']->activeVersion()->value('id'));
});
