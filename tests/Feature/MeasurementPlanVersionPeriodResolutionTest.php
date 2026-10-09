<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionResolver;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Qual versão do plano de medição rege cada competência
 * ({@see MeasurementPlanVersionResolver}).
 *
 * Regra: a competência (o mês) da medição decide a versão do plano. Entre as
 * versões que valeram -- vigente ou substituída --, rege o mês M a de maior
 * número cuja vigência começa até M; antes da vigência da primeira, rege a V1,
 * que planeja esses meses como medições atrasadas. Rascunho e revisão
 * cancelada nunca regem, e a versão substituída no próprio mês da ativação não
 * rege mês nenhum. Lido como estava no envio de uma medição, o histórico só
 * conta as versões ativadas antes dela. A vigência é sempre o 1º dia do mês da
 * ativação no calendário de negócio, e a regra é uma só no resolvedor e no SQL
 * das telas e da Engenharia.
 *
 * Os históricos são montados pelo serviço de versões com o relógio levado a
 * cada dia de ativação: a vigência de cada versão é a que a ativação real
 * grava, e não uma data escolhida pelo teste.
 */
uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    config()->set('filesystems.private_disk', 'local');
    // Vigência e competência são do calendário de negócio: o fuso fixo deixa
    // as fronteiras de mês independentes do ambiente.
    config()->set('measurements.business_timezone', 'America/Sao_Paulo');
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a versão ativada vale a
 * partir do mês desse dia no calendário de negócio.
 */
function periodResolutionTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * As competências de `$from` a `$to` ('Y-m'), inclusive.
 *
 * @return list<string>
 */
function periodResolutionMonths(string $from, string $to): array
{
    $months = [];

    for ($month = CarbonImmutable::parse("{$from}-01"); $month->format('Y-m') <= $to; $month = $month->addMonthNoOverflow()) {
        $months[] = $month->format('Y-m');
    }

    return $months;
}

/**
 * Operação em andamento com o plano do Residencial Aurora criado pelo serviço,
 * a V1 em rascunho. Quem planeja é um administrador.
 *
 * @param  list<string>  $months
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet}
 */
function periodResolutionPlannedOperation(array $months): array
{
    $planner = makeAdminUser();
    $operation = Operation::factory()->create(['status' => 'active']);

    return [
        'planner' => $planner,
        'operation' => $operation,
        'planSet' => periodResolutionPlan($planner, $operation, 'Residencial Aurora', $months),
    ];
}

/**
 * Plano de uma obra criado pelo serviço, com a V1 em rascunho: Fundo de Obra
 * de R$ 10.000.000,00 e 5% previstos por mês nas competências informadas.
 *
 * @param  list<string>  $months
 */
function periodResolutionPlan(User $planner, Operation $operation, string $name, array $months): MeasurementPlanSet
{
    $lines = [];

    foreach (array_values($months) as $index => $month) {
        $lines[] = [
            'sequence_number' => $index + 1,
            'planned_monthly_percent' => '5.00',
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal(500 * ($index + 1)),
            'measurement_date' => $month,
        ];
    }

    return app(MeasurementPlanVersionService::class)->createPlan($operation, $planner, [
        'name' => $name,
        'is_default' => ! $operation->planSets()->exists(),
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '10000000.00'], $lines);
}

function periodResolutionVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Põe em vigor, pelo serviço, o rascunho do plano, como quem acabou de abrir a
 * tela: a vigência é o mês do relógio.
 */
function periodResolutionActivateDraft(User $planner, MeasurementPlanSet $planSet): MeasurementPlanVersion
{
    $draft = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->draft()->sole();

    return app(MeasurementPlanVersionService::class)->activate($draft, $planner, (int) $draft->revision);
}

/**
 * Abre pelo serviço a revisão do plano -- o rascunho copiado da vigente, com o
 * passado igual -- e acrescenta ao fim do cronograma uma medição prevista de
 * 5% em cada mês informado.
 *
 * @param  array{planner: User, planSet: MeasurementPlanSet}  $history
 * @param  list<string>  $addedMonths
 */
function periodResolutionOpenRevision(array $history, array $addedMonths = []): MeasurementPlanVersion
{
    $service = app(MeasurementPlanVersionService::class);
    $draft = $service->createRevision($history['planSet'], $history['planner'], [
        'revision_category' => MeasurementPlanRevisionCategory::Schedule->value,
        'revision_reason' => 'Cronograma refeito com a construtora.',
    ]);

    if ($addedMonths === []) {
        return $draft;
    }

    $rows = MeasurementPlanLine::query()
        ->where('plan_version_id', $draft->id)
        ->orderBy('sequence_number')
        ->get()
        ->map(fn (MeasurementPlanLine $line): array => [
            'id' => (int) $line->id,
            'sequence_number' => (int) $line->sequence_number,
            'planned_monthly_percent' => $line->planned_monthly_percent,
            'planned_cumulative_percent' => $line->planned_cumulative_percent,
            'measurement_date' => $line->measurement_date->format('Y-m'),
        ])
        ->all();
    $sequence = (int) collect($rows)->max('sequence_number');

    foreach ($addedMonths as $month) {
        $rows[] = [
            'sequence_number' => ++$sequence,
            'planned_monthly_percent' => '5.00',
            'planned_cumulative_percent' => '0.00',
            'measurement_date' => $month,
        ];
    }

    return $service->updateDraft($draft, $history['planner'], [], $rows, (int) $draft->revision);
}

/**
 * A revisão aberta e ativada pelo serviço: vale a partir do mês do relógio.
 *
 * @param  array{planner: User, planSet: MeasurementPlanSet}  $history
 * @param  list<string>  $addedMonths
 */
