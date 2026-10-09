<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPhysicalProgressContribution;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Schemas\MeasurementForm;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionResolver;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationNextMeasurementResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;

/**
 * A competência decide a versão do plano, e o envio a congela.
 *
 * Para cada plano e cada mês vale uma versão só: entre as que valeram
 * (vigente ou substituída), a de maior número cuja vigência começa até aquele
 * mês -- e, antes da vigência da primeira, a V1. Rascunho e revisão cancelada
 * nunca valem. O arquivo da medição congela no envio a versão que rege a
 * competência dela (`measurement_assets.plan_version_id`), que nunca mais é
 * recalculada: junho medido em agosto, com a V2 vigente desde julho, fica na
 * V1 -- na medição prevista de junho da V1, com o Fundo de Obra da V1 no
 * snapshot da Engenharia e na referência do pagamento.
 *
 * Cenário-base: o Residencial Horizonte, com a V1 ativada em 05/01/2027
 * (vigente desde 01/2027; Fundo de Obra de R$ 20.000.000,00; sem avanço
 * inicial; 8% previstos por mês de 01/2027 a 12/2027) e a revisão de custo V2
 * (R$ 23.000.000,00) ativada em 02/07/2027, vigente desde 07/2027.
 */
uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // Os arquivos de medição vão para o disco privado falso.
    config()->set('filesystems.private_disk', 'local');
    // Vigência e competência são do calendário de negócio: o fuso fixo deixa
    // as fronteiras de mês independentes do ambiente.
    config()->set('measurements.business_timezone', 'America/Sao_Paulo');
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a versão ativada vale a
 * partir do mês desse dia no calendário de negócio.
 */
function periodLinkageTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * Operação em andamento em que quem envia a medição responde por todas as
 * etapas; quem planeja as obras é um administrador.
 *
 * @return array{actor: User, planner: User, operation: Operation}
 */
function periodLinkageOperation(): array
{
    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize']);

    return [
        'actor' => $actor,
        'planner' => makeAdminUser(),
        'operation' => Operation::factory()->create([
            'status' => 'active',
            ...array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id),
        ]),
    ];
}

/**
 * Competências ('Y-m') consecutivas a partir da informada.
 *
 * @return list<string>
 */
function periodLinkageMonths(string $first, int $count): array
{
    $start = CarbonImmutable::parse("{$first}-01");

    return array_map(fn (int $offset): string => $start->addMonthsNoOverflow($offset)->format('Y-m'), range(0, $count - 1));
}

/**
 * Plano de uma obra da operação, criado pelo serviço e com a V1 ativada pelo
 * serviço no dia corrente: o Fundo de Obra informado, sem avanço inicial, e o
 * mesmo previsto mensal em cada competência, com o acumulado somado.
 *
 * @param  array{planner: User, operation: Operation}  $scenario
 * @param  list<string>  $months
 */
function periodLinkageActivePlan(array $scenario, string $name, string $fund, array $months, string $monthly = '8.00', bool $isDefault = true): MeasurementPlanSet
{
    $running = 0;
    $lines = [];

    foreach (array_values($months) as $index => $month) {
        $running += (int) MeasurementPhysicalProgress::basisPoints($monthly);
        $lines[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => $monthly,
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal($running),
            'measurement_date' => $month,
        ];
    }

    $service = app(MeasurementPlanVersionService::class);
    $planSet = $service->createPlan($scenario['operation'], $scenario['planner'], [
        'name' => $name,
        'is_default' => $isDefault,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => '0.00',
    ], ['construction_fund_amount' => $fund], $lines);
    $draft = periodLinkageVersion($planSet, 1);
    $service->activate($draft, $scenario['planner'], (int) $draft->revision);

    return $planSet->fresh();
}

function periodLinkageVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * @return array<string, MeasurementPlanLine> linhas da versão por competência ('Y-m')
 */
function periodLinkageLinesOf(MeasurementPlanVersion $version): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
        ->get()
        ->keyBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->format('Y-m'))
        ->all();
}

/**
 * Abre pelo serviço a revisão do plano sobre a versão vigente, em rascunho.
 *
 * @param  array{planner: User}  $scenario
 */
function periodLinkageDraftRevision(array $scenario, MeasurementPlanSet $planSet, MeasurementPlanRevisionCategory $category): MeasurementPlanVersion
{
    $active = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->active()->sole();

    return app(MeasurementPlanVersionService::class)->createRevision($planSet->fresh(), $scenario['planner'], [
        'revision_category' => $category->value,
        'revision_reason' => $category === MeasurementPlanRevisionCategory::Cost
            ? 'Reajuste do orçamento da obra aprovado pelo comitê de crédito.'
            : 'Replanejamento do cronograma aprovado pelo comitê de obras.',
    ], (int) $active->id);
}

/**
 * Revisão do plano pelo serviço, do rascunho à ativação no dia informado: a
 * nova versão vale a partir do mês desse dia. `$schedule` recebe as linhas do
 * rascunho (por competência) e devolve o cronograma inteiro a gravar; sem ele,
 * o cronograma copiado da vigente fica igual.
 *
 * @param  array{planner: User}  $scenario
 * @param  (callable(array<string, MeasurementPlanLine>): list<array<string, mixed>>)|null  $schedule
 */
function periodLinkageRevise(array $scenario, MeasurementPlanSet $planSet, string $date, MeasurementPlanRevisionCategory $category, ?string $fund = null, ?callable $schedule = null): MeasurementPlanVersion
{
    periodLinkageTravelTo($date);
    $service = app(MeasurementPlanVersionService::class);
    $draft = periodLinkageDraftRevision($scenario, $planSet, $category);
    $draft = $service->updateDraft(
        $draft,
        $scenario['planner'],
        $fund === null ? [] : ['construction_fund_amount' => $fund],
        $schedule === null ? null : $schedule(periodLinkageLinesOf($draft)),
        (int) $draft->revision,
    );

    return $service->activate($draft, $scenario['planner'], (int) $draft->revision);
}

/**
 * O Residencial Horizonte só com a V1: criado e ativado em 05/01/2027.
 *
 * @return array{actor: User, planner: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, lines: array<string, MeasurementPlanLine>}
 */
function periodLinkageScenarioUnderV1(): array
{
    periodLinkageTravelTo('2027-01-05');
    $scenario = periodLinkageOperation();
    $planSet = periodLinkageActivePlan($scenario, 'Residencial Horizonte', '20000000.00', periodLinkageMonths('2027-01', 12));
    $v1 = periodLinkageVersion($planSet, 1);

    return $scenario + ['planSet' => $planSet, 'v1' => $v1, 'lines' => periodLinkageLinesOf($v1)];
}

/**
 * A revisão de custo do Residencial Horizonte: R$ 23.000.000,00, ativada em
 * 02/07/2027 e vigente desde 07/2027, com o cronograma copiado da V1.
 *
 * @param  array{planner: User, planSet: MeasurementPlanSet}  $scenario
 */
