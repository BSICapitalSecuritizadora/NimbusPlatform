<?php

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementPlanVersionComparison;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\OperationStatus;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // As medições destes cenários guardam o arquivo no disco privado falso.
    config()->set('filesystems.private_disk', 'local');
    // A projeção do rascunho e a vigência seguem o mês do calendário de
    // negócio: o fuso fixo deixa as fronteiras de mês independentes do ambiente.
    config()->set('measurements.business_timezone', 'America/Sao_Paulo');
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a competência em que uma
 * ativação valeria -- e a partir da qual o rascunho é projetado -- é o mês
 * desse dia no calendário de negócio.
 */
function planVersionComparisonTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * Operação em andamento e quem a replaneja: participa de todas as etapas (as
 * medições destes cenários passam pelo fluxo real) e pode alterar a operação
 * -- a mesma porta que o serviço confere antes de escrever no plano.
 *
 * @return array{actor: User, operation: Operation}
 */
function planVersionComparisonOperation(): array
{
    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo([
        'operations.view', 'operations.update',
        'measurements.view', 'measurements.create', 'measurements.update', 'measurements.review',
        'measurements.pay', 'measurements.receipts', 'measurements.finalize',
    ]);

    $operation = Operation::factory()->create([
        'status' => OperationStatus::Active,
        'assigned_user_id' => $actor->id,
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);

    return ['actor' => $actor, 'operation' => $operation];
}

/**
 * Uma medição prevista por mês, todas com o mesmo previsto mensal.
 *
 * @return array<string, string> previsto mensal por competência ('Y-m')
 */
function planVersionComparisonMonthly(string $firstMonth, int $count, string $percent): array
{
    $first = CarbonImmutable::parse($firstMonth.'-01');
    $months = [];

    for ($index = 0; $index < $count; $index++) {
        $months[$first->addMonthsNoOverflow($index)->format('Y-m')] = $percent;
    }

    return $months;
}

/**
 * Plano criado pela porta de escrita, com a V1 em rascunho e uma medição
 * prevista por competência, na ordem das sequências. O acumulado vai digitado
 * como soma corrida desde zero: é a ativação (e a comparação do rascunho) que o
 * refaz a partir do avanço físico.
 *
 * @param  array<string, string>  $monthlyByMonth  previsto mensal por competência ('Y-m')
 */
function planVersionComparisonPlan(
    Operation $operation,
    User $actor,
    array $monthlyByMonth,
    ?string $fund = '1000000.00',
    string $initialPercent = '0.00',
    ?string $referenceDate = null,
): MeasurementPlanSet {
    $lines = [];
    $running = 0;
    $sequence = 0;

    foreach ($monthlyByMonth as $month => $monthly) {
        $running += (int) MeasurementPhysicalProgress::basisPoints($monthly);
        $lines[] = [
            'sequence_number' => ++$sequence,
            'planned_monthly_percent' => $monthly,
            'planned_cumulative_percent' => MeasurementPhysicalProgress::decimal($running),
            'measurement_date' => $month,
        ];
    }

    return app(MeasurementPlanVersionService::class)->createPlan($operation, $actor, [
        'name' => 'Residencial Aurora',
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => $initialPercent,
        'initial_physical_progress_reference_date' => $referenceDate,
    ], ['construction_fund_amount' => $fund], $lines);
}

function planVersionComparisonVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Ativa pelo serviço o rascunho como a pessoa o viu: com o contador atual.
 */
function planVersionComparisonActivate(MeasurementPlanVersion $draft, User $actor): MeasurementPlanVersion
{
    $seen = $draft->fresh();

    return app(MeasurementPlanVersionService::class)->activate($seen, $actor, (int) $seen->revision);
}

/**
 * Abre a revisão sobre a versão vigente que a pessoa viu, com a justificativa
 * que a ativação exige.
 */
function planVersionComparisonRevision(MeasurementPlanSet $planSet, User $actor, MeasurementPlanRevisionCategory $category = MeasurementPlanRevisionCategory::Schedule): MeasurementPlanVersion
{
    $active = MeasurementPlanVersion::query()->where('plan_set_id', $planSet->id)->active()->sole();

    return app(MeasurementPlanVersionService::class)->createRevision($planSet, $actor, [
        'revision_category' => $category->value,
        'revision_reason' => 'Replanejamento aprovado pelo comitê de obras.',
    ], (int) $active->id);
}

/**
 * Grava o rascunho -- dados e, quando informado, o cronograma inteiro -- com o
 * contador atual: quem edita acabou de abrir a tela.
 *
 * @param  array<string, mixed>  $data
 * @param  list<array<string, mixed>>|null  $rows
 */
function planVersionComparisonSaveDraft(MeasurementPlanVersion $draft, User $actor, array $data, ?array $rows = null): MeasurementPlanVersion
{
    $seen = $draft->fresh();

    return app(MeasurementPlanVersionService::class)->updateDraft($seen, $actor, $data, $rows, (int) $seen->revision);
}

/**
 * @return array<string, MeasurementPlanLine> linhas da versão por competência ('Y-m')
 */
function planVersionComparisonLines(MeasurementPlanVersion $version): array
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
 * O cronograma do rascunho como o formulário o devolve, cada linha com o id,
 * indexado pela competência para o teste mexer em uma delas.
 *
 * @return array<string, array{id: int, sequence_number: int, planned_monthly_percent: string, planned_cumulative_percent: string, measurement_date: string}>
 */
function planVersionComparisonDraftRows(MeasurementPlanVersion $draft): array
{
    return array_map(fn (MeasurementPlanLine $line): array => [
        'id' => (int) $line->id,
        'sequence_number' => (int) $line->sequence_number,
        'planned_monthly_percent' => $line->planned_monthly_percent,
        'planned_cumulative_percent' => $line->planned_cumulative_percent,
        'measurement_date' => $line->measurement_date->format('Y-m'),
    ], planVersionComparisonLines($draft));
}

/**
 * A comparação como a tela a pede, com as duas versões relidas do banco: a
 * situação e o retrato da ativação são os gravados, não os da instância que o
 * teste tem na mão.
 */
function planVersionComparisonCompare(?MeasurementPlanVersion $base, MeasurementPlanVersion $candidate): MeasurementPlanVersionComparison
{
    return app(MeasurementPlanVersionService::class)->compare($base?->fresh(), $candidate->fresh());
}

/**
 * O cenário que {@see Scenario} espera, com as linhas da versão por
 * competência: a medição que ele envia captura essa versão.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet}  $context
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}
 */
function planVersionComparisonUnder(array $context, MeasurementPlanVersion $version): array
{
    return [
        'actor' => $context['actor'],
        'operation' => $context['operation'],
        'planSet' => $context['planSet'],
        'lines' => planVersionComparisonLines($version),
    ];
}

/**
 * O custo previsto como a trilha da ativação da versão o registrou.
 *
 * @return array{construction_fund_amount: string|null, previous_construction_fund_amount: string|null, construction_fund_variation_amount: string|null, construction_fund_variation_percent: string|null}
 */
function planVersionComparisonFundTrail(MeasurementPlanVersion $version): array
{
    $activation = Activity::query()
        ->where('log_name', 'measurements')
        ->where('event', 'plan_version_activated')
        ->where('subject_type', $version->getMorphClass())
        ->where('subject_id', $version->id)
        ->sole();

    return [
        'construction_fund_amount' => $activation->properties['construction_fund_amount'],
        'previous_construction_fund_amount' => $activation->properties['previous_construction_fund_amount'],
        'construction_fund_variation_amount' => $activation->properties['construction_fund_variation_amount'],
        'construction_fund_variation_percent' => $activation->properties['construction_fund_variation_percent'],
    ];
}

/**
 * O custo previsto como a comparação o entrega para a tela.
 *
 * @return array<string, mixed>
 */
function planVersionComparisonFundView(MeasurementPlanVersionComparison $comparison): array
{
    return Arr::only($comparison->toArray(), [
        'base_construction_fund_amount',
        'candidate_construction_fund_amount',
        'construction_fund_variation_amount',
        'construction_fund_variation_percent',
    ]);
}

/**
 * As linhas alteradas, pela competência na versão candidata: o acumulado
 * previsto antes e depois e a lista de diferenças.
 *
 * @return array<string, array{0: string, 1: string, 2: list<string>}>
 */
function planVersionComparisonCumulativeChanges(MeasurementPlanVersionComparison $comparison): array
{
    $changes = [];

    foreach ($comparison->changedLines as $line) {
        $changes[substr((string) $line['after']['measurement_date'], 0, 7)] = [
            $line['before']['planned_cumulative_percent'],
            $line['after']['planned_cumulative_percent'],
            $line['changes'],
        ];
    }

    return $changes;
}

/**
 * Versões, linhas e trilha do plano como estão no banco: comparar é leitura e
 * não pode mudar nada.
 *
 * @return array{versions: list<array<string, mixed>>, lines: list<array<string, mixed>>, activities: int}
 */
function planVersionComparisonFootprint(MeasurementPlanSet $planSet): array
{
    $rows = fn (string $table): array => DB::table($table)
        ->where('plan_set_id', $planSet->id)
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return [
        'versions' => $rows('measurement_plan_versions'),
        'lines' => $rows('measurement_plan_lines'),
        'activities' => DB::table('activity_log')->count(),
    ];
}

/**
 * Revisão de custo: a V1 com o Fundo de Obra de base, vigente desde 01/2027
 * (ativada em 10/01/2027), e a V2 aberta em 10/02/2027 com o fundo candidato.
 * O cronograma (10% ao mês de 03/2027 a 08/2027) começa depois das duas
 * vigências e não tem medição: a projeção do rascunho refaz o acumulado da V1,
 * e o custo é a única diferença entre as versões.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, draft: MeasurementPlanVersion}
 */
function planVersionComparisonFundRevision(?string $baseFund, ?string $candidateFund): array
{
    planVersionComparisonTravelTo('2027-01-10');
    ['actor' => $actor, 'operation' => $operation] = planVersionComparisonOperation();
    $planSet = planVersionComparisonPlan($operation, $actor, planVersionComparisonMonthly('2027-03', 6, '10.00'), fund: $baseFund);
    $v1 = planVersionComparisonActivate(planVersionComparisonVersion($planSet, 1), $actor);

    planVersionComparisonTravelTo('2027-02-10');
    $draft = planVersionComparisonSaveDraft(
        planVersionComparisonRevision($planSet, $actor, MeasurementPlanRevisionCategory::Cost),
        $actor,
        ['construction_fund_amount' => $candidateFund],
    );

    return compact('actor', 'operation', 'planSet', 'v1', 'draft');
}

/**
 * V1 vigente desde 01/2027 com 10% previstos de 03/2027 a 06/2027 e uma
 * medição prevista de 07/2027 ainda em 0% (gerada para preencher depois), e a
 * revisão aberta em 10/02/2027.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, draft: MeasurementPlanVersion}
 */
function planVersionComparisonCompletionPlan(): array
{
    planVersionComparisonTravelTo('2027-01-10');
    ['actor' => $actor, 'operation' => $operation] = planVersionComparisonOperation();
    $planSet = planVersionComparisonPlan($operation, $actor, [
        '2027-03' => '10.00',
        '2027-04' => '10.00',
        '2027-05' => '10.00',
        '2027-06' => '10.00',
        '2027-07' => '0.00',
    ]);
    $v1 = planVersionComparisonActivate(planVersionComparisonVersion($planSet, 1), $actor);

    planVersionComparisonTravelTo('2027-02-10');
    $draft = planVersionComparisonRevision($planSet, $actor);

    return compact('actor', 'operation', 'planSet', 'v1', 'draft');
}

/**
 * V1 vigente desde 01/2027 com 10% ao mês de 03/2027 a 07/2027, e a revisão
 * aberta em 10/02/2027. Sem medição e com o cronograma começando depois das
 * duas vigências, a projeção do rascunho refaz o acumulado da V1: a comparação
 * mostra só o que a revisão mexeu.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, draft: MeasurementPlanVersion}
 */
function planVersionComparisonScheduleRevision(): array
{
    planVersionComparisonTravelTo('2027-01-10');
    ['actor' => $actor, 'operation' => $operation] = planVersionComparisonOperation();
    $planSet = planVersionComparisonPlan($operation, $actor, planVersionComparisonMonthly('2027-03', 5, '10.00'));
    $v1 = planVersionComparisonActivate(planVersionComparisonVersion($planSet, 1), $actor);

    planVersionComparisonTravelTo('2027-02-10');
    $draft = planVersionComparisonRevision($planSet, $actor);

    return compact('actor', 'operation', 'planSet', 'v1', 'draft');
}

/**
 * Obra que entrou com 30% executados (referência 31/12/2026), V1 vigente desde
 * 01/2027 com 10% ao mês de 01/2027 a 06/2027 -- acumulado gravado de 40% a
 * 90% -- e 20% medidos em fevereiro pela Engenharia no fluxo real: avanço
 * físico atual de 50%. O relógio fica em 06/04/2027.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion}
 */
function planVersionComparisonHalfBuiltPlan(): array
{
    planVersionComparisonTravelTo('2027-01-10');
    ['actor' => $actor, 'operation' => $operation] = planVersionComparisonOperation();
    $planSet = planVersionComparisonPlan(
        $operation,
        $actor,
        planVersionComparisonMonthly('2027-01', 6, '10.00'),
        initialPercent: '30.00',
        referenceDate: '2026-12-31',
    );
    $v1 = planVersionComparisonActivate(planVersionComparisonVersion($planSet, 1), $actor);
    $context = compact('actor', 'operation', 'planSet', 'v1');

    planVersionComparisonTravelTo('2027-03-05');
    Scenario::measured(planVersionComparisonUnder($context, $v1), '2027-02', 20);

    planVersionComparisonTravelTo('2027-04-06');

    return $context;
}

// ── Custo previsto ───────────────────────────────────────────────────────────

it('compares the construction fund of a cost revision in cents and basis points, as the activation trail records it', function (string $candidateFund, int $variationCents, int $variationBasisPoints, string $variationAmount, string $variationPercent) {
    ['actor' => $actor, 'v1' => $v1, 'draft' => $draft] = planVersionComparisonFundRevision('20000000.00', $candidateFund);

    $comparison = planVersionComparisonCompare($v1, $draft);
    $expected = [
        'base_version_id' => $v1->id,
        'base_version_number' => 1,
        'candidate_version_id' => $draft->id,
        'candidate_version_number' => 2,
        'base_effective_from' => '2027-01-01',
        'candidate_effective_from' => null,
        'base_construction_fund_amount' => '20000000.00',
        'candidate_construction_fund_amount' => $candidateFund,
        'construction_fund_variation_amount' => $variationAmount,
        'construction_fund_variation_percent' => $variationPercent,
        'base_completion' => '2027-08-31',
        'candidate_completion' => '2027-08-31',
        'completion_variation_days' => 0,
        'current_physical_progress_percent' => '0.00',
        'remaining_physical_progress_percent' => '100.00',
        'base_final_planned_cumulative_percent' => '60.00',
        'candidate_final_planned_cumulative_percent' => '60.00',
        'added_lines' => [],
        'removed_lines' => [],
        'changed_lines' => [],
        'unchanged_lines' => 6,
        'open_measurement_ids_on_base' => [],
        'pending_before_effective_percent' => '0.00',
    ];

    // O percentual sai em basis points (15% = 1500) e a tela o recebe como
    // decimal de duas casas. O cronograma não mudou: a comparação inteira diz
    // que a revisão é só de custo -- mesmo término, mesmo acumulado final, e
    // nenhum previsto anterior a 02/2027 ficou por medir.
    expect($comparison->fundVariationCents())->toBe($variationCents)
        ->and($comparison->fundVariationBasisPoints())->toBe($variationBasisPoints)
        ->and($comparison->hasLineChanges())->toBeFalse()
        ->and($comparison->toArray())->toBe($expected);

    // Ativada, a trilha guarda a mesma variação que a tela mostrou, e a versão
    // já vigente compara igual, agora com a vigência -- e sem o pendente, que
    // só existe para o rascunho.
    $v2 = planVersionComparisonActivate($draft, $actor);
    $activated = planVersionComparisonCompare($v1, $v2);

    expect(planVersionComparisonFundTrail($v2))->toBe([
        'construction_fund_amount' => $candidateFund,
        'previous_construction_fund_amount' => '20000000.00',
        'construction_fund_variation_amount' => $variationAmount,
        'construction_fund_variation_percent' => $variationPercent,
    ])
        ->and($activated->fundVariationCents())->toBe($variationCents)
        ->and($activated->fundVariationBasisPoints())->toBe($variationBasisPoints)
        ->and($activated->toArray())->toBe([...$expected, 'candidate_effective_from' => '2027-02-01', 'pending_before_effective_percent' => null]);
})->with([
    'cost overrun of 15%: R$ 20 mi to R$ 23 mi' => ['23000000.00', 300_000_000, 1_500, '3000000.00', '15.00'],
    'cost cut of 10%: R$ 20 mi to R$ 18 mi' => ['18000000.00', -200_000_000, -1_000, '-2000000.00', '-10.00'],
]);

it('computes the cost variation without float, rounding the basis points half away from zero', function (string $baseFund, string $candidateFund, int $variationCents, int $variationBasisPoints, string $variationAmount, string $variationPercent) {
    ['actor' => $actor, 'v1' => $v1, 'draft' => $draft] = planVersionComparisonFundRevision($baseFund, $candidateFund);

    $comparison = planVersionComparisonCompare($v1, $draft);

    expect($comparison->fundVariationCents())->toBe($variationCents)
        ->and($comparison->fundVariationBasisPoints())->toBe($variationBasisPoints)
        ->and(planVersionComparisonFundView($comparison))->toBe([
            'base_construction_fund_amount' => $baseFund,
            'candidate_construction_fund_amount' => $candidateFund,
            'construction_fund_variation_amount' => $variationAmount,
            'construction_fund_variation_percent' => $variationPercent,
        ]);

    // A trilha da ativação faz a mesma conta exata: o que a tela mostrou é o
    // que fica registrado.
    $v2 = planVersionComparisonActivate($draft, $actor);

    expect(planVersionComparisonFundTrail($v2))->toBe([
        'construction_fund_amount' => $candidateFund,
        'previous_construction_fund_amount' => $baseFund,
        'construction_fund_variation_amount' => $variationAmount,
        'construction_fund_variation_percent' => $variationPercent,
    ]);
})->with([
    // 1.011.480.161 - 1.011.480.160 = 1 centavo, e 1 × 10.000 / 1.011.480.160 =
    // 0,0000099 bp, que arredonda para 0. Em float, 10114801.61 - 10114801.60
    // dá 0,0099999997...: truncado em centavos, o centavo sumiria.
    'one cent over R$ 10.114.801,60' => ['10114801.60', '10114801.61', 1, 0, '0.01', '0.00'],
    // R$ 100,00 sobre R$ 2 mi: 10.000 × 10.000 / 200.000.000 = 0,5 bp exato.
    // Meio para longe do zero dá ±1; truncar ou arredondar para o par daria 0.
    'half a basis point up' => ['2000000.00', '2000100.00', 10_000, 1, '100.00', '0.01'],
    'half a basis point down' => ['2000000.00', '1999900.00', -10_000, -1, '-100.00', '-0.01'],
    // R$ 1 mi sobre R$ 3 mi: 100.000.000 × 10.000 / 300.000.000 = 3.333,33...
    // bp, dízima que fica em 3.333; dois terços (6.666,67) sobem para 6.667.
    'one third, a repeating decimal' => ['3000000.00', '4000000.00', 100_000_000, 3_333, '1000000.00', '33.33'],
    'two thirds' => ['3000000.00', '5000000.00', 200_000_000, 6_667, '2000000.00', '66.67'],
    'minus one third' => ['3000000.00', '2000000.00', -100_000_000, -3_333, '-1000000.00', '-33.33'],
]);

it('has no variation over a base version without fund and no percent over a zero fund', function (?string $baseFund, ?int $baseCents, ?int $variationCents, ?string $variationAmount) {
    ['actor' => $actor, 'v1' => $v1, 'draft' => $draft] = planVersionComparisonFundRevision($baseFund, '500000.00');

    $comparison = planVersionComparisonCompare($v1, $draft);

    // Sem fundo na V1 não há de onde medir a variação; de zero para R$ 500
    // mil há variação em reais, mas nenhum percentual -- nada de divisão por
    // zero virando número.
    expect($comparison->baseFundCents)->toBe($baseCents)
        ->and($comparison->candidateFundCents)->toBe(50_000_000)
        ->and($comparison->fundVariationCents())->toBe($variationCents)
        ->and($comparison->fundVariationBasisPoints())->toBeNull()
        ->and(planVersionComparisonFundView($comparison))->toBe([
            'base_construction_fund_amount' => $baseFund,
            'candidate_construction_fund_amount' => '500000.00',
            'construction_fund_variation_amount' => $variationAmount,
            'construction_fund_variation_percent' => null,
        ]);

    $v2 = planVersionComparisonActivate($draft, $actor);

    expect(planVersionComparisonFundTrail($v2))->toBe([
        'construction_fund_amount' => '500000.00',
        'previous_construction_fund_amount' => $baseFund,
        'construction_fund_variation_amount' => $variationAmount,
        'construction_fund_variation_percent' => null,
    ]);
})->with([
    'V1 without fund' => [null, null, null, null],
    'V1 with a zero fund' => ['0.00', 0, 50_000_000, '500000.00'],
]);

it('has no cost variation for a revision draft that left the fund blank', function () {
    ['v1' => $v1, 'draft' => $draft] = planVersionComparisonFundRevision('1000000.00', null);

    $comparison = planVersionComparisonCompare($v1, $draft);

    // A ativação recusa esse rascunho (o custo previsto não fica em branco);
    // até lá a tela mostra a ausência, e não uma queda de 100%.
    expect($draft->construction_fund_amount)->toBeNull()
        ->and($comparison->baseFundCents)->toBe(100_000_000)
        ->and($comparison->candidateFundCents)->toBeNull()
        ->and($comparison->fundVariationCents())->toBeNull()
        ->and($comparison->fundVariationBasisPoints())->toBeNull()
        ->and(planVersionComparisonFundView($comparison))->toBe([
            'base_construction_fund_amount' => '1000000.00',
            'candidate_construction_fund_amount' => null,
            'construction_fund_variation_amount' => null,
            'construction_fund_variation_percent' => null,
        ]);
});

// ── Término previsto ─────────────────────────────────────────────────────────

it('measures the completion at the end of the last month with planned advance, positive when the schedule slips', function () {
    ['actor' => $actor, 'v1' => $v1, 'draft' => $draft] = planVersionComparisonCompletionPlan();
    $rows = planVersionComparisonDraftRows($draft);
    $draft = planVersionComparisonSaveDraft($draft, $actor, [], [
        ...array_values(Arr::except($rows, '2027-07')),
        ['planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '50.00'] + $rows['2027-07'],
        ['sequence_number' => 6, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '60.00', 'measurement_date' => '2027-08'],
        ['sequence_number' => 7, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '70.00', 'measurement_date' => '2027-09'],
    ]);

    $comparison = planVersionComparisonCompare($v1, $draft);
    $completion = ['base_completion', 'candidate_completion', 'completion_variation_days'];

    // A V1 termina em junho: julho está no cronograma, mas com 0% previsto, e
    // não é término. A V2 vai até setembro: 31 + 31 + 30 = 92 dias de atraso.
    expect($comparison->baseCompletion->toDateString())->toBe('2027-06-30')
        ->and($comparison->candidateCompletion->toDateString())->toBe('2027-09-30')
        ->and($comparison->completionVariationDays())->toBe(92)
        ->and(Arr::only($comparison->toArray(), $completion))->toBe([
            'base_completion' => '2027-06-30',
            'candidate_completion' => '2027-09-30',
            'completion_variation_days' => 92,
        ]);

    // Vigente, a V2 compara igual: o término sai do cronograma gravado.
    $v2 = planVersionComparisonActivate($draft, $actor);

    expect(Arr::only(planVersionComparisonCompare($v1, $v2)->toArray(), $completion))->toBe([
        'base_completion' => '2027-06-30',
        'candidate_completion' => '2027-09-30',
        'completion_variation_days' => 92,
    ]);
});

it('gives negative days when the revision pulls the completion earlier, ignoring the months left at 0% at the end', function () {
    ['actor' => $actor, 'v1' => $v1, 'draft' => $draft] = planVersionComparisonCompletionPlan();
    $rows = planVersionComparisonDraftRows($draft);
    $draft = planVersionComparisonSaveDraft($draft, $actor, [], [
        ['planned_monthly_percent' => '20.00', 'planned_cumulative_percent' => '20.00'] + $rows['2027-03'],
        ['planned_monthly_percent' => '20.00', 'planned_cumulative_percent' => '40.00'] + $rows['2027-04'],
        ['planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '40.00'] + $rows['2027-05'],
        ['planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '40.00'] + $rows['2027-06'],
        $rows['2027-07'],
    ]);
    $draft = app(MeasurementPlanVersionService::class)->addDraftLines($draft, $actor, 2, '2027-08', (int) $draft->revision);

    $comparison = planVersionComparisonCompare($v1, $draft);

    // A construtora concentra os 40% em março e abril. Maio a setembro
    // continuam no cronograma, com 0% previsto, e não adiam o término:
    // 31/05 + 30/06 = 61 dias de antecipação.
    expect(array_keys(planVersionComparisonLines($draft)))->toBe(['2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09'])
        ->and($comparison->baseCompletion->toDateString())->toBe('2027-06-30')
        ->and($comparison->candidateCompletion->toDateString())->toBe('2027-04-30')
        ->and($comparison->completionVariationDays())->toBe(-61)
        ->and(Arr::only($comparison->toArray(), ['base_completion', 'candidate_completion', 'completion_variation_days']))->toBe([
            'base_completion' => '2027-06-30',
            'candidate_completion' => '2027-04-30',
            'completion_variation_days' => -61,
        ]);
});

it('compares the V1 with nothing before it, the completion falling back to the last dated planned measurement', function () {
    planVersionComparisonTravelTo('2027-01-10');
    ['actor' => $actor, 'operation' => $operation] = planVersionComparisonOperation();
    $planSet = app(MeasurementPlanVersionService::class)->createPlan($operation, $actor, [
        'name' => 'Residencial Aurora',
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '1000000.00'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '0.00', 'measurement_date' => '2027-01'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '0.00', 'measurement_date' => '2027-02'],
        ['sequence_number' => 3, 'planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '0.00', 'measurement_date' => '2027-03'],
        ['sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00'],
    ]);
    $draft = planVersionComparisonVersion($planSet, 1);

    $comparison = planVersionComparisonCompare(null, $draft);

    // Rascunho em preenchimento: os meses gerados ainda sem previsto e um
    // previsto digitado sem mês. Sem avanço planejado em mês nenhum, o término
    // é o da última medição prevista com mês (março); a linha sem mês nunca é
    // término. A V1 não tem versão anterior: tudo é acréscimo, e não há
    // variação de custo nem de prazo.
    expect($comparison->base)->toBeNull()
        ->and($comparison->completionVariationDays())->toBeNull()
        ->and($comparison->hasLineChanges())->toBeTrue()
        ->and($comparison->toArray())->toBe([
            'base_version_id' => null,
            'base_version_number' => null,
            'candidate_version_id' => $draft->id,
            'candidate_version_number' => 1,
            'base_effective_from' => null,
            'candidate_effective_from' => null,
            'base_construction_fund_amount' => null,
            'candidate_construction_fund_amount' => '1000000.00',
            'construction_fund_variation_amount' => null,
            'construction_fund_variation_percent' => null,
            'base_completion' => null,
            'candidate_completion' => '2027-03-31',
            'completion_variation_days' => null,
            'current_physical_progress_percent' => '0.00',
            'remaining_physical_progress_percent' => '100.00',
            'base_final_planned_cumulative_percent' => null,
            'candidate_final_planned_cumulative_percent' => '0.00',
            'added_lines' => [
                ['sequence_number' => 4, 'measurement_date' => null, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00'],
                ['sequence_number' => 1, 'measurement_date' => '2027-01-01', 'planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '0.00'],
                ['sequence_number' => 2, 'measurement_date' => '2027-02-01', 'planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '0.00'],
                ['sequence_number' => 3, 'measurement_date' => '2027-03-01', 'planned_monthly_percent' => '0.00', 'planned_cumulative_percent' => '0.00'],
            ],
            'removed_lines' => [],
            'changed_lines' => [],
            'unchanged_lines' => 0,
            'open_measurement_ids_on_base' => [],
            'pending_before_effective_percent' => '0.00',
        ]);
});

// ── Avanço físico ────────────────────────────────────────────────────────────

it('shows the single physical progress of the plan and projects the draft from it, the activated version keeping its activation snapshot', function () {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = $context = planVersionComparisonHalfBuiltPlan();
    $draft = planVersionComparisonRevision($planSet, $actor);
    $before = planVersionComparisonFootprint($planSet);

    $comparison = planVersionComparisonCompare($v1, $draft);
    $projected = [
        '2027-04' => ['70.00', '80.00', ['planned_cumulative_percent']],
        '2027-05' => ['80.00', '90.00', ['planned_cumulative_percent']],
        '2027-06' => ['90.00', '100.00', ['planned_cumulative_percent']],
    ];

    // O avanço é do plano (30% iniciais + 20% de fevereiro), um só para as
    // duas versões. O rascunho mostra o que a ativação gravaria hoje: janeiro
    // e março (20%) ficaram antes da vigência sem medição e continuam a medir,
    // então de abril em diante vale 50% + 20% mais o previsto mensal; janeiro
    // a março, anteriores à vigência, ficam como na V1.
    expect($comparison->currentBasisPoints)->toBe(5_000)
        ->and($comparison->remainingBasisPoints)->toBe(5_000)
        ->and($comparison->pendingBeforeEffectiveBasisPoints)->toBe(2_000)
        ->and(planVersionComparisonCumulativeChanges($comparison))->toBe($projected)
        ->and($comparison->unchangedLines)->toBe(3)
        ->and(Arr::only($comparison->toArray(), [
            'current_physical_progress_percent',
            'remaining_physical_progress_percent',
            'base_final_planned_cumulative_percent',
            'candidate_final_planned_cumulative_percent',
            'pending_before_effective_percent',
        ]))->toBe([
            'current_physical_progress_percent' => '50.00',
            'remaining_physical_progress_percent' => '50.00',
            'base_final_planned_cumulative_percent' => '90.00',
            'candidate_final_planned_cumulative_percent' => '100.00',
            'pending_before_effective_percent' => '20.00',
        ]);

    // Comparar é leitura: o rascunho continua com o acumulado copiado da V1
    // (70%, 80% e 90%), e nada entra na trilha.
    expect(planVersionComparisonFootprint($planSet))->toBe($before)
        ->and(array_map(fn (MeasurementPlanLine $line): string => $line->planned_cumulative_percent, planVersionComparisonLines($draft)))->toBe([
            '2027-01' => '40.00',
            '2027-02' => '50.00',
            '2027-03' => '60.00',
            '2027-04' => '70.00',
            '2027-05' => '80.00',
            '2027-06' => '90.00',
        ]);

    // Ativada, a V2 grava o acumulado que a tela mostrou e o retrato do avanço
    // daquele momento; o pendente é do rascunho e some da versão ativada.
    $v2 = planVersionComparisonActivate($draft, $actor);
    $activated = planVersionComparisonCompare($v1, $v2);

    expect($v2->activation_progress_percent)->toBe('50.00')
        ->and($activated->currentBasisPoints)->toBe(5_000)
        ->and($activated->remainingBasisPoints)->toBe(5_000)
        ->and($activated->pendingBeforeEffectiveBasisPoints)->toBeNull()
        ->and($activated->toArray()['pending_before_effective_percent'])->toBeNull()
        ->and(planVersionComparisonCumulativeChanges($activated))->toBe($projected);

    // Em maio a Engenharia aprova mais 15% (abril, sob a V2) e o plano vai a
    // 65%. A comparação da V2 continua no retrato da ativação -- a régua sob
    // a qual ela foi aprovada --, e a V1, substituída, guarda o da dela: 30%.
    planVersionComparisonTravelTo('2027-05-05');
    Scenario::measured(planVersionComparisonUnder($context, $v2), '2027-04', 15);
    $afterMeasurement = planVersionComparisonCompare($v1, $v2);
    $first = planVersionComparisonCompare(null, $v1);

    expect(Scenario::progress($context)->currentPercent())->toBe('65.00')
        ->and($afterMeasurement->currentBasisPoints)->toBe(5_000)
        ->and($afterMeasurement->remainingBasisPoints)->toBe(5_000)
        ->and(Arr::only($afterMeasurement->toArray(), ['current_physical_progress_percent', 'remaining_physical_progress_percent']))->toBe([
            'current_physical_progress_percent' => '50.00',
            'remaining_physical_progress_percent' => '50.00',
        ])
        ->and($first->currentBasisPoints)->toBe(3_000)
        ->and($first->remainingBasisPoints)->toBe(7_000);

    // O próximo rascunho parte do avanço de agora: 65%, mais janeiro e março
    // (20%), ainda por medir, com o acumulado de maio em diante refeito sobre
    // eles. Medida acima do previsto (20% em fevereiro, 15% em abril), a obra
    // não cabe mais no cronograma copiado: junho somaria 105%, e a meta para no
    // teto de 100% -- a ativação desse rascunho esbarraria no teto do plano.
    planVersionComparisonTravelTo('2027-05-06');
    $next = planVersionComparisonCompare($v2, planVersionComparisonRevision($planSet, $actor));

    expect($next->currentBasisPoints)->toBe(6_500)
        ->and($next->remainingBasisPoints)->toBe(3_500)
        ->and($next->pendingBeforeEffectiveBasisPoints)->toBe(2_000)
        ->and($next->unchangedLines)->toBe(5)
        ->and(planVersionComparisonCumulativeChanges($next))->toBe([
            '2027-05' => ['90.00', '95.00', ['planned_cumulative_percent']],
        ]);
});

it('projects the draft from the business month in Brasília, not from the UTC date', function (string $instantUtc, string $competence, array $changes, string $pendingBeforeEffective) {
    ['actor' => $actor, 'planSet' => $planSet, 'v1' => $v1] = planVersionComparisonHalfBuiltPlan();
    $draft = planVersionComparisonRevision($planSet, $actor);

    $this->travelTo(CarbonImmutable::parse($instantUtc, 'UTC'));
    $comparison = planVersionComparisonCompare($v1, $draft);

    // A projeção parte da competência em que a ativação valeria agora: às
    // 22h30 de 30/04 em Brasília ainda é abril, mesmo já sendo maio em UTC.
    // Em maio, abril também fica antes da vigência sem medição e passa do
    // cronograma projetado para o pendente; o fim continua em 100%.
    expect(MeasurementPlanVersionService::activationCompetence()->toDateString())->toBe($competence)
        ->and($comparison->currentBasisPoints)->toBe(5_000)
        ->and(planVersionComparisonCumulativeChanges($comparison))->toBe($changes)
        ->and($comparison->toArray()['candidate_final_planned_cumulative_percent'])->toBe('100.00')
        ->and($comparison->toArray()['pending_before_effective_percent'])->toBe($pendingBeforeEffective);
})->with([
    '22:30 of 30/04 in Brasília (01:30 UTC of 01/05)' => ['2027-05-01 01:30:00', '2027-04-01', [
        '2027-04' => ['70.00', '80.00', ['planned_cumulative_percent']],
        '2027-05' => ['80.00', '90.00', ['planned_cumulative_percent']],
        '2027-06' => ['90.00', '100.00', ['planned_cumulative_percent']],
    ], '20.00'],
    '00:30 of 01/05 in Brasília (03:30 UTC)' => ['2027-05-01 03:30:00', '2027-05-01', [
        '2027-05' => ['80.00', '90.00', ['planned_cumulative_percent']],
        '2027-06' => ['90.00', '100.00', ['planned_cumulative_percent']],
    ], '30.00'],
]);

// ── Medições previstas ───────────────────────────────────────────────────────

it('matches the planned measurements of the two versions by lineage: added, removed, changed and unchanged', function () {
    ['actor' => $actor, 'v1' => $v1, 'draft' => $draft] = planVersionComparisonScheduleRevision();
    $v1Lines = planVersionComparisonLines($v1);

    // Recém-aberta, a revisão é cópia fiel da V1.
    $copy = planVersionComparisonCompare($v1, $draft);

    expect($copy->hasLineChanges())->toBeFalse()
        ->and($copy->addedLines)->toBe([])
        ->and($copy->removedLines)->toBe([])
        ->and($copy->changedLines)->toBe([])
        ->and($copy->unchangedLines)->toBe(5);

    // O replanejamento: 5% de abril passam para maio; junho sai do
    // cronograma; a medição prevista 05 vai de julho para agosto, e julho
    // ganha uma medição prevista nova.
    $rows = planVersionComparisonDraftRows($draft);
    $draft = planVersionComparisonSaveDraft($draft, $actor, [], [
        $rows['2027-03'],
        ['planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '15.00'] + $rows['2027-04'],
        ['planned_monthly_percent' => '15.00', 'planned_cumulative_percent' => '30.00'] + $rows['2027-05'],
        ['sequence_number' => 6, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2027-07'],
        ['measurement_date' => '2027-08'] + $rows['2027-07'],
    ]);

    $comparison = planVersionComparisonCompare($v1, $draft);

    // Casadas pela linhagem: a 05 em agosto é a mesma medição prevista de
    // julho (mudou só o mês), e a nova de julho é outra, mesmo no mês dela. O
    // acumulado é o projetado: abril perde os 5% que maio recupera, e a nova
    // de julho repõe os 10% de junho -- agosto chega aos mesmos 50%.
    expect($comparison->hasLineChanges())->toBeTrue()
        ->and($comparison->unchangedLines)->toBe(1)
        ->and($comparison->addedLines)->toBe([
            ['sequence_number' => 6, 'measurement_date' => '2027-07-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00'],
        ])
        ->and($comparison->removedLines)->toBe([
            ['sequence_number' => 4, 'measurement_date' => '2027-06-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00'],
        ])
        ->and($comparison->changedLines)->toBe([
            [
                'lineage_key' => $v1Lines['2027-04']->lineage_key,
                'before' => ['sequence_number' => 2, 'measurement_date' => '2027-04-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00'],
                'after' => ['sequence_number' => 2, 'measurement_date' => '2027-04-01', 'planned_monthly_percent' => '5.00', 'planned_cumulative_percent' => '15.00'],
                'changes' => ['planned_monthly_percent', 'planned_cumulative_percent'],
            ],
            [
                'lineage_key' => $v1Lines['2027-05']->lineage_key,
                'before' => ['sequence_number' => 3, 'measurement_date' => '2027-05-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '30.00'],
                'after' => ['sequence_number' => 3, 'measurement_date' => '2027-05-01', 'planned_monthly_percent' => '15.00', 'planned_cumulative_percent' => '30.00'],
                'changes' => ['planned_monthly_percent'],
            ],
            [
                'lineage_key' => $v1Lines['2027-07']->lineage_key,
                'before' => ['sequence_number' => 5, 'measurement_date' => '2027-07-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '50.00'],
                'after' => ['sequence_number' => 5, 'measurement_date' => '2027-08-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '50.00'],
                'changes' => ['measurement_date'],
            ],
        ]);

    // A ativação grava exatamente o cronograma que a comparação mostrou.
    $v2 = planVersionComparisonActivate($draft, $actor);
    $lineKeys = ['added_lines', 'removed_lines', 'changed_lines', 'unchanged_lines'];

    expect(Arr::only(planVersionComparisonCompare($v1, $v2)->toArray(), $lineKeys))
        ->toBe(Arr::only($comparison->toArray(), $lineKeys));
});

it('gives back its lineage to a planned measurement deleted and created again with the same number and month, and treats one created under another number as removed and added, and a renumbered one as changed', function () {
    ['actor' => $actor, 'v1' => $v1, 'draft' => $draft] = planVersionComparisonScheduleRevision();
    $v1Lines = planVersionComparisonLines($v1);
    $rows = planVersionComparisonDraftRows($draft);

    // Abril é excluído e cadastrado de novo, igual -- mesma sequência e mês: é
    // a mesma medição prevista da V1 e volta com a linhagem dela. Junho é
    // excluído e cadastrado com outro número: é outra medição prevista, sem a
    // identidade (e a ocupação) da anterior. Março e maio só trocam de número
    // entre si.
    $draft = planVersionComparisonSaveDraft($draft, $actor, [], [
        ['sequence_number' => 3] + $rows['2027-03'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2027-04'],
        ['sequence_number' => 1] + $rows['2027-05'],
        ['sequence_number' => 6, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2027-06'],
        $rows['2027-07'],
    ]);

    $comparison = planVersionComparisonCompare($v1, $draft);
    $draftLines = planVersionComparisonLines($draft);

    expect($draftLines['2027-04']->id)->not->toBe($rows['2027-04']['id'])
        ->and($draftLines['2027-04']->lineage_key)->toBe($v1Lines['2027-04']->lineage_key)
        ->and($draftLines['2027-06']->lineage_key)->not->toBe($v1Lines['2027-06']->lineage_key)
        ->and($comparison->removedLines)->toBe([
            ['sequence_number' => 4, 'measurement_date' => '2027-06-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00'],
        ])
        ->and($comparison->addedLines)->toBe([
            ['sequence_number' => 6, 'measurement_date' => '2027-06-01', 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00'],
        ])
        ->and(array_map(fn (array $line): array => [
            $line['lineage_key'],
            $line['before']['sequence_number'],
            $line['after']['sequence_number'],
            $line['changes'],
        ], $comparison->changedLines))->toBe([
            [$v1Lines['2027-03']->lineage_key, 1, 3, ['sequence_number']],
            [$v1Lines['2027-05']->lineage_key, 3, 1, ['sequence_number']],
        ])
        ->and($comparison->unchangedLines)->toBe(2);
});

// ── Medições em andamento na versão anterior ─────────────────────────────────

it('lists the open measurements sent under the base version, which stay on it after the revision', function () {
    planVersionComparisonTravelTo('2026-06-01');
    ['actor' => $actor, 'operation' => $operation] = planVersionComparisonOperation();
    $planSet = planVersionComparisonPlan(
        $operation,
        $actor,
        planVersionComparisonMonthly('2026-06', 4, '10.00'),
        fund: '20000000.00',
        initialPercent: '30.00',
        referenceDate: '2026-05-31',
    );
    $v1 = planVersionComparisonActivate(planVersionComparisonVersion($planSet, 1), $actor);
    $context = compact('actor', 'operation', 'planSet');
    $underV1 = planVersionComparisonUnder($context, $v1);
    $workflow = app(MeasurementWorkflow::class);

    // Junho: medido, pago (10% de R$ 20 mi) e finalizado sob a V1.
    planVersionComparisonTravelTo('2026-06-20');
    $finalized = Scenario::measurement($underV1, '2026-06');
    planVersionComparisonTravelTo('2026-06-25');
    Scenario::approveEngineering($underV1, $finalized, 10);
    $workflow->approve($finalized->fresh(), $actor);
    $workflow->approve($finalized->fresh(), $actor);
    $payment = $workflow->registerPayment($finalized->fresh(), $actor, [
        'plan_set_id' => $planSet->id,
        'pay_date' => '2026-06-25',
        'amount' => '2000000.00',
        'method' => 'TED',
    ]);
    $workflow->approve($finalized->fresh(), $actor);
    $workflow->attachReceipt($payment->fresh(), $actor, MeasurementReceiptEvidenceScenario::file());
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $actor);
    $workflow->finalize($finalized->fresh(), $actor);

    // Julho: a primeira medição é recusada na Engenharia; a reenviada aguarda
    // a análise.
    planVersionComparisonTravelTo('2026-07-20');
    $rejected = Scenario::measurement($underV1, '2026-07');
    $workflow->reject($rejected->fresh(), $actor, 'Arquivo de outra obra: reenviar a medição de julho.');
    planVersionComparisonTravelTo('2026-07-22');
    $atEngineering = Scenario::measurement($underV1, '2026-07');

    // Agosto: aprovada pela Engenharia e pela Gestão, aguardando o pagamento.
    planVersionComparisonTravelTo('2026-08-20');
    $awaitingPayment = Scenario::measurement($underV1, '2026-08');
    Scenario::approveEngineering($underV1, $awaitingPayment, 10);
    $workflow->approve($awaitingPayment->fresh(), $actor);
    $workflow->approve($awaitingPayment->fresh(), $actor);

    expect([
        $finalized->fresh()->status,
        $rejected->fresh()->status,
        $atEngineering->fresh()->status,
        $awaitingPayment->fresh()->status,
    ])->toBe(['finalized', 'rejected', 'in_review', 'awaiting_payment'])
        ->and(collect([$finalized, $rejected, $atEngineering, $awaitingPayment])->map(fn ($measurement): int => (int) $measurement->assets()->sole()->plan_version_id)->unique()->values()->all())
        ->toBe([$v1->id]);

    // A revisão de custo, aberta no fim de agosto, avisa quais medições em
    // andamento continuam na V1. As encerradas -- finalizada e recusada -- não
    // pedem mais nada e ficam de fora.
    planVersionComparisonTravelTo('2026-08-25');
    $draft = planVersionComparisonSaveDraft(
        planVersionComparisonRevision($planSet, $actor, MeasurementPlanRevisionCategory::Cost),
        $actor,
        ['construction_fund_amount' => '23000000.00'],
    );
    $comparison = planVersionComparisonCompare($v1, $draft);

    expect($comparison->openMeasurementIdsOnBase)->toBe([$atEngineering->id, $awaitingPayment->id])
        ->and($comparison->toArray()['open_measurement_ids_on_base'])->toBe([$atEngineering->id, $awaitingPayment->id]);

    // Ativada a V2 em setembro, as duas continuam na V1; a medição de setembro
    // nasce sob a V2 e é dela que a próxima revisão avisa.
    planVersionComparisonTravelTo('2026-09-01');
    $v2 = planVersionComparisonActivate($draft, $actor);
    planVersionComparisonTravelTo('2026-09-20');
    $underV2 = Scenario::measurement(planVersionComparisonUnder($context, $v2), '2026-09');
    planVersionComparisonTravelTo('2026-09-25');
    $v3 = planVersionComparisonRevision($planSet, $actor);

    expect(planVersionComparisonCompare($v1, $v2)->openMeasurementIdsOnBase)->toBe([$atEngineering->id, $awaitingPayment->id])
        ->and(planVersionComparisonCompare($v2, $v3)->openMeasurementIdsOnBase)->toBe([$underV2->id])
        ->and(planVersionComparisonCompare(null, $v1)->openMeasurementIdsOnBase)->toBe([]);

    // A lista é a situação de agora: recusada a de julho, só agosto continua.
    $workflow->reject($atEngineering->fresh(), $actor, 'Fotos da medição ilegíveis.');

    expect($atEngineering->fresh()->status)->toBe('rejected')
        ->and(planVersionComparisonCompare($v1, $v2)->openMeasurementIdsOnBase)->toBe([$awaitingPayment->id]);
});