function periodResolutionRevise(array $history, array $addedMonths = []): MeasurementPlanVersion
{
    $draft = periodResolutionOpenRevision($history, $addedMonths);

    return app(MeasurementPlanVersionService::class)->activate($draft, $history['planner'], (int) $draft->revision);
}

/**
 * Grava uma data de vigência no rascunho do plano. A coluna é preenchível e o
 * rascunho aceita a gravação; só a ativação, porém, põe a versão em vigor.
 *
 * @param  array{planSet: MeasurementPlanSet}  $history
 */
function periodResolutionWriteEffectiveFromOnDraft(array $history, string $date): MeasurementPlanVersion
{
    $draft = MeasurementPlanVersion::query()->where('plan_set_id', $history['planSet']->id)->draft()->sole();
    $draft->fill(['effective_from' => $date])->save();

    return $draft->fresh();
}

/**
 * Medição da competência ('Y-m') da operação, ainda sem arquivo, como o envio
 * a cria.
 *
 * @param  array{planner: User, operation: Operation}  $history
 */
function periodResolutionMeasurement(array $history, string $month): Measurement
{
    return Measurement::factory()->create([
        'operation_id' => $history['operation']->id,
        'reference_month' => "{$month}-01",
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $history['planner']->id,
    ]);
}

// ── Históricos montados pelo serviço ─────────────────────────────────────────

/**
 * V1 ativada em 04/01/2027 (vigência 01/2027), com 5% por mês de 01/2027 a
 * 12/2027; V2 ativada em 02/07/2027 (vigência 07/2027), que estende o
 * cronograma até 03/2028; V3 aberta em 10/08/2027, com 04/2028 a mais, e
 * deixada em rascunho.
 *
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet}
 */
function periodResolutionSuccessiveRevisions(): array
{
    periodResolutionTravelTo('2027-01-04');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-01', '2027-12'));
    periodResolutionActivateDraft($history['planner'], $history['planSet']);

    periodResolutionTravelTo('2027-07-02');
    periodResolutionRevise($history, periodResolutionMonths('2028-01', '2028-03'));

    periodResolutionTravelTo('2027-08-10');
    periodResolutionOpenRevision($history, ['2028-04']);

    return $history;
}

/**
 * V1 ativada em 10/04/2027 (vigência 04/2027) com o cronograma começando em
 * 02/2027: fevereiro e março são medições atrasadas, planejadas pela V1 antes
 * da vigência dela.
 *
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet}
 */
function periodResolutionLateFirstVersion(): array
{
    periodResolutionTravelTo('2027-04-10');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-02', '2027-09'));
    periodResolutionActivateDraft($history['planner'], $history['planSet']);

    return $history;
}

/**
 * Revisão ativada em 15/06/2027 (vigência 06/2027), com 10/2027 a mais.
 *
 * @param  array{planner: User, planSet: MeasurementPlanSet}  $history
 */
function periodResolutionReviseInJune(array $history): MeasurementPlanVersion
{
    periodResolutionTravelTo('2027-06-15');

    return periodResolutionRevise($history, ['2027-10']);
}

/**
 * V1 vigente desde 01/2027; V2 ativada em 05/07/2027 (com 01/2028 a mais) e V3
 * em 20/07/2027 (com 02/2028 a mais), as duas com vigência 07/2027. Entre as
 * duas ativações, em 10/07/2027, a operação recebe uma medição de julho, ainda
 * sem arquivo.
 *
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet, between: Measurement}
 */
function periodResolutionSameMonthRevisions(): array
{
    periodResolutionTravelTo('2027-01-04');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-01', '2027-12'));
    periodResolutionActivateDraft($history['planner'], $history['planSet']);

    periodResolutionTravelTo('2027-07-05');
    periodResolutionRevise($history, ['2028-01']);

    periodResolutionTravelTo('2027-07-10');
    $history['between'] = periodResolutionMeasurement($history, '2027-07');

    periodResolutionTravelTo('2027-07-20');
    periodResolutionRevise($history, ['2028-02']);

    return $history;
}

/**
 * V1 ativada em 05/04/2027 (vigência 04/2027) com o cronograma começando em
 * 02/2027, e substituída em 25/04/2027 pela V2 -- com 10/2027 a mais e
 * vigência 04/2027 também.
 *
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet}
 */
function periodResolutionFirstVersionReplacedInItsMonth(): array
{
    periodResolutionTravelTo('2027-04-05');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-02', '2027-09'));
    periodResolutionActivateDraft($history['planner'], $history['planSet']);

    periodResolutionTravelTo('2027-04-25');
    periodResolutionRevise($history, ['2027-10']);

    return $history;
}

/**
 * V1 vigente desde 01/2027; V2 aberta em 02/07/2027, com 01/2028 a mais e
 * uma data de vigência (01/07/2027) gravada enquanto era rascunho, e
 * cancelada em 10/07/2027.
 *
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet}
 */
function periodResolutionCancelledRevision(): array
{
    periodResolutionTravelTo('2027-01-04');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-01', '2027-12'));
    periodResolutionActivateDraft($history['planner'], $history['planSet']);

    periodResolutionTravelTo('2027-07-02');
    periodResolutionOpenRevision($history, ['2028-01']);
    $draft = periodResolutionWriteEffectiveFromOnDraft($history, '2027-07-01');

    periodResolutionTravelTo('2027-07-10');
    app(MeasurementPlanVersionService::class)->cancel($draft, $history['planner'], 'A construtora desistiu do replanejamento.', (int) $draft->revision);

    return $history;
}

/**
 * Revisão ativada em 03/09/2027 (vigência 09/2027), depois da cancelada.
 *
 * @param  array{planner: User, planSet: MeasurementPlanSet}  $history
 */