function periodLinkageReviseCost(array $scenario): MeasurementPlanVersion
{
    return periodLinkageRevise($scenario, $scenario['planSet'], '2027-07-02', MeasurementPlanRevisionCategory::Cost, '23000000.00');
}

/**
 * O cenário-base: V1 vigente desde 01/2027 e V2 vigente desde 07/2027.
 *
 * @return array{actor: User, planner: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, v2: MeasurementPlanVersion, lines: array<string, MeasurementPlanLine>, v2Lines: array<string, MeasurementPlanLine>}
 */
function periodLinkageScenario(): array
{
    $scenario = periodLinkageScenarioUnderV1();
    $v2 = periodLinkageReviseCost($scenario);

    return $scenario + ['v2' => $v2, 'v2Lines' => periodLinkageLinesOf($v2)];
}

/**
 * Para cada competência do cronograma, a medição prevista da versão que hoje
 * a rege ({@see MeasurementPlanVersionResolver::forCompetence()}). A
 * competência que a versão que a rege tirou do cronograma fica de fora.
 *
 * @return array<string, MeasurementPlanLine>
 */
function periodLinkageGoverningLines(MeasurementPlanSet $planSet): array
{
    $resolver = app(MeasurementPlanVersionResolver::class);
    $lines = [];

    foreach (periodLinkageMonths('2027-01', 12) as $month) {
        $governing = $resolver->forCompetence($planSet, $month);
        $line = $governing instanceof MeasurementPlanVersion ? (periodLinkageLinesOf($governing)[$month] ?? null) : null;

        if ($line instanceof MeasurementPlanLine) {
            $lines[$month] = $line;
        }
    }

    return $lines;
}

/**
 * O cenário com as medições previstas que regem cada competência hoje: é por
 * elas que {@see Scenario::measurement()} envia a medição do mês.
 *
 * @param  array{planSet: MeasurementPlanSet}  $scenario
 * @return array<string, mixed>
 */
function periodLinkageUnderGoverning(array $scenario): array
{
    return ['lines' => periodLinkageGoverningLines($scenario['planSet'])] + $scenario;
}

/**
 * Medição da competência ('Y-m', ou nenhuma) ainda sem arquivo, como o envio a
 * cria antes de gravar o arquivo de cada empreendimento.
 *
 * @param  array{actor: User, operation: Operation}  $scenario
 */
function periodLinkageMeasurement(array $scenario, ?string $month): Measurement
{
    return Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => $month === null ? null : "{$month}-01",
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $scenario['actor']->id,
    ]);
}

/**
 * Grava o arquivo da medição numa medição prevista -- ou, recebendo o plano,
 * sem linha. É aqui que o arquivo congela a versão do plano.
 */