function periodResolutionReviseInSeptember(array $history): MeasurementPlanVersion
{
    periodResolutionTravelTo('2027-09-03');

    return periodResolutionRevise($history);
}

/**
 * Plano criado em 10/03/2027 com 5% por mês de 03/2027 a 08/2027 e a V1 ainda
 * em rascunho.
 *
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet}
 */
function periodResolutionDraftOnly(): array
{
    periodResolutionTravelTo('2027-03-10');

    return periodResolutionPlannedOperation(periodResolutionMonths('2027-03', '2027-08'));
}

/**
 * Residencial Aurora com a V1 vigente desde 01/2027, ativada antes de qualquer
 * medição da operação; a medição de junho, enviada em 20/06/2027; a Torre
 * Boreal, com a V1 ativada depois dela, em 25/06/2027 (vigência 06/2027); a V2
 * da Aurora, ativada em 02/07/2027 (vigência 07/2027); e a medição de julho,
 * enviada em 20/07/2027.
 *
 * @return array{planner: User, operation: Operation, planSet: MeasurementPlanSet, boreal: MeasurementPlanSet, june: Measurement, july: Measurement}
 */
function periodResolutionHistoryWithMeasurements(): array
{
    periodResolutionTravelTo('2027-01-04');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-01', '2027-12'));
    periodResolutionActivateDraft($history['planner'], $history['planSet']);

    periodResolutionTravelTo('2027-06-20');
    $history['june'] = periodResolutionMeasurement($history, '2027-06');

    periodResolutionTravelTo('2027-06-25');
    $history['boreal'] = periodResolutionPlan($history['planner'], $history['operation'], 'Torre Boreal', periodResolutionMonths('2027-06', '2027-12'));
    periodResolutionActivateDraft($history['planner'], $history['boreal']);

    periodResolutionTravelTo('2027-07-02');
    periodResolutionRevise($history);

    periodResolutionTravelTo('2027-07-20');
    $history['july'] = periodResolutionMeasurement($history, '2027-07');

    return $history;
}

/**
 * Monta o histórico do cenário pelo nome: o dataset passa o nome, e não uma
 * closure, para o teste receber o histórico montado.
 */
function periodResolutionBuild(string $scenario): void
{
    match ($scenario) {
        'successive revisions' => periodResolutionSuccessiveRevisions(),
        'late V1' => periodResolutionLateFirstVersion(),
        'late V1, revised' => periodResolutionReviseInJune(periodResolutionLateFirstVersion()),
        'same-month revisions' => periodResolutionSameMonthRevisions(),
        'V1 replaced in its own month' => periodResolutionFirstVersionReplacedInItsMonth(),
        'cancelled revision' => periodResolutionCancelledRevision(),
        'cancelled revision, then V3' => periodResolutionReviseInSeptember(periodResolutionCancelledRevision()),
        'V1 draft only' => periodResolutionDraftOnly(),
        'V1 draft only, with a vigência written' => periodResolutionWriteEffectiveFromOnDraft(periodResolutionDraftOnly(), '2027-03-01'),
        'measurements around the revision' => periodResolutionHistoryWithMeasurements(),
    };
}

// ── Leituras ─────────────────────────────────────────────────────────────────

/**
 * A versão que o resolvedor dá a cada competência: 'Y-m' => 'V{n}', ou `null`
 * quando nenhuma rege.
 *
 * @param  list<string>  $months
 * @return array<string, string|null>
 */
function periodResolutionResolved(MeasurementPlanSet $planSet, array $months, ?int $asOfMeasurementId = null): array
{
    $resolver = app(MeasurementPlanVersionResolver::class);
    $resolved = [];

    foreach ($months as $month) {
        $resolved[$month] = $resolver->forCompetence($planSet, $month, $asOfMeasurementId)?->label();
    }

    return $resolved;
}

/**
 * Cada competência com a versão do trecho em que cai: `$from` dá o primeiro
 * mês ('Y-m') de cada versão, em ordem; antes do primeiro trecho, nenhuma.
 *
 * @param  list<string>  $months
 * @param  array<string, string>  $from
 * @return array<string, string|null>
 */
function periodResolutionExpected(array $months, array $from): array
{
    $expected = [];

    foreach ($months as $month) {
        $expected[$month] = null;

        foreach ($from as $start => $label) {
            if ($month >= $start) {
                $expected[$month] = $label;
            }
        }
    }

    return $expected;
}

/**
 * As medições previstas que regem a própria competência, como a consulta das
 * telas as devolve: 'V{n} Y-m', em ordem de competência. `$competence` limita
 * ao mês dela.
 *
 * @return list<string>
 */
function periodResolutionGoverningLines(MeasurementPlanSet $planSet, ?int $asOfMeasurementId = null, DateTimeInterface|string|null $competence = null): array
{
    return MeasurementPlanLine::query()
        ->where('plan_set_id', $planSet->id)
        ->governingTheirCompetence($asOfMeasurementId)
        ->when($competence !== null, fn ($lines) => $lines->inCompetence($competence))
        ->with('version')
        ->get()
        ->sortBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->format('Y-m').'-'.str_pad((string) $line->sequence_number, 6, '0', STR_PAD_LEFT))
        ->map(fn (MeasurementPlanLine $line): string => $line->version->label().' '.$line->measurement_date->format('Y-m'))
        ->values()
        ->all();
}

/**
 * `governs()` diz, de cada versão do plano em cada competência, o mesmo que
 * `forCompetence()`: a versão rege o mês se e só se é a que o resolvedor dá.
 *
 * @param  list<string>  $months
 */