function periodLinkageAttach(Measurement $measurement, MeasurementPlanLine|MeasurementPlanSet $target): MeasurementAsset
{
    $line = $target instanceof MeasurementPlanLine ? $target : null;
    $planSetId = $line instanceof MeasurementPlanLine ? (int) $line->plan_set_id : (int) $target->getKey();
    $path = sprintf('nimbus_docs/measurements/assets/period-linkage-%d-%d-%s.pdf', $measurement->id, $planSetId, $line?->id ?? 'sem-linha');
    Storage::disk('local')->put($path, "%PDF-1.7 competência e versão do plano: {$path}");

    return $measurement->assets()->create([
        'plan_set_id' => $planSetId,
        'plan_line_id' => $line?->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);
}

/**
 * Medição da competência com um arquivo em cada medição prevista informada,
 * aguardando a Engenharia -- como o envio a grava.
 *
 * @param  array{actor: User, operation: Operation}  $scenario
 * @param  list<MeasurementPlanLine>  $lines
 */
function periodLinkageSend(array $scenario, string $month, array $lines): Measurement
{
    $measurement = periodLinkageMeasurement($scenario, $month);

    foreach ($lines as $line) {
        periodLinkageAttach($measurement, $line);
    }

    app(MeasurementWorkflow::class)->startReview($measurement->fresh(), $scenario['actor']);

    return $measurement->fresh();
}

/**
 * A versão, a medição prevista e a ocupação que o arquivo da medição gravou.
 *
 * @return array{plan_version_id: mixed, plan_line_id: mixed, line_claim_key: mixed}
 */
function periodLinkageBinding(Measurement $measurement): array
{
    return $measurement->assets()->sole()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']);
}

/**
 * Recusa do domínio como ela sai, com o contexto que vai para o log.
 */
function periodLinkageRefusal(callable $attempt): MeasurementWorkflowException
{
    try {
        $attempt();
    } catch (MeasurementWorkflowException $refusal) {
        return $refusal;
    }

    test()->fail('A gravação deveria ter sido recusada.');
}

/**
 * Erros da recusa da Engenharia exatamente como o modal os recebe.
 *
 * @return array<string, list<string>>
 */
function periodLinkageEngineeringErrors(callable $approval): array
{
    try {
        $approval();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('A Engenharia deveria ter recusado a aprovação.');
}

/**
 * A próxima competência pendente da operação ('m/Y'), como a listagem a mostra.
 */
function periodLinkageNextMonth(Operation $operation): ?string
{
    return app(OperationNextMeasurementResolver::class)
        ->addNextMeasurementDate(Operation::query())
        ->findOrFail($operation->id)
        ->next_pending_measurement_at?->format('m/Y');
}

/**
 * As medições previstas do mês que regem a própria competência e ainda podem
 * receber medição.
 *
 * @return list<int>
 */
function periodLinkageOfferedLineIds(MeasurementPlanSet $planSet, string $month): array
{
    return MeasurementPlanLine::query()
        ->where('plan_set_id', $planSet->id)
        ->governingTheirCompetence()
        ->availableForMeasurement()
        ->inCompetence($month)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

/**
 * Opções do seletor "Medição do cronograma" do Enviar Medição como o
 * formulário as monta, sem renderizar a tela.
 *
 * @return array<int, string>
 */
function periodLinkageScheduleOptions(MeasurementPlanSet $planSet): array
{
    $probe = new class extends MeasurementForm
    {
        /**
         * @return array<int, string>
         */
        public static function schedules(mixed $planSetId): array
        {
            return parent::scheduleOptionsForPlanSet($planSetId);
        }
    };

    return $probe::schedules($planSet->id);
}

// ── A versão que rege a competência no envio ─────────────────────────────────

it('binds the June measurement sent in August to V1, the version that governs June, and the July and August measurements to V2', function () {
    $scenario = periodLinkageScenario();
    ['v1' => $v1, 'v2' => $v2, 'lines' => $v1Lines, 'v2Lines' => $v2Lines] = $scenario;
    $resolver = app(MeasurementPlanVersionResolver::class);

    // A V2 vale desde 07/2027; a V1, substituída, continua regendo o que veio
    // antes: junho é da V1, julho e agosto são da V2.
    expect($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->effective_from->toDateString())->toBe('2027-07-01')
        ->and(array_map(fn (string $month): ?int => $resolver->forCompetence($scenario['planSet'], $month)?->id, ['2027-06', '2027-07', '2027-08']))
        ->toBe([$v1->id, $v2->id, $v2->id]);

    // 10/08: junho atrasado, julho e agosto são enviados no mesmo dia, cada um
    // pela medição prevista da versão que rege o próprio mês.
    periodLinkageTravelTo('2027-08-10');
    $governing = periodLinkageUnderGoverning($scenario);
    $june = Scenario::measurement($governing, '2027-06');
    $july = Scenario::measurement($governing, '2027-07');
    $august = Scenario::measurement($governing, '2027-08');

    // Junho fica na V1, na medição prevista de junho dela -- e não na cópia
    // que a V2 traz com a mesma linhagem: a ocupação é da linhagem de junho.
    expect($v2Lines['2027-06']->lineage_key)->toBe($v1Lines['2027-06']->lineage_key)
        ->and(periodLinkageBinding($june))->toBe([
            'plan_version_id' => $v1->id,
            'plan_line_id' => $v1Lines['2027-06']->id,
            'line_claim_key' => $v1Lines['2027-06']->lineage_key,
        ])
        ->and(periodLinkageBinding($july))->toBe([
            'plan_version_id' => $v2->id,
            'plan_line_id' => $v2Lines['2027-07']->id,
            'line_claim_key' => $v1Lines['2027-07']->lineage_key,
        ])
        ->and(periodLinkageBinding($august))->toBe([
            'plan_version_id' => $v2->id,
            'plan_line_id' => $v2Lines['2027-08']->id,
            'line_claim_key' => $v1Lines['2027-08']->lineage_key,
        ])
        ->and($v1->assets()->pluck('measurement_id')->all())->toBe([$june->id])
        ->and($v2->assets()->orderBy('measurement_id')->pluck('measurement_id')->all())->toBe([$july->id, $august->id])
        ->and([$june->status, $july->status, $august->status])->toBe(['in_review', 'in_review', 'in_review']);
});

it('refuses for June the copy of the June line that V2 carries and for August the August line of V1, persisting nothing', function () {
    $scenario = periodLinkageScenario();
    ['v1' => $v1, 'v2' => $v2, 'lines' => $v1Lines, 'v2Lines' => $v2Lines] = $scenario;

    periodLinkageTravelTo('2027-08-10');
    $june = periodLinkageMeasurement($scenario, '2027-06');
    $august = periodLinkageMeasurement($scenario, '2027-08');

    // A cópia de junho na V2 é a mesma medição prevista (mesma linhagem), mas
    // não é da versão que rege junho; a linha de agosto da V1 é de uma versão
    // que já não rege agosto. Nenhuma das duas é trocada em silêncio.
    $juneRefusal = periodLinkageRefusal(fn () => periodLinkageAttach($june, $v2Lines['2027-06']));
    $augustRefusal = periodLinkageRefusal(fn () => periodLinkageAttach($august, $v1Lines['2027-08']));

    expect($juneRefusal->getMessage())->toBe(sprintf(MeasurementAsset::VERSION_NOT_GOVERNING_REFUSAL, '06/2027', 'V2', 'Residencial Horizonte', 'V1'))
        ->and($juneRefusal->context())->toBe([
            'plan_line_id' => $v2Lines['2027-06']->id,
            'plan_version_id' => $v2->id,
            'governing_plan_version_id' => $v1->id,
        ])
        ->and($augustRefusal->getMessage())->toBe(sprintf(MeasurementAsset::VERSION_NOT_GOVERNING_REFUSAL, '08/2027', 'V1', 'Residencial Horizonte', 'V2'))
        ->and($augustRefusal->context()['governing_plan_version_id'])->toBe($v2->id);

    // Nada fica gravado: nem arquivo, nem ocupação, nem o PDF no disco. As
    // medições previstas que regem junho e agosto continuam livres.
    expect(MeasurementAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([])
        ->and(periodLinkageOfferedLineIds($scenario['planSet'], '2027-06'))->toBe([$v1Lines['2027-06']->id])
        ->and(periodLinkageOfferedLineIds($scenario['planSet'], '2027-08'))->toBe([$v2Lines['2027-08']->id]);
});

it('never lets a draft or a cancelled revision govern a competence: their lines are refused and the line of the version in force is accepted', function () {
    $scenario = periodLinkageScenario();
    ['v1' => $v1, 'v2' => $v2, 'v2Lines' => $v2Lines, 'planSet' => $planSet] = $scenario;
    $resolver = app(MeasurementPlanVersionResolver::class);
    $service = app(MeasurementPlanVersionService::class);

    // 10/08: a V3 é aberta como rascunho, copiada da V2.
    periodLinkageTravelTo('2027-08-10');
    $v3 = periodLinkageDraftRevision($scenario, $planSet, MeasurementPlanRevisionCategory::Schedule);
    $v3Lines = periodLinkageLinesOf($v3);
    $august = periodLinkageMeasurement($scenario, '2027-08');

    // O rascunho ainda não vale: agosto continua regido pela V2, e a medição
    // prevista de agosto do rascunho é recusada.
    expect($v3->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($v3->effective_from)->toBeNull()
        ->and($v3Lines['2027-08']->lineage_key)->toBe($v2Lines['2027-08']->lineage_key)
        ->and($resolver->forCompetence($planSet, '2027-08')?->id)->toBe($v2->id)
        ->and(fn () => periodLinkageAttach($august, $v3Lines['2027-08']))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementAsset::VERSION_NOT_GOVERNING_REFUSAL, '08/2027', 'V3', 'Residencial Horizonte', 'V2')))
        ->and($august->assets()->exists())->toBeFalse();

    // A de agosto da V2, que rege agosto, é aceita com o rascunho aberto.
    $accepted = periodLinkageAttach($august, $v2Lines['2027-08']);

    expect($accepted->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
        'plan_version_id' => $v2->id,
        'plan_line_id' => $v2Lines['2027-08']->id,
        'line_claim_key' => $v2Lines['2027-08']->lineage_key,
    ]);

    // Cancelado, o rascunho nunca valeu: as linhas dele continuam recusadas,
    // em qualquer competência, e quem rege cada mês não muda.
    $cancelled = $service->cancel($v3, $scenario['planner'], 'Replanejamento abandonado pelo comitê de obras.', (int) $v3->revision);
    $september = periodLinkageMeasurement($scenario, '2027-09');
    $june = periodLinkageMeasurement($scenario, '2027-06');

    expect($cancelled->status)->toBe(MeasurementPlanVersionStatus::Cancelled)
        ->and($resolver->forCompetence($planSet, '2027-09')?->id)->toBe($v2->id)
        ->and($resolver->forCompetence($planSet, '2027-06')?->id)->toBe($v1->id)
        ->and(fn () => periodLinkageAttach($september, $v3Lines['2027-09']))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementAsset::VERSION_NOT_GOVERNING_REFUSAL, '09/2027', 'V3', 'Residencial Horizonte', 'V2')))
        ->and(fn () => periodLinkageAttach($june, $v3Lines['2027-06']))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementAsset::VERSION_NOT_GOVERNING_REFUSAL, '06/2027', 'V3', 'Residencial Horizonte', 'V1')))
        ->and(MeasurementAsset::query()->where('plan_version_id', $v3->id)->exists())->toBeFalse()
        ->and(periodLinkageAttach($september, $v2Lines['2027-09'])->plan_version_id)->toBe($v2->id);
});