function periodResolutionAssertGovernsAgrees(MeasurementPlanSet $planSet, array $months): void
{
    $resolver = app(MeasurementPlanVersionResolver::class);
    $versions = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->orderBy('version_number')->get();

    foreach ($months as $month) {
        $governing = $resolver->forCompetence($planSet, $month)?->getKey();

        foreach ($versions as $version) {
            expect($resolver->governs($version, $month))
                ->toBe($governing !== null && (int) $governing === (int) $version->getKey(), "governs({$version->label()}, {$month})");
        }
    }
}

/**
 * O SQL das telas e da Engenharia diz o mesmo que o resolvedor: cada medição
 * prevista do plano, de qualquer versão, volta da consulta de linhas se e só
 * se a versão dela é a que o resolvedor dá ao mês dela; e a consulta de
 * versões devolve, em cada competência, no máximo uma versão -- a do
 * resolvedor. Devolve quantas linhas regem a própria competência.
 */
function periodResolutionAssertSqlAgrees(MeasurementPlanSet $planSet, ?int $asOfMeasurementId): int
{
    $resolver = app(MeasurementPlanVersionResolver::class);
    $context = "plano {$planSet->name}, histórico ".($asOfMeasurementId === null ? 'atual' : "no envio da medição #{$asOfMeasurementId}");
    $expected = MeasurementPlanLine::query()
        ->where('plan_set_id', $planSet->id)
        ->orderBy('id')
        ->get()
        ->filter(fn (MeasurementPlanLine $line): bool => $line->measurement_date !== null
            && (int) $line->plan_version_id === (int) $resolver->forCompetence($planSet, $line->measurement_date, $asOfMeasurementId)?->getKey())
        ->map(fn (MeasurementPlanLine $line): int => (int) $line->id)
        ->values()
        ->all();
    $governing = MeasurementPlanLine::query()
        ->where('plan_set_id', $planSet->id)
        ->governingTheirCompetence($asOfMeasurementId)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();

    expect($governing)->toBe($expected, "linhas que regem a própria competência ({$context})");

    foreach (periodResolutionMonths('2026-01', '2029-06') as $month) {
        $resolved = $resolver->forCompetence($planSet, $month, $asOfMeasurementId)?->getKey();
        $versionIds = MeasurementPlanVersion::query()
            ->where('plan_set_id', $planSet->id)
            ->governing($month, $asOfMeasurementId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        expect($versionIds)->toBe($resolved === null ? [] : [(int) $resolved], "versões que regem {$month} ({$context})");
    }

    return count($governing);
}

/**
 * Recusa do domínio como ela sai, com o contexto que vai para o log.
 */
function periodResolutionRefusal(callable $attempt): MeasurementWorkflowException
{
    try {
        $attempt();
    } catch (MeasurementWorkflowException $refusal) {
        return $refusal;
    }

    test()->fail('A gravação deveria ter sido recusada.');
}

// ── A versão que rege cada competência ───────────────────────────────────────

it('resolves each competence to the version whose vigência started last by then: V1 up to June, V2 from July on, never the open V3 draft', function () {
    $history = periodResolutionSuccessiveRevisions();
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $v1 = periodResolutionVersion($planSet, 1);
    $v2 = periodResolutionVersion($planSet, 2);
    $v3 = periodResolutionVersion($planSet, 3);

    // O histórico como a ativação real o gravou: cada vigência é o 1º dia do
    // mês da ativação.
    expect([$v1->status, $v1->effective_from?->toDateString()])->toBe([MeasurementPlanVersionStatus::Superseded, '2027-01-01'])
        ->and([$v2->status, $v2->effective_from?->toDateString()])->toBe([MeasurementPlanVersionStatus::Active, '2027-07-01'])
        ->and([$v3->status, $v3->effective_from])->toBe([MeasurementPlanVersionStatus::Draft, null]);

    // O primeiro mês da V1, o último antes da V2, o primeiro da V2 e os
    // seguintes, até depois do fim do cronograma. O plano vale também pelo id.
    expect($resolver->forCompetence($planSet, '2027-01')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence($planSet, '2027-06')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence($planSet, '2027-07')?->id)->toBe($v2->id)
        ->and($resolver->forCompetence($planSet, '2027-08')?->id)->toBe($v2->id)
        ->and($resolver->forCompetence($planSet, '2028-12')?->id)->toBe($v2->id)
        ->and($resolver->forCompetence((int) $planSet->id, '2027-06')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence((int) $planSet->id, '2027-07')?->id)->toBe($v2->id);

    // Mês a mês, de 2026 a 2029: a V1 até 06/2027 -- inclusive antes da
    // vigência dela, que só a V1 alcança --, a V2 de 07/2027 em diante. O
    // rascunho da V3, mesmo com o cronograma estendido até 04/2028, nunca.
    $months = periodResolutionMonths('2026-01', '2029-12');

    expect(periodResolutionResolved($planSet, $months))->toBe(periodResolutionExpected($months, ['2026-01' => 'V1', '2027-07' => 'V2']));

    periodResolutionAssertGovernsAgrees($planSet, $months);

    expect($resolver->governs($v1, '2027-06'))->toBeTrue()
        ->and($resolver->governs($v1, '2027-07'))->toBeFalse()
        ->and($resolver->governs($v2, '2027-06'))->toBeFalse()
        ->and($resolver->governs($v2, '2027-07'))->toBeTrue()
        ->and($resolver->governs($v3, '2028-04'))->toBeFalse()
        ->and($resolver->governs($v2, null))->toBeFalse();

    // Cada competência do cronograma pela versão que a rege: as cópias de
    // janeiro a junho que a V2 traz, os meses de julho em diante da V1 e o
    // rascunho inteiro ficam de fora.
    expect(periodResolutionGoverningLines($planSet))->toBe([
        ...array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-01', '2027-06')),
        ...array_map(fn (string $month): string => "V2 {$month}", periodResolutionMonths('2027-07', '2028-03')),
    ]);
});

it('resolves the competences planned before the first vigência to the V1, and keeps them there after a revision', function () {
    $history = periodResolutionLateFirstVersion();
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $v1 = periodResolutionVersion($planSet, 1);

    // A V1 ativada em 10/04/2027 vale desde 04/2027, mas o cronograma dela
    // começa em 02/2027: fevereiro e março são medições atrasadas que só ela
    // planeja. Um mês anterior a todo o cronograma também é dela.
    expect($v1->effective_from?->toDateString())->toBe('2027-04-01')
        ->and(periodResolutionResolved($planSet, ['2026-01', '2027-02', '2027-03', '2027-04', '2027-06']))->toBe([
            '2026-01' => 'V1',
            '2027-02' => 'V1',
            '2027-03' => 'V1',
            '2027-04' => 'V1',
            '2027-06' => 'V1',
        ])
        ->and($resolver->governs($v1, '2026-01'))->toBeTrue()
        ->and($resolver->governs($v1, '2027-02'))->toBeTrue()
        ->and(periodResolutionGoverningLines($planSet))->toBe(array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-02', '2027-09')));

    // A V2, ativada em 15/06/2027, vale desde 06/2027: março -- e todo mês
    // até maio -- continua da V1; junho em diante passa à V2.
    $v2 = periodResolutionReviseInJune($history);

    expect($v2->effective_from?->toDateString())->toBe('2027-06-01')
        ->and($resolver->forCompetence($planSet, '2027-03')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence($planSet, '2027-06')?->id)->toBe($v2->id)
        ->and(periodResolutionResolved($planSet, ['2026-01', '2027-02', '2027-03', '2027-05', '2027-06', '2027-07', '2028-12']))->toBe([
            '2026-01' => 'V1',
            '2027-02' => 'V1',
            '2027-03' => 'V1',
            '2027-05' => 'V1',
            '2027-06' => 'V2',
            '2027-07' => 'V2',
            '2028-12' => 'V2',
        ]);

    periodResolutionAssertGovernsAgrees($planSet, periodResolutionMonths('2026-01', '2028-12'));

    expect(periodResolutionGoverningLines($planSet))->toBe([
        ...array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-02', '2027-05')),
        ...array_map(fn (string $month): string => "V2 {$month}", periodResolutionMonths('2027-06', '2027-10')),
    ]);
});

it('gives no competence to a revision superseded in its own activation month, except as read by a measurement sent between the two activations', function () {
    $history = periodResolutionSameMonthRevisions();
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $v1 = periodResolutionVersion($planSet, 1);
    $v2 = periodResolutionVersion($planSet, 2);
    $v3 = periodResolutionVersion($planSet, 3);

    // V2 (05/07) e V3 (20/07) valem as duas desde 07/2027: a vigência da V2
    // termina antes de começar.
    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v2->superseded_by_version_id)->toBe($v3->id)
        ->and($v2->effective_from?->toDateString())->toBe('2027-07-01')
        ->and($v2->effectiveUntil()?->toDateString())->toBe('2027-06-30')
        ->and($v3->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v3->effective_from?->toDateString())->toBe('2027-07-01');

    expect($resolver->forCompetence($planSet, '2027-07')?->id)->toBe($v3->id)
        ->and($resolver->forCompetence($planSet, '2027-06')?->id)->toBe($v1->id);

    // De 01/2027 a 06/2028, nenhuma competência é da V2.
    $months = periodResolutionMonths('2027-01', '2028-06');

    expect(periodResolutionResolved($planSet, $months))->toBe(periodResolutionExpected($months, ['2027-01' => 'V1', '2027-07' => 'V3']))
        ->and(array_values(array_filter($months, fn (string $month): bool => $resolver->governs($v2, $month))))->toBe([]);

    periodResolutionAssertGovernsAgrees($planSet, periodResolutionMonths('2026-01', '2028-12'));

    // Nem as medições previstas da V2: julho em diante é da V3; antes, da V1.
    expect(periodResolutionGoverningLines($planSet))->toBe([
        ...array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-01', '2027-06')),
        ...array_map(fn (string $month): string => "V3 {$month}", periodResolutionMonths('2027-07', '2028-02')),
    ]);

    // Lido como estava no envio da medição de julho, entre as duas ativações,
    // julho era da V2: a V3 ainda não tinha sido ativada.
    $between = $history['between'];

    expect($v2->last_measurement_id_at_activation)->toBeNull()
        ->and($v3->last_measurement_id_at_activation)->toBe($between->id)
        ->and($resolver->forCompetence($planSet, '2027-07', $between->id)?->id)->toBe($v2->id)
        ->and($resolver->forCompetence($planSet, '2027-06', $between->id)?->id)->toBe($v1->id)
        ->and(periodResolutionGoverningLines($planSet, $between->id))->toBe([
            ...array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-01', '2027-06')),
            ...array_map(fn (string $month): string => "V2 {$month}", periodResolutionMonths('2027-07', '2028-01')),
        ]);
});

it('keeps the competences before the vigência with a V1 superseded in its own activation month', function () {
    $history = periodResolutionFirstVersionReplacedInItsMonth();
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $v1 = periodResolutionVersion($planSet, 1);
    $v2 = periodResolutionVersion($planSet, 2);

    // A V1, ativada em 05/04 com o cronograma desde 02/2027, foi substituída
    // pela V2 em 25/04: as duas valem desde 04/2027.
    expect($v1->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v1->effective_from?->toDateString())->toBe('2027-04-01')
        ->and($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->effective_from?->toDateString())->toBe('2027-04-01');

    // A V1 não rege abril, mas continua com o que vem antes da vigência dela:
    // a V2 não alcança competência anterior à própria vigência.
    expect($resolver->forCompetence($planSet, '2027-03')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence($planSet, '2027-04')?->id)->toBe($v2->id)
        ->and(periodResolutionResolved($planSet, ['2026-01', '2027-02', '2027-03', '2027-04', '2027-05', '2028-12']))->toBe([
            '2026-01' => 'V1',
            '2027-02' => 'V1',
            '2027-03' => 'V1',
            '2027-04' => 'V2',
            '2027-05' => 'V2',
            '2028-12' => 'V2',
        ])
        ->and($resolver->governs($v1, '2027-03'))->toBeTrue()
        ->and($resolver->governs($v1, '2027-04'))->toBeFalse()
        ->and($resolver->governs($v2, '2027-03'))->toBeFalse()
        ->and($resolver->governs($v2, '2027-04'))->toBeTrue();

    periodResolutionAssertGovernsAgrees($planSet, periodResolutionMonths('2026-01', '2028-12'));

    expect(periodResolutionGoverningLines($planSet))->toBe([
        'V1 2027-02',
        'V1 2027-03',
        ...array_map(fn (string $month): string => "V2 {$month}", periodResolutionMonths('2027-04', '2027-10')),
    ]);
});

it('ignores a cancelled revision: later competences stay with the version in force until the next activated revision', function () {
    $history = periodResolutionCancelledRevision();
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $v1 = periodResolutionVersion($planSet, 1);
    $v2 = periodResolutionVersion($planSet, 2);
    $months = periodResolutionMonths('2026-01', '2029-12');

    // A V2 cancelada nunca valeu -- nem com a data de vigência que o rascunho
    // chegou a ter: julho e os meses seguintes continuam da V1.
    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Cancelled)
        ->and($v2->effective_from?->toDateString())->toBe('2027-07-01')
        ->and($v1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($resolver->forCompetence($planSet, '2027-07')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence($planSet, '2028-01')?->id)->toBe($v1->id)
        ->and(periodResolutionResolved($planSet, $months))->toBe(array_fill_keys($months, 'V1'))
        ->and(array_values(array_filter($months, fn (string $month): bool => $resolver->governs($v2, $month))))->toBe([])
        ->and(periodResolutionGoverningLines($planSet))->toBe(array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-01', '2027-12')));

    // A revisão seguinte é a V3, ativada em 03/09/2027: setembro em diante é
    // dela, agosto continua da V1, e a V2 segue fora.
    $v3 = periodResolutionReviseInSeptember($history);

    expect($v3->version_number)->toBe(3)
        ->and($v3->effective_from?->toDateString())->toBe('2027-09-01')
        ->and($resolver->forCompetence($planSet, '2027-08')?->id)->toBe($v1->id)
        ->and($resolver->forCompetence($planSet, '2027-09')?->id)->toBe($v3->id)
        ->and(periodResolutionResolved($planSet, $months))->toBe(periodResolutionExpected($months, ['2026-01' => 'V1', '2027-09' => 'V3']));

    periodResolutionAssertGovernsAgrees($planSet, $months);

    expect(periodResolutionGoverningLines($planSet))->toBe([
        ...array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-01', '2027-08')),
        ...array_map(fn (string $month): string => "V3 {$month}", periodResolutionMonths('2027-09', '2027-12')),
    ]);
});

it('resolves no version while the plan only has the V1 draft', function () {
    $history = periodResolutionDraftOnly();
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $draft = periodResolutionVersion($planSet, 1);
    $months = periodResolutionMonths('2026-01', '2029-12');

    // O plano nunca entrou em vigor: nenhuma competência tem versão, nem as do
    // cronograma do rascunho.
    expect($draft->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and(periodResolutionResolved($planSet, $months))->toBe(array_fill_keys($months, null))
        ->and(array_values(array_filter($months, fn (string $month): bool => $resolver->governs($draft, $month))))->toBe([])
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->governing('2027-03')->exists())->toBeFalse()
        ->and(periodResolutionGoverningLines($planSet))->toBe([]);

    // Nem com a data de vigência gravada no rascunho: só a ativação põe a
    // versão em vigor.
    $draft = periodResolutionWriteEffectiveFromOnDraft($history, '2027-03-01');

    expect($draft->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($draft->effective_from?->toDateString())->toBe('2027-03-01')
        ->and(periodResolutionResolved($planSet, $months))->toBe(array_fill_keys($months, null))
        ->and(array_values(array_filter($months, fn (string $month): bool => $resolver->governs($draft, $month))))->toBe([])
        ->and(periodResolutionGoverningLines($planSet))->toBe([]);
});

it('reads the history as it was when a measurement was sent: only the versions activated before it count', function () {
    $history = periodResolutionHistoryWithMeasurements();
    $resolver = app(MeasurementPlanVersionResolver::class);
    $aurora = $history['planSet'];
    $boreal = $history['boreal'];
    $june = $history['june'];
    $july = $history['july'];
    $auroraV1 = periodResolutionVersion($aurora, 1);
    $auroraV2 = periodResolutionVersion($aurora, 2);
    $borealV1 = periodResolutionVersion($boreal, 1);

    // A ativação guarda a última medição da operação até ali: nenhuma na V1
    // da Aurora; a de junho na V2 dela e na V1 da Boreal.
    expect($auroraV1->last_measurement_id_at_activation)->toBeNull()
        ->and($auroraV2->last_measurement_id_at_activation)->toBe($june->id)
        ->and($borealV1->last_measurement_id_at_activation)->toBe($june->id)
        ->and($july->id)->toBeGreaterThan($june->id);

    // Hoje julho é da V2. No envio da medição de junho a V2 ainda não tinha
    // sido ativada: lido como estava naquele envio, julho era da V1. A medição
    // de julho, enviada depois da ativação, vê a V2.
    expect($resolver->forCompetence($aurora, '2027-07')?->id)->toBe($auroraV2->id)
        ->and($resolver->forCompetence($aurora, '2027-07', $june->id)?->id)->toBe($auroraV1->id)
        ->and($resolver->forCompetence($aurora, '2027-07', $july->id)?->id)->toBe($auroraV2->id)
        ->and($resolver->forCompetence($aurora, '2027-06', $june->id)?->id)->toBe($auroraV1->id)
        ->and($resolver->forCompetence($aurora, '2027-06', $july->id)?->id)->toBe($auroraV1->id)
        ->and(periodResolutionResolved($aurora, ['2026-12', '2027-06', '2027-07', '2028-12'], $june->id))->toBe([
            '2026-12' => 'V1',
            '2027-06' => 'V1',
            '2027-07' => 'V1',
            '2028-12' => 'V1',
        ]);

    // A Torre Boreal passou a valer depois do envio de junho: para aquela
    // medição o plano não regia competência nenhuma; para a de julho, já sim.
    expect(periodResolutionResolved($boreal, ['2027-05', '2027-06', '2027-07'], $june->id))->toBe(['2027-05' => null, '2027-06' => null, '2027-07' => null])
        ->and(periodResolutionResolved($boreal, ['2027-05', '2027-06', '2027-07'], $july->id))->toBe(['2027-05' => 'V1', '2027-06' => 'V1', '2027-07' => 'V1'])
        ->and(periodResolutionResolved($boreal, ['2027-05', '2027-06', '2027-07']))->toBe(['2027-05' => 'V1', '2027-06' => 'V1', '2027-07' => 'V1']);

    // O SQL das telas e da Engenharia lê o mesmo histórico, uma versão por
    // plano no máximo.
    $governingJulyAsOfJuly = [$auroraV2->id, $borealV1->id];
    sort($governingJulyAsOfJuly);

    expect(MeasurementPlanVersion::query()->governing('2027-07', $june->id)->orderBy('id')->pluck('id')->all())->toBe([$auroraV1->id])
        ->and(MeasurementPlanVersion::query()->governing('2027-07', $july->id)->orderBy('id')->pluck('id')->all())->toBe($governingJulyAsOfJuly)
        ->and(periodResolutionGoverningLines($aurora, $june->id))->toBe(array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-01', '2027-12')))
        ->and(periodResolutionGoverningLines($aurora, $july->id))->toBe([
            ...array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-01', '2027-06')),
            ...array_map(fn (string $month): string => "V2 {$month}", periodResolutionMonths('2027-07', '2027-12')),
        ])
        ->and(periodResolutionGoverningLines($boreal, $june->id))->toBe([])
        ->and(periodResolutionGoverningLines($boreal, $july->id))->toBe(array_map(fn (string $month): string => "V1 {$month}", periodResolutionMonths('2027-06', '2027-12')));

    // Os planos que preveem medição na competência, pela versão que a rege
    // hoje: maio só tem a Aurora, junho tem os dois, 2028 nenhum.
    expect($resolver->planSetsPlannedIn([$boreal->id, $aurora->id], '2027-05'))->toBe([$aurora->id])
        ->and($resolver->planSetsPlannedIn([$boreal->id, $aurora->id], '2027-06'))->toBe([$aurora->id, $boreal->id])
        ->and($resolver->planSetsPlannedIn([$aurora->id, $boreal->id], '2028-01'))->toBe([])
        ->and($resolver->planSetsPlannedIn([], '2027-06'))->toBe([]);
});

it('agrees in SQL with the resolver, line by line and month by month', function (string $scenario) {
    periodResolutionBuild($scenario);

    $asOfMeasurementIds = [null, ...Measurement::query()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()];
    $planSets = MeasurementPlanSet::query()->orderBy('id')->get();
    $governingLines = 0;

    foreach ($planSets as $planSet) {
        foreach ($asOfMeasurementIds as $asOfMeasurementId) {
            $governingLines += periodResolutionAssertSqlAgrees($planSet, $asOfMeasurementId);
        }
    }

    // Sem conferência vazia: há cronograma, e alguma linha rege a própria
    // competência sempre que alguma versão valeu.
    $hasBeenEffective = MeasurementPlanVersion::query()
        ->whereIn('status', [MeasurementPlanVersionStatus::Active->value, MeasurementPlanVersionStatus::Superseded->value])
        ->exists();

    expect($planSets)->not->toBeEmpty()
        ->and(MeasurementPlanLine::query()->count())->toBeGreaterThan(0)
        ->and($governingLines > 0)->toBe($hasBeenEffective);
})->with([
    'V1, V2 and the open V3 draft' => ['successive revisions'],
    'V1 with competences before its vigência' => ['late V1'],
    'V1 with competences before its vigência, then V2' => ['late V1, revised'],
    'V2 superseded by V3 in its own month' => ['same-month revisions'],
    'V1 superseded by V2 in its own month' => ['V1 replaced in its own month'],
    'cancelled V2' => ['cancelled revision'],
    'cancelled V2, then V3' => ['cancelled revision, then V3'],
    'only the V1 draft' => ['V1 draft only'],
    'only the V1 draft, with a vigência written on it' => ['V1 draft only, with a vigência written'],
    'two plans read as of each measurement' => ['measurements around the revision'],
]);

// ── Normalização da competência ──────────────────────────────────────────────

it('reads the competence by its civil date in any format or time of day, without converting time zones', function () {
    $history = periodResolutionSuccessiveRevisions();
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $versions = [
        '2027-06' => periodResolutionVersion($planSet, 1),
        '2027-07' => periodResolutionVersion($planSet, 2),
    ];
    $inputs = [
        '2027-06' => [
            '2027-06',
            '2027-06-01',
            '2027-06-30',
            '2027-06-01 00:00:00',
            '2027-06-15 08:30:00',
            '2027-06-30 23:59:59',
            CarbonImmutable::parse('2027-06-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2027-06-15 13:45:00', 'UTC'),
            CarbonImmutable::parse('2027-06-30 23:59:59', 'UTC'),
            // 22h30 de 30/06 em Brasília já é 01/07 em UTC: vale a data civil
            // informada, junho.
            CarbonImmutable::parse('2027-06-30 22:30:00', 'America/Sao_Paulo'),
            new DateTimeImmutable('2027-06-30 22:30:00', new DateTimeZone('America/Sao_Paulo')),
        ],
        '2027-07' => [
            '2027-07',
            '2027-07-01',
            '2027-07-01 00:00:00',
            '2027-07-31 23:59:59',
            CarbonImmutable::parse('2027-07-01 00:00:00', 'UTC'),
            // 01h de 01/07 em UTC ainda é 30/06 em Brasília: vale a data civil
            // informada, julho.
            CarbonImmutable::parse('2027-07-01 01:00:00', 'UTC'),
            CarbonImmutable::parse('2027-07-31 23:59:59', 'America/Sao_Paulo'),
        ],
    ];

    foreach ($inputs as $month => $competences) {
        $governing = $versions[$month];
        $other = $versions[$month === '2027-06' ? '2027-07' : '2027-06'];

        foreach ($competences as $competence) {
            $described = is_string($competence) ? "'{$competence}'" : $competence->format('Y-m-d H:i:s e');

            expect(MeasurementPlanVersionResolver::competenceStart($competence)->toDateTimeString())->toBe("{$month}-01 00:00:00", "início da competência de {$described}")
                ->and($resolver->forCompetence($planSet, $competence)?->id)->toBe($governing->id, "versão de {$described}")
                ->and($resolver->governs($governing, $competence))->toBeTrue("{$governing->label()} rege {$described}")
                ->and($resolver->governs($other, $competence))->toBeFalse("{$other->label()} não rege {$described}")
                ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->governing($competence)->pluck('id')->all())->toBe([$governing->id], "consulta de versões em {$described}")
                ->and(periodResolutionGoverningLines($planSet, competence: $competence))->toBe(["{$governing->label()} {$month}"], "medições previstas de {$described}");
        }
    }
});

// ── A vigência começa no 1º dia do mês ───────────────────────────────────────

it('refuses to put a version in force with a vigência that does not start on the first day of a month', function () {
    periodResolutionTravelTo('2027-07-15');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-07', '2027-12'));
    $planSet = $history['planSet'];
    $resolver = app(MeasurementPlanVersionResolver::class);
    $draft = periodResolutionVersion($planSet, 1);

    // Ativação gravada fora do serviço com a vigência no dia da ativação, e
    // não no 1º dia do mês: o resolvedor compara meses pelo dia 1º.
    $refusal = periodResolutionRefusal(fn () => $draft->forceFill([
        'status' => MeasurementPlanVersionStatus::Active,
        'effective_from' => '2027-07-15',
        'activated_at' => now(),
        'activated_by' => $history['planner']->id,
    ])->save());

    expect($refusal->getMessage())->toBe('A vigência de uma versão do plano começa no primeiro dia de um mês.')
        ->and($refusal->context())->toBe(['plan_version_id' => $draft->id, 'effective_from' => '2027-07-15'])
        ->and($draft->fresh()->only(['status', 'effective_from', 'activated_at']))->toBe([
            'status' => MeasurementPlanVersionStatus::Draft,
            'effective_from' => null,
            'activated_at' => null,
        ])
        ->and($resolver->forCompetence($planSet, '2027-07'))->toBeNull();

    // A mesma ativação com a vigência no 1º dia do mês é aceita: a recusa é do
    // dia, não da ativação.
    $draft = $draft->fresh();
    $draft->forceFill([
        'status' => MeasurementPlanVersionStatus::Active,
        'effective_from' => '2027-07-01',
        'activated_at' => now(),
        'activated_by' => $history['planner']->id,
    ])->save();

    expect($draft->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($draft->fresh()->effective_from?->toDateString())->toBe('2027-07-01')
        ->and($resolver->forCompetence($planSet, '2027-07')?->id)->toBe($draft->id);
});

it('refuses a version created already in force, outside the service, with a vigência that does not start on the first day of a month', function () {
    periodResolutionTravelTo('2027-07-15');
    $history = periodResolutionPlannedOperation(periodResolutionMonths('2027-07', '2027-12'));
    $planSet = $history['planSet'];

    $version = new MeasurementPlanVersion([
        'plan_set_id' => $planSet->id,
        'operation_id' => $planSet->operation_id,
        'version_number' => 2,
        'effective_from' => '2027-07-15',
    ]);
    $version->forceFill(['status' => MeasurementPlanVersionStatus::Superseded, 'activated_at' => now()]);

    $refusal = periodResolutionRefusal(fn () => $version->save());

    expect($refusal->getMessage())->toBe('A vigência de uma versão do plano começa no primeiro dia de um mês.')
        ->and(MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->count())->toBe(1);
});