// ── A versão congelada no arquivo ────────────────────────────────────────────

it('keeps the June measurement sent under V1 on V1 through two later revisions and approves it against the V1 schedule and fund', function () {
    $scenario = periodLinkageScenarioUnderV1();
    ['v1' => $v1, 'planSet' => $planSet] = $scenario;
    $june = $scenario['lines']['2027-06'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $frozen = ['plan_version_id' => $v1->id, 'plan_line_id' => $june->id, 'line_claim_key' => $june->lineage_key];

    // 20/06/2027: a V1 vigente rege junho, e a medição de junho a congela.
    periodLinkageTravelTo('2027-06-20');
    $measurement = Scenario::measurement(periodLinkageUnderGoverning($scenario), '2027-06');

    expect(periodLinkageBinding($measurement))->toBe($frozen);

    // A V2 (custo, desde 07/2027) e a V3 (custo, ativada em 03/09, desde
    // 09/2027) entram em vigor com junho ainda na Engenharia: o arquivo não
    // muda de versão nem de linha.
    $v2 = periodLinkageReviseCost($scenario);

    expect(periodLinkageBinding($measurement))->toBe($frozen);

    $v3 = periodLinkageRevise($scenario, $planSet, '2027-09-03', MeasurementPlanRevisionCategory::Cost, '25000000.00');

    expect($v2->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v3->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v3->effective_from->toDateString())->toBe('2027-09-01')
        ->and(periodLinkageBinding($measurement))->toBe($frozen)
        ->and($measurement->fresh()->only(['status', 'current_stage']))->toBe(['status' => 'in_review', 'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING]);

    // 06/09: a Engenharia aprova junho contra a V1 -- cronograma e Fundo de
    // Obra dela --, com a V3 vigente.
    periodLinkageTravelTo('2027-09-06');
    Scenario::approveEngineering($scenario, $measurement, 8);
    $snapshot = $measurement->fresh()->engineering_snapshot;
    $entry = $snapshot['plan_sets'][0];

    expect($snapshot['plan_sets'])->toHaveCount(1)
        ->and($entry['plan_version_id'])->toBe($v1->id)
        ->and($entry['plan_version_number'])->toBe(1)
        ->and($entry['construction_fund_amount'])->toBe('20000000.00')
        ->and($entry['plan_line_id'])->toBe($june->id)
        ->and($entry['plan_line_lineage_key'])->toBe($june->lineage_key)
        ->and($entry['measurement_date'])->toBe('2027-06-01')
        ->and($entry['planned_cumulative_percent'])->toBe('48.00')
        ->and($entry['realized_monthly_percent'])->toBe('8.00')
        ->and($measurement->fresh()->current_stage)->toBe(2)
        ->and(periodLinkageBinding($measurement))->toBe($frozen)
        ->and($planSet->fresh()->currentConstructionFundAmount())->toBe('25000000.00');

    // E o histórico continua o mesmo: junho é da V1, julho da V2, setembro da V3.
    expect($resolver->forCompetence($planSet, '2027-06')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence($planSet, '2027-07')?->id)->toBe($v2->id)
        ->and($resolver->forCompetence($planSet, '2027-09')?->id)->toBe($v3->id);
});

it('pays the delayed June measurement against the V1 fund of R$ 20 million while V2 is in force', function () {
    $scenario = periodLinkageScenario();
    $workflow = app(MeasurementWorkflow::class);
    $planSetId = $scenario['planSet']->id;

    // Junho sai em 10/08, com a V2 (R$ 23 milhões) vigente desde julho, e a
    // Engenharia o aprova com 10%.
    periodLinkageTravelTo('2027-08-10');
    $june = Scenario::measurement(periodLinkageUnderGoverning($scenario), '2027-06');
    periodLinkageTravelTo('2027-08-11');
    Scenario::approveEngineering($scenario, $june, 10);
    $entry = $june->fresh()->engineering_snapshot['plan_sets'][0];

    expect([$entry['plan_version_id'], $entry['plan_version_number'], $entry['construction_fund_amount'], $entry['realized_monthly_percent']])
        ->toBe([$scenario['v1']->id, 1, '20000000.00', '10.00']);

    // Gestão e Compliance aprovam; o pagamento de R$ 2.000.000,00 -- 10% de
    // R$ 20 milhões -- confere com a referência. Contra o fundo da V2, o
    // esperado seria R$ 2.300.000,00 e o mesmo pagamento divergiria.
    periodLinkageTravelTo('2027-08-12');
    $workflow->approve($june->fresh(), $scenario['actor']);
    $workflow->approve($june->fresh(), $scenario['actor']);

    expect($june->fresh()->only(['status', 'current_stage']))->toBe(['status' => 'awaiting_payment', 'current_stage' => MeasurementWorkflow::STAGE_PAYMENT]);

    $payment = $workflow->registerPayment($june->fresh(), $scenario['actor'], [
        'plan_set_id' => $planSetId,
        'pay_date' => '2027-08-12',
        'amount' => '2000000.00',
        'method' => 'TED',
    ]);
    $assessment = $payment->fresh()->financial_assessment;

    expect($payment->plan_set_id)->toBe($planSetId)
        ->and($assessment['reference']['fund_amount'])->toBe('20000000.00')
        ->and($assessment['reference']['expected_amount'])->toBe('2000000.00')
        ->and($assessment['reference']['divergence_amount'])->toBe('0.00')
        ->and($assessment['reference']['status'])->toBe('matched')
        ->and($assessment['requires_acceptance'])->toBeFalse()
        ->and($scenario['planSet']->fresh()->currentConstructionFundAmount())->toBe('23000000.00');
});

it('records the approval of the delayed June measurement on the June line of V1 and not on the copy V2 carries', function () {
    $scenario = periodLinkageScenario();
    $v1June = $scenario['lines']['2027-06'];
    $v2June = $scenario['v2Lines']['2027-06'];

    periodLinkageTravelTo('2027-08-10');
    $june = Scenario::measured(periodLinkageUnderGoverning($scenario), '2027-06', 10);

    // A execução fica na linha de junho da V1, a que a medição congelou; a
    // cópia da V2 continua sem realizado -- e ocupada, porque a linhagem é a
    // mesma.
    expect($v1June->fresh()->only(['realized_monthly_percent', 'realized_cumulative_percent', 'measurement_id']))->toBe([
        'realized_monthly_percent' => '10.00',
        'realized_cumulative_percent' => '10.00',
        'measurement_id' => $june->id,
    ])
        ->and($v2June->fresh()->only(['realized_monthly_percent', 'realized_cumulative_percent', 'measurement_id']))->toBe([
            'realized_monthly_percent' => '0.00',
            'realized_cumulative_percent' => '0.00',
            'measurement_id' => null,
        ])
        ->and(MeasurementPlanLine::query()->whereKey([$v1June->id, $v2June->id])->availableForMeasurement()->exists())->toBeFalse();

    // O avanço do plano conta junho uma vez, pela linha da V1 e pela linhagem
    // de junho.
    $progress = Scenario::progress($scenario);

    expect($progress->currentPercent())->toBe('10.00')
        ->and(array_map(fn (MeasurementPhysicalProgressContribution $contribution): array => [
            $contribution->measurementId,
            $contribution->planLineId,
            $contribution->lineageKey,
            $contribution->measurementDate?->toDateString(),
            $contribution->basisPoints,
        ], $progress->contributions))->toBe([[$june->id, $v1June->id, $v1June->lineage_key, '2027-06-01', 1000]]);
});

// ── A competência da medição ─────────────────────────────────────────────────

it('refuses at creation the May line of V1 on a measurement whose competence is June', function () {
    $scenario = periodLinkageScenario();
    $may = $scenario['lines']['2027-05'];

    periodLinkageTravelTo('2027-08-10');
    $june = periodLinkageMeasurement($scenario, '2027-06');

    // Maio também é regido pela V1, mas a competência da medição é junho: é
    // ela que decide a medição prevista de cada arquivo.
    $refusal = periodLinkageRefusal(fn () => periodLinkageAttach($june, $may));

    expect($refusal->getMessage())->toBe(sprintf(MeasurementAsset::COMPETENCE_MISMATCH_REFUSAL, 'Residencial Horizonte', '05/2027', '06/2027'))
        ->and($refusal->context())->toBe(['measurement_id' => $june->id, 'plan_line_id' => $may->id])
        ->and(MeasurementAsset::query()->count())->toBe(0)
        ->and(periodLinkageOfferedLineIds($scenario['planSet'], '2027-05'))->toBe([$may->id]);

    // A medição prevista de junho, da versão que rege junho, é aceita.
    expect(periodLinkageAttach($june, $scenario['lines']['2027-06'])->plan_version_id)->toBe($scenario['v1']->id);
});

it('freezes no version on a file without a schedule line, with or without a competence: the version is frozen with the planned measurement', function () {
    $scenario = periodLinkageScenario();
    $planSet = $scenario['planSet'];

    periodLinkageTravelTo('2027-08-10');
    $june = periodLinkageAttach(periodLinkageMeasurement($scenario, '2027-06'), $planSet);
    $july = periodLinkageAttach(periodLinkageMeasurement($scenario, '2027-07'), $planSet);
    $undated = periodLinkageAttach(periodLinkageMeasurement($scenario, null), $planSet);
    $columns = ['plan_set_id', 'plan_version_id', 'plan_line_id', 'line_claim_key'];
    $unbound = [
        'plan_set_id' => $planSet->id,
        'plan_version_id' => null,
        'plan_line_id' => null,
        'line_claim_key' => null,
    ];

    // Sem a medição prevista não há o que congelar -- a versão é a da linha,
    // e a Engenharia não aprova arquivo sem linha. Congelar pela competência
    // deixaria o arquivo fora da guarda da ativação, que protege as
    // competências pelas linhas ocupadas.
    expect($june->fresh()->only($columns))->toBe($unbound)
        ->and($july->fresh()->only($columns))->toBe($unbound)
        ->and($undated->fresh()->only($columns))->toBe($unbound);

    // Escolhida a linha, a versão nasce com ela: a que rege a competência.
    $june->fresh()->fill(['plan_line_id' => $scenario['lines']['2027-06']->id])->save();

    expect($june->fresh()->only(['plan_version_id', 'plan_line_id']))->toBe([
        'plan_version_id' => $scenario['v1']->id,
        'plan_line_id' => $scenario['lines']['2027-06']->id,
    ]);
});

// ── Próxima medição e cronograma oferecido ───────────────────────────────────

it('suggests the delayed June by the V1 line that governs it and never offers nor suggests the competence a later revision removed', function () {
    $scenario = periodLinkageScenarioUnderV1();
    ['planSet' => $planSet, 'lines' => $v1Lines] = $scenario;
    $resolver = app(MeasurementPlanVersionResolver::class);

    // Janeiro a maio são medidos (8% cada) sob a V1, antes da revisão de custo
    // de julho; junho fica para trás.
    periodLinkageTravelTo('2027-06-10');
    $underV1 = periodLinkageUnderGoverning($scenario);

    foreach (periodLinkageMonths('2027-01', 5) as $month) {
        Scenario::measured($underV1, $month, 8);
    }

    $v2 = periodLinkageReviseCost($scenario);
    $v2Lines = periodLinkageLinesOf($v2);

    // 10/08: a próxima competência é junho, atrasado, pela medição prevista
    // da V1 -- a única de junho que rege a própria competência e está livre --,
    // e é ela que o Enviar Medição oferece, antes das da V2.
    periodLinkageTravelTo('2027-08-10');
    $this->actingAs($scenario['actor']);

    expect(periodLinkageNextMonth($scenario['operation']))->toBe('06/2027')
        ->and(periodLinkageOfferedLineIds($planSet, '2027-06'))->toBe([$v1Lines['2027-06']->id])
        ->and(periodLinkageScheduleOptions($planSet))->toBe([
            $v1Lines['2027-06']->id => 'Medição 06 · 06/2027 · 48,0% planejado',
            $v2Lines['2027-07']->id => 'Medição 07 · 07/2027 · 56,0% planejado',
            $v2Lines['2027-08']->id => 'Medição 08 · 08/2027 · 64,0% planejado',
            $v2Lines['2027-09']->id => 'Medição 09 · 09/2027 · 72,0% planejado',
            $v2Lines['2027-10']->id => 'Medição 10 · 10/2027 · 80,0% planejado',
            $v2Lines['2027-11']->id => 'Medição 11 · 11/2027 · 88,0% planejado',
            $v2Lines['2027-12']->id => 'Medição 12 · 12/2027 · 96,0% planejado',
        ]);

    // 03/09: a V3 tira outubro do cronograma (obra parada), desde 09/2027.
    $v3 = periodLinkageRevise($scenario, $planSet, '2027-09-03', MeasurementPlanRevisionCategory::Schedule, schedule: fn (array $draftLines): array => collect($draftLines)
        ->reject(fn (MeasurementPlanLine $line, string $month): bool => $month === '2027-10')
        ->map(fn (MeasurementPlanLine $line): array => [
            'id' => $line->id,
            'sequence_number' => $line->sequence_number,
            'planned_monthly_percent' => $line->planned_monthly_percent,
            'planned_cumulative_percent' => $line->planned_cumulative_percent,
            'measurement_date' => $line->measurement_date->format('Y-m'),
        ])
        ->values()
        ->all());
    $v3Lines = periodLinkageLinesOf($v3);

    // Outubro passa a ser regido pela V3, que não o prevê: as linhas de
    // outubro da V1 e da V2 continuam livres, mas não regem outubro, e nada de
    // outubro é oferecido nem sugerido.
    expect(array_keys($v3Lines))->not->toContain('2027-10')
        ->and($resolver->forCompetence($planSet, '2027-10')?->id)->toBe($v3->id)
        ->and(MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->inCompetence('2027-10')->availableForMeasurement()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$v1Lines['2027-10']->id, $v2Lines['2027-10']->id])
        ->and(periodLinkageOfferedLineIds($planSet, '2027-10'))->toBe([])
        ->and(periodLinkageNextMonth($scenario['operation']))->toBe('06/2027')
        ->and(periodLinkageScheduleOptions($planSet))->toBe([
            $v1Lines['2027-06']->id => 'Medição 06 · 06/2027 · 48,0% planejado',
            $v2Lines['2027-07']->id => 'Medição 07 · 07/2027 · 56,0% planejado',
            $v2Lines['2027-08']->id => 'Medição 08 · 08/2027 · 64,0% planejado',
            $v3Lines['2027-09']->id => 'Medição 09 · 09/2027 · 72,0% planejado',
            $v3Lines['2027-11']->id => 'Medição 11 · 11/2027 · 80,0% planejado',
            $v3Lines['2027-12']->id => 'Medição 12 · 12/2027 · 88,0% planejado',
        ]);

    // Medidos junho (V1), julho e agosto (V2) e setembro (V3), a próxima
    // competência pula outubro e vai a novembro.
    periodLinkageTravelTo('2027-09-10');
    $governing = periodLinkageUnderGoverning($scenario);

    foreach (periodLinkageMonths('2027-06', 4) as $month) {
        Scenario::measured($governing, $month, 8);
    }

    expect(periodLinkageNextMonth($scenario['operation']))->toBe('11/2027')
        ->and(periodLinkageScheduleOptions($planSet))->toBe([
            $v3Lines['2027-11']->id => 'Medição 11 · 11/2027 · 80,0% planejado',
            $v3Lines['2027-12']->id => 'Medição 12 · 12/2027 · 88,0% planejado',
        ]);

    // Nem pelo modelo: a medição prevista de outubro da V2 não vale para outubro.
    $october = periodLinkageMeasurement($scenario, '2027-10');

    expect(fn () => periodLinkageAttach($october, $v2Lines['2027-10']))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementAsset::VERSION_NOT_GOVERNING_REFUSAL, '10/2027', 'V2', 'Residencial Horizonte', 'V3')))
        ->and($october->assets()->exists())->toBeFalse();
});

// ── Engenharia ───────────────────────────────────────────────────────────────

it('approves at Engineering an August measurement sent before a revision that added August to another plan without that plan, and requires it from the August measurement sent after', function () {
    // Torre Alfa (01/2027 a 12/2027) e Torre Beta (09/2027 a 12/2027), as duas
    // vigentes desde 01/2027.
    periodLinkageTravelTo('2027-01-05');
    $scenario = periodLinkageOperation();
    $alfa = periodLinkageActivePlan($scenario, 'Torre Alfa', '20000000.00', periodLinkageMonths('2027-01', 12));
    $beta = periodLinkageActivePlan($scenario, 'Torre Beta', '5000000.00', periodLinkageMonths('2027-09', 4), '10.00', isDefault: false);
    $betaV1 = periodLinkageVersion($beta, 1);
    $alfaScenario = ['planSet' => $alfa, 'lines' => periodLinkageGoverningLines($alfa)] + $scenario;
    $resolver = app(MeasurementPlanVersionResolver::class);

    // 05/08: agosto (N) é enviado só com a Torre Alfa -- a Torre Beta não
    // prevê agosto.
    periodLinkageTravelTo('2027-08-05');
    $n = Scenario::measurement($alfaScenario, '2027-08');

    // 10/08: a revisão de cronograma da Torre Beta, vigente desde 08/2027,
    // acrescenta uma medição prevista em agosto.
    $betaV2 = periodLinkageRevise($scenario, $beta, '2027-08-10', MeasurementPlanRevisionCategory::Schedule, schedule: fn (array $draftLines): array => [
        ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2027-08'],
        ['id' => $draftLines['2027-09']->id, 'sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2027-09'],
        ['id' => $draftLines['2027-10']->id, 'sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '30.00', 'measurement_date' => '2027-10'],
        ['id' => $draftLines['2027-11']->id, 'sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2027-11'],
        ['id' => $draftLines['2027-12']->id, 'sequence_number' => 5, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '50.00', 'measurement_date' => '2027-12'],
    ]);
    $betaAugust = periodLinkageLinesOf($betaV2)['2027-08'];

    // Hoje a V2 rege agosto da Torre Beta e o prevê; no envio de N, quem
    // regia agosto era a V1, que não o prevê.
    expect($betaV1->effective_from->toDateString())->toBe('2027-01-01')
        ->and($betaV2->effective_from->toDateString())->toBe('2027-08-01')
        ->and($betaV2->last_measurement_id_at_activation)->toBe($n->id)
        ->and($resolver->forCompetence($beta, '2027-08')?->id)->toBe($betaV2->id)
        ->and($resolver->forCompetence($beta, '2027-08', $n->id)?->id)->toBe($betaV1->id)
        ->and($resolver->planSetsPlannedIn([$alfa->id, $beta->id], '2027-08'))->toBe([$alfa->id, $beta->id]);

    // A Engenharia aprova N só com a Torre Alfa: a revisão posterior ao envio
    // não muda o que N precisa cobrir.
    periodLinkageTravelTo('2027-08-12');
    Scenario::approveEngineering($alfaScenario, $n, 8);

    expect($n->fresh()->current_stage)->toBe(2)
        ->and(array_column($n->fresh()->engineering_snapshot['plan_sets'], 'plan_set_id'))->toBe([$alfa->id]);

    // N volta à Engenharia e é recusada; agosto é enviado de novo, depois da
    // revisão, ainda só com a Torre Alfa: agora a Torre Beta é exigida.
    Scenario::returnToEngineering($alfaScenario, $n);
    app(MeasurementWorkflow::class)->reject($n->fresh(), $scenario['actor'], 'Planilha de medição de outra obra.');
    periodLinkageTravelTo('2027-08-13');
    $resent = Scenario::measurement($alfaScenario, '2027-08');

    expect($n->fresh()->status)->toBe('rejected')
        ->and(periodLinkageEngineeringErrors(fn () => Scenario::approveEngineering($alfaScenario, $resent, 8)))->toBe([
            'assets.coverage' => ['Envie exatamente um arquivo para cada empreendimento da operação.'],
            "assets.{$beta->id}" => ['Envie o arquivo da medição para Torre Beta.'],
        ])
        ->and($resent->fresh()->only(['status', 'current_stage', 'engineering_snapshot']))->toBe([
            'status' => 'in_review',
            'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
            'engineering_snapshot' => null,
        ]);

    // Com o arquivo da Torre Beta na medição prevista de agosto da V2, a
    // competência é aprovada com os dois planos.
    app(MeasurementWorkflow::class)->reject($resent->fresh(), $scenario['actor'], 'Falta o arquivo da Torre Beta.');
    $complete = periodLinkageSend($scenario, '2027-08', [$alfaScenario['lines']['2027-08'], $betaAugust]);
    app(MeasurementWorkflow::class)->approve($complete->fresh(), $scenario['actor'], engineeringProgress: [$alfa->id => 8, $beta->id => 10]);

    expect(array_map(fn (array $entry): array => [$entry['plan_set_id'], $entry['plan_version_number'], $entry['plan_line_id']], $complete->fresh()->engineering_snapshot['plan_sets']))
        ->toBe([[$alfa->id, 1, $alfaScenario['lines']['2027-08']->id], [$beta->id, 2, $betaAugust->id]]);
});

it('refuses at Engineering a June file rewritten outside the model to V2 and its June copy, and approves the June measurement sent again under V1', function () {
    $scenario = periodLinkageScenario();
    $planSetId = $scenario['planSet']->id;
    $v2June = $scenario['v2Lines']['2027-06'];

    periodLinkageTravelTo('2027-08-10');
    $june = Scenario::measurement(periodLinkageUnderGoverning($scenario), '2027-06');
    $asset = $june->assets()->sole();

    // Fora do modelo, o arquivo passa para a V2 e para a cópia de junho dela
    // (as chaves estrangeiras continuam válidas): a Engenharia não aprova
    // junho contra o cronograma e o Fundo de Obra de uma versão que não o rege.
    DB::table('measurement_assets')->where('id', $asset->id)->update([
        'plan_version_id' => $scenario['v2']->id,
        'plan_line_id' => $v2June->id,
    ]);

    expect(periodLinkageEngineeringErrors(fn () => Scenario::approveEngineering($scenario, $june, 10)))->toBe([
        "plan_line.{$planSetId}" => ['A medição de Residencial Horizonte foi enviada sob a V2 do plano, que não vale para a competência 06/2027: recuse esta medição e envie uma nova.'],
    ])
        ->and($june->fresh()->only(['status', 'current_stage', 'engineering_snapshot']))->toBe([
            'status' => 'in_review',
            'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
            'engineering_snapshot' => null,
        ])
        ->and($june->fresh()->hasApprovedEngineering())->toBeFalse()
        ->and($v2June->fresh()->measurement_id)->toBeNull()
        ->and($scenario['lines']['2027-06']->fresh()->measurement_id)->toBeNull();

    // É o caminho da mensagem: recusada a medição, junho volta a ser enviado
    // sob a V1 e aprovado.
    app(MeasurementWorkflow::class)->reject($june->fresh(), $scenario['actor'], 'Arquivo vinculado à versão errada do plano.');
    $resent = Scenario::measured(periodLinkageUnderGoverning($scenario), '2027-06', 10);

    expect(periodLinkageBinding($resent))->toBe([
        'plan_version_id' => $scenario['v1']->id,
        'plan_line_id' => $scenario['lines']['2027-06']->id,
        'line_claim_key' => $scenario['lines']['2027-06']->lineage_key,
    ])
        ->and($resent->engineering_snapshot['plan_sets'][0]['plan_version_id'])->toBe($scenario['v1']->id);
});

// ── A competência depois do envio ────────────────────────────────────────────

it('refuses moving the delayed June measurement to July, which V1 does not govern, and to May without moving its planned measurement', function () {
    $scenario = periodLinkageScenario();

    periodLinkageTravelTo('2027-08-10');
    $june = Scenario::measurement(periodLinkageUnderGoverning($scenario), '2027-06');
    $frozen = periodLinkageBinding($june);

    // Julho é da V2: a medição congelada na V1 não muda para lá.
    $refusal = periodLinkageRefusal(fn () => $june->fresh()->fill(['reference_month' => '2027-07-01'])->save());

    expect($refusal->getMessage())->toBe(sprintf(Measurement::FROZEN_VERSION_COMPETENCE_CHANGE_REFUSAL, '07/2027', 'V1', 'Residencial Horizonte'))
        ->and($refusal->context())->toBe([
            'measurement_id' => $june->id,
            'asset_id' => $june->assets()->sole()->id,
            'plan_version_id' => $scenario['v1']->id,
        ])
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-06-01');

    // Maio é regido pela V1, mas a competência é uma só: ela não muda sem a
    // medição prevista do arquivo -- a medição ficaria com duas competências.
    $refusal = periodLinkageRefusal(fn () => $june->fresh()->fill(['reference_month' => '2027-05-01'])->save());

    expect($refusal->getMessage())->toBe(sprintf(Measurement::COMPETENCE_WITHOUT_ITS_LINES_REFUSAL, 'Residencial Horizonte', '06/2027', '05/2027'))
        ->and($june->fresh()->reference_month->toDateString())->toBe('2027-06-01')
        ->and(periodLinkageBinding($june))->toBe($frozen);

    // Trocando a medição prevista para maio (da V1) e a competência junto,
    // como o Editar faz, a medição vai para maio, ainda na V1.
    DB::transaction(function () use ($june, $scenario): void {
        $june->assets()->sole()->fill(['plan_line_id' => $scenario['lines']['2027-05']->id])->save();
        $june->fresh()->fill(['reference_month' => '2027-05-01'])->save();
    });

    expect($june->fresh()->reference_month->toDateString())->toBe('2027-05-01')
        ->and(periodLinkageBinding($june))->toBe([
            'plan_version_id' => $scenario['v1']->id,
            'plan_line_id' => $scenario['lines']['2027-05']->id,
            'line_claim_key' => $scenario['lines']['2027-05']->lineage_key,
        ]);
});

// ── A competência medida sem o plano, conforme o envio ───────────────────────

it('leaves out of the offer, of the next measurement and of the pending the competence a revision added after the operation measured it without the plan', function () {
    // A Torre prevê de 09 a 11/2027 e o Anexo -- o plano padrão -- só 11 e
    // 12/2027; os dois valem desde 09/2027.
    periodLinkageTravelTo('2027-09-02');
    $scenario = periodLinkageOperation();
    $tower = periodLinkageActivePlan($scenario, 'Torre', '10000000.00', periodLinkageMonths('2027-09', 3), '10.00', isDefault: false);
    $annex = periodLinkageActivePlan($scenario, 'Anexo', '5000000.00', periodLinkageMonths('2027-11', 2), '10.00');
    $service = app(MeasurementPlanVersionService::class);
    $this->actingAs($scenario['actor']);

    // 05/10: outubro sai só com a Torre -- certo, porque o Anexo não previa
    // outubro.
    periodLinkageTravelTo('2027-10-05');
    $october = periodLinkageSend($scenario, '2027-10', [periodLinkageLinesOf(periodLinkageVersion($tower, 1))['2027-10']]);

    // 06/10: a revisão do Anexo, vigente desde 10/2027, acrescenta outubro. A
    // ativação passa: o Anexo não tem competência de pé.
    $annexV2 = periodLinkageRevise($scenario, $annex, '2027-10-06', MeasurementPlanRevisionCategory::Schedule, null, fn (array $draft): array => [
        ['sequence_number' => 1, 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '5.00', 'measurement_date' => '2027-10'],
        ['id' => $draft['2027-11']->id, 'sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '15.00', 'measurement_date' => '2027-11'],
        ['id' => $draft['2027-12']->id, 'sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '25.00', 'measurement_date' => '2027-12'],
    ]);
    $annexOctober = periodLinkageLinesOf($annexV2)['2027-10'];

    // Outubro do Anexo rege a competência e está livre, mas a operação já a
    // mediu sem ele, quando ele não previa outubro: uma segunda medição de
    // outubro teria de cobrir a Torre, cuja linha a medição de outubro ocupa.
    // Não é oferecida, nem sugerida, nem conta como pendente.
    expect(MeasurementPlanLine::query()->whereKey($annexOctober->id)->governingTheirCompetence()->availableForMeasurement()->exists())->toBeTrue()
        ->and(MeasurementPlanLine::query()->whereKey($annexOctober->id)->competenceMeasuredWithoutThePlan()->exists())->toBeTrue()
        ->and(array_keys(periodLinkageScheduleOptions($annex)))->toBe([
            periodLinkageLinesOf($annexV2)['2027-11']->id,
            periodLinkageLinesOf($annexV2)['2027-12']->id,
        ])
        ->and(periodLinkageNextMonth($scenario['operation']))->toBe('11/2027')
        ->and($service->monthsMeasuredWithoutThePlan($annex->fresh()))->toBe(['2027-10' => $october->id]);

    // Recusada na Engenharia a medição de outubro, a competência volta a ser
    // medida -- agora com os dois planos.
    periodLinkageTravelTo('2027-10-07');
    app(MeasurementWorkflow::class)->reject($october->fresh(), $scenario['actor'], 'Reenviar outubro com o Anexo.');

    expect(array_keys(periodLinkageScheduleOptions($annex)))->toContain($annexOctober->id)
        ->and(periodLinkageNextMonth($scenario['operation']))->toBe('10/2027')
        ->and($service->monthsMeasuredWithoutThePlan($annex->fresh()))->toBe([]);
});

// ── O arquivo gravado pela regra anterior ────────────────────────────────────

it('re-approves at Engineering a paid measurement returned from Finalization whose file the previous rule froze on the revision copy, instead of leaving it stuck', function () {
    $scenario = periodLinkageScenario();
    $workflow = app(MeasurementWorkflow::class);
    $planSetId = $scenario['planSet']->id;

    // Junho sai em 10/08 e percorre o fluxo até a Finalização, pago.
    periodLinkageTravelTo('2027-08-10');
    $june = Scenario::measured(periodLinkageUnderGoverning($scenario), '2027-06', 10);
    $workflow->approve($june->fresh(), $scenario['actor']);
    $workflow->approve($june->fresh(), $scenario['actor']);
    $workflow->registerPayment($june->fresh(), $scenario['actor'], [
        'plan_set_id' => $planSetId,
        'pay_date' => '2027-08-12',
        'amount' => '2000000.00',
        'method' => 'TED',
    ]);
    $workflow->approve($june->fresh(), $scenario['actor']);

    expect($june->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION);

    // Devolvida da Finalização à Engenharia, com o arquivo como a regra
    // anterior o gravava: na cópia de junho que a V2 traz (mesma linhagem),
    // congelado na V2 -- que não rege junho.
    $workflow->returnToStage($june->fresh(), $scenario['actor'], MeasurementWorkflow::STAGE_ENGINEERING, 'Rever o percentual medido.');
    DB::table('measurement_assets')->where('measurement_id', $june->id)->update([
        'plan_version_id' => $scenario['v2']->id,
        'plan_line_id' => $scenario['v2Lines']['2027-06']->id,
    ]);

    // Sem pagamento, a Engenharia recusaria e a saída seria recusar e
    // reenviar. Com pagamento não há recusa terminal: o contexto pago não muda
    // mais, e a Engenharia o reaprova como está, em vez de prender a medição.
    Scenario::approveEngineering($scenario, $june, 10);

    expect($june->fresh()->current_stage)->toBe(2)
        ->and($june->fresh()->engineering_snapshot['plan_sets'][0]['plan_version_id'])->toBe($scenario['v2']->id);
});

// ── O arquivo sem medição prevista ───────────────────────────────────────────

it('lets a legacy file without a schedule line take the line of the version that governs its competence, even with a version recorded by the migration', function () {
    $scenario = periodLinkageScenario();
    $planSet = $scenario['planSet'];

    // Agosto (regido pela V2) com um arquivo sem linha em que a migração das
    // versões gravou a V1: sem linha, nada estava congelado.
    periodLinkageTravelTo('2027-08-10');
    $asset = periodLinkageAttach(periodLinkageMeasurement($scenario, '2027-08'), $planSet);
    DB::table('measurement_assets')->where('id', $asset->id)->update(['plan_version_id' => $scenario['v1']->id]);

    expect((int) DB::table('measurement_assets')->where('id', $asset->id)->value('plan_version_id'))->toBe($scenario['v1']->id);

    // A linha de agosto da V2 é aceita, e a versão nasce com ela.
    $asset->fresh()->fill(['plan_line_id' => $scenario['v2Lines']['2027-08']->id])->save();

    expect($asset->fresh()->only(['plan_version_id', 'plan_line_id']))->toBe([
        'plan_version_id' => $scenario['v2']->id,
        'plan_line_id' => $scenario['v2Lines']['2027-08']->id,
    ]);
});

it('refuses the revision that would take over the competence of a standing measurement whose file has no schedule line yet', function () {
    $scenario = periodLinkageScenarioUnderV1();

    // Outubro sai em 05/10 com o arquivo ainda sem a medição prevista.
    periodLinkageTravelTo('2027-10-05');
    $october = periodLinkageMeasurement($scenario, '2027-10');
    periodLinkageAttach($october, $scenario['planSet']);
    app(MeasurementWorkflow::class)->startReview($october->fresh(), $scenario['actor']);

    // Ativada em outubro, a revisão passaria a reger outubro: a versão que o
    // arquivo vai congelar ao ganhar a linha é a que regia no envio.
    $refusal = null;

    try {
        periodLinkageRevise($scenario, $scenario['planSet'], '2027-10-20', MeasurementPlanRevisionCategory::Cost, '25000000.00');
    } catch (ValidationException $exception) {
        $refusal = $exception->errors();
    }

    expect($refusal)->toHaveKey('effective_from')
        ->and($refusal['effective_from'][0])->toContain('10/2027')
        ->and($refusal['effective_from'][0])->toContain("medição de pé (#{$october->id})")
        ->and(app(MeasurementPlanVersionResolver::class)->forCompetence($scenario['planSet'], '2027-10')?->id)->toBe($scenario['v1']->id);
});
