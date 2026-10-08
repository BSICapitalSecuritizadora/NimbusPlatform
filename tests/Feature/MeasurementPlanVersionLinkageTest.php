<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Schemas\MeasurementForm;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanLinesRelationManager;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationNextMeasurementResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
});

/**
 * Leva o relógio às 10h de Brasília (13h UTC) do dia: a vigência de uma
 * versão ativada é o mês desse dia no calendário de negócio.
 */
function planVersionLinkageTravelTo(string $date): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} 13:00:00", 'UTC'));
}

/**
 * Obra que entrou no sistema com 30% executados até 31/05/2026, planejada pelo
 * serviço e com a V1 ativada em 01/06/2026: Fundo de Obra de R$ 20.000.000,00
 * e 10% previstos por mês de 06/2026 a 09/2026. Quem envia e decide as
 * medições responde por todas as etapas; quem replaneja é um administrador.
 *
 * @return array{actor: User, planner: User, operation: Operation, planSet: MeasurementPlanSet, v1: MeasurementPlanVersion, lines: array<string, MeasurementPlanLine>}
 */
function planVersionLinkageScenario(): array
{
    config()->set('filesystems.private_disk', 'local');
    planVersionLinkageTravelTo('2026-06-01');

    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo(['measurements.view', 'measurements.create', 'measurements.update', 'measurements.review', 'measurements.pay', 'measurements.receipts', 'measurements.finalize']);
    $planner = makeAdminUser();

    $operation = Operation::factory()->create([
        'status' => 'active',
        'assigned_user_id' => $actor->id,
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);

    $service = app(MeasurementPlanVersionService::class);
    $planSet = $service->createPlan($operation, $planner, [
        'name' => 'Residencial Aurora',
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => '30.00',
        'initial_physical_progress_reference_date' => '2026-05-31',
    ], ['construction_fund_amount' => '20000000.00'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2026-06'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '50.00', 'measurement_date' => '2026-07'],
        ['sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '60.00', 'measurement_date' => '2026-08'],
        ['sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '70.00', 'measurement_date' => '2026-09'],
    ]);
    $draft = $planSet->draftVersion()->firstOrFail();
    $v1 = $service->activate($draft, $planner, (int) $draft->revision);

    return [
        'actor' => $actor,
        'planner' => $planner,
        'operation' => $operation,
        'planSet' => $planSet->fresh(),
        'v1' => $v1,
        'lines' => planVersionLinkageLinesOf($v1),
    ];
}

/**
 * Revisão de custo pelo serviço, do rascunho à ativação. `$schedule` recebe as
 * linhas do rascunho (por competência) e devolve o cronograma inteiro a
 * gravar; sem ele, o cronograma copiado da vigente fica igual.
 *
 * @param  array{planner: User, planSet: MeasurementPlanSet}  $scenario
 * @param  (callable(array<string, MeasurementPlanLine>): list<array<string, mixed>>)|null  $schedule
 */
function planVersionLinkageRevise(array $scenario, string $fund, ?callable $schedule = null): MeasurementPlanVersion
{
    $service = app(MeasurementPlanVersionService::class);
    $active = MeasurementPlanVersion::query()->where('plan_set_id', $scenario['planSet']->id)->active()->sole();
    $draft = $service->createRevision($scenario['planSet'], $scenario['planner'], [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Reajuste do orçamento da obra aprovado pelo comitê de crédito.',
    ], (int) $active->id);
    $draft = $service->updateDraft(
        $draft,
        $scenario['planner'],
        ['construction_fund_amount' => $fund],
        $schedule === null ? null : $schedule(planVersionLinkageLinesOf($draft)),
        (int) $draft->revision,
    );

    return $service->activate($draft, $scenario['planner'], (int) $draft->revision);
}

/**
 * @return array<string, MeasurementPlanLine> linhas da versão por competência ('Y-m')
 */
function planVersionLinkageLinesOf(MeasurementPlanVersion $version): array
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
 * O cenário com o cronograma da versão: as medições enviadas por
 * {@see Scenario::measurement()} usam as linhas dela.
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function planVersionLinkageUnder(array $scenario, MeasurementPlanVersion $version): array
{
    return ['lines' => planVersionLinkageLinesOf($version)] + $scenario;
}

/**
 * Medição da competência ainda sem arquivo, como o envio a cria antes de
 * gravar o arquivo de cada empreendimento.
 *
 * @param  array{actor: User, operation: Operation}  $scenario
 */
function planVersionLinkageMeasurement(array $scenario, string $month): Measurement
{
    return Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => $month.'-01',
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $scenario['actor']->id,
    ]);
}

/**
 * Grava o arquivo da medição numa linha do cronograma: é aqui que o arquivo
 * captura a versão do plano e ocupa a medição prevista.
 */
function planVersionLinkageAttach(Measurement $measurement, MeasurementPlanLine $line): MeasurementAsset
{
    $path = "nimbus_docs/measurements/assets/plan-version-linkage-{$measurement->id}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.7 vínculo com a versão do plano {$measurement->id}");

    return $measurement->assets()->create([
        'plan_set_id' => $line->plan_set_id,
        'plan_line_id' => $line->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);
}

/**
 * Recusa do domínio como ela sai, com o contexto que vai para o log.
 */
function planVersionLinkageRefusal(callable $attempt): MeasurementWorkflowException
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
function planVersionLinkageEngineeringErrors(callable $approval): array
{
    try {
        $approval();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('A Engenharia deveria ter recusado a aprovação.');
}

/**
 * Opções do seletor "Medição do cronograma" como o formulário as monta, sem
 * renderizar a tela.
 *
 * @return array<int, string>
 */
function planVersionLinkageScheduleOptions(MeasurementPlanSet $planSet, ?MeasurementAsset $asset = null): array
{
    $probe = new class extends MeasurementForm
    {
        /**
         * @return array<int, string>
         */
        public static function schedules(mixed $planSetId, ?MeasurementAsset $asset): array
        {
            return parent::scheduleOptionsForPlanSet($planSetId, $asset);
        }
    };

    return $probe::schedules($planSet->id, $asset);
}

function planVersionLinkageNextMonth(Operation $operation): ?string
{
    return app(OperationNextMeasurementResolver::class)
        ->addNextMeasurementDate(Operation::query())
        ->findOrFail($operation->id)
        ->next_pending_measurement_at?->format('m/Y');
}

/**
 * Linhas disponíveis para medição nova, numa versão.
 *
 * @return list<int>
 */
function planVersionLinkageAvailableLineIds(MeasurementPlanVersion $version): array
{
    return MeasurementPlanLine::query()
        ->where('plan_version_id', $version->id)
        ->availableForMeasurement()
        ->orderBy('measurement_date')
        ->orderBy('sequence_number')
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

// ── A versão capturada no envio ──────────────────────────────────────────────

it('keeps a measurement on the version it was sent under and sends the next one under the new version', function () {
    $scenario = planVersionLinkageScenario();
    $v1 = $scenario['v1'];
    $june = $scenario['lines']['2026-06'];

    // M06 enviada em 20/06, com a V1 vigente: o arquivo captura a V1.
    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');
    $m06Asset = $m06->assets()->sole();

    expect($m06Asset->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
        'plan_version_id' => $v1->id,
        'plan_line_id' => $june->id,
        'line_claim_key' => $june->lineage_key,
    ]);

    // A V2 entra em vigor em 01/07 com a M06 ainda na Engenharia: a medição
    // aberta não migra em silêncio para o cronograma novo.
    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00');
    $v2Lines = planVersionLinkageLinesOf($v2);

    expect($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v1->fresh()->superseded_by_version_id)->toBe($v2->id)
        ->and($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->version_number)->toBe(2)
        ->and($v2->effective_from->toDateString())->toBe('2026-07-01')
        ->and($m06->fresh()->status)->toBe('in_review')
        ->and($m06Asset->fresh()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $v1->id,
            'plan_line_id' => $june->id,
            'line_claim_key' => $june->lineage_key,
        ]);

    // M07, enviada depois da ativação, nasce sob a V2: na cópia de julho, que
    // é a mesma medição prevista (mesma linhagem) da V1.
    planVersionLinkageTravelTo('2026-07-20');
    $m07 = Scenario::measurement(planVersionLinkageUnder($scenario, $v2), '2026-07');

    expect($v2Lines['2026-07']->lineage_key)->toBe($scenario['lines']['2026-07']->lineage_key)
        ->and($m07->assets()->sole()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $v2->id,
            'plan_line_id' => $v2Lines['2026-07']->id,
            'line_claim_key' => $scenario['lines']['2026-07']->lineage_key,
        ])
        ->and($v1->assets()->pluck('measurement_id')->all())->toBe([$m06->id])
        ->and($v2->assets()->pluck('measurement_id')->all())->toBe([$m07->id]);
});

it('refuses a new measurement file on a line of a superseded or draft version of the plan', function () {
    $scenario = planVersionLinkageScenario();
    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00');
    $v3 = app(MeasurementPlanVersionService::class)->createRevision($scenario['planSet'], $scenario['planner'], [
        'revision_category' => MeasurementPlanRevisionCategory::Schedule->value,
        'revision_reason' => 'Replanejamento do segundo semestre.',
    ], $v2->id);

    planVersionLinkageTravelTo('2026-07-20');
    $measurement = planVersionLinkageMeasurement($scenario, '2026-07');
    $refusal = sprintf(MeasurementAsset::SUPERSEDED_PLAN_REFUSAL, 'Residencial Aurora');

    // Quem abriu o formulário antes da revisão não envia pelo cronograma
    // substituído, nem pelo rascunho da próxima revisão, que ainda não vale.
    expect(fn () => planVersionLinkageAttach($measurement, $scenario['lines']['2026-07']))
        ->toThrow(new MeasurementWorkflowException($refusal))
        ->and(fn () => planVersionLinkageAttach($measurement, planVersionLinkageLinesOf($v3)['2026-07']))
        ->toThrow(new MeasurementWorkflowException($refusal))
        ->and(MeasurementAsset::query()->count())->toBe(0);

    // A mesma competência pela versão vigente é aceita: a recusa é da versão,
    // não do mês.
    $asset = planVersionLinkageAttach($measurement, planVersionLinkageLinesOf($v2)['2026-07']);

    expect($asset->plan_version_id)->toBe($v2->id)
        ->and($asset->line_claim_key)->toBe($scenario['lines']['2026-07']->lineage_key)
        ->and($measurement->assets()->pluck('id')->all())->toBe([$asset->id]);
});

it('refuses a measurement file on a plan whose first version was never activated', function () {
    $scenario = planVersionLinkageScenario();
    $tower = app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['planner'], [
        'name' => 'Torre Beta',
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '5000000.00'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-06'],
    ]);
    $draftLine = MeasurementPlanLine::query()->where('plan_set_id', $tower->id)->sole();

    planVersionLinkageTravelTo('2026-06-20');
    $measurement = planVersionLinkageMeasurement($scenario, '2026-06');

    // O cronograma da V1 ainda é rascunho: medir sobre ele seria medir um
    // plano que ninguém pôs em vigor.
    expect(fn () => planVersionLinkageAttach($measurement, $draftLine))
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementAsset::PLAN_NOT_EFFECTIVE_REFUSAL, 'Torre Beta')))
        ->and($measurement->assets()->exists())->toBeFalse()
        ->and($tower->versions()->sole()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and(MeasurementAsset::query()->where('plan_set_id', $tower->id)->exists())->toBeFalse();
});

// ── Aprovação, correção e pagamento sob a versão capturada ───────────────────

it('approves at Engineering after the revision a measurement sent under V1 against the V1 line and fund', function () {
    $scenario = planVersionLinkageScenario();
    $v1 = $scenario['v1'];
    $june = $scenario['lines']['2026-06'];

    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');

    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00');
    $v2June = planVersionLinkageLinesOf($v2)['2026-06'];

    // Aprovada em 05/07, depois da revisão de custo: vale o que estava em vigor
    // quando a medição foi enviada, e não o fundo revisado.
    planVersionLinkageTravelTo('2026-07-05');
    Scenario::approveEngineering($scenario, $m06, 25);

    $snapshot = $m06->fresh()->engineering_snapshot;
    $entry = $snapshot['plan_sets'][0];

    expect($snapshot['plan_sets'])->toHaveCount(1)
        ->and($entry['plan_set_id'])->toBe($scenario['planSet']->id)
        ->and($entry['plan_version_id'])->toBe($v1->id)
        ->and($entry['plan_version_number'])->toBe(1)
        ->and($entry['construction_fund_amount'])->toBe('20000000.00')
        ->and($entry['plan_line_id'])->toBe($june->id)
        ->and($entry['plan_line_lineage_key'])->toBe($june->lineage_key)
        ->and($entry['measurement_date'])->toBe('2026-06-01')
        ->and($entry['planned_cumulative_percent'])->toBe('40.00')
        ->and($entry['realized_monthly_percent'])->toBe('25.00')
        ->and($entry['realized_cumulative_percent'])->toBe('55.00')
        ->and($entry['plan_initial_physical_progress_percent'])->toBe('30.00')
        ->and($entry['prior_realized_cumulative_percent'])->toBe('30.00')
        ->and($scenario['planSet']->fresh()->currentConstructionFundAmount())->toBe('23000000.00');

    // A execução fica gravada na linha da V1 em que a medição foi enviada; a
    // cópia da V2 continua sem realizado.
    expect($june->fresh()->only(['realized_monthly_percent', 'realized_cumulative_percent', 'measurement_id']))->toBe([
        'realized_monthly_percent' => '25.00',
        'realized_cumulative_percent' => '55.00',
        'measurement_id' => $m06->id,
    ])
        ->and($v2June->fresh()->only(['realized_monthly_percent', 'realized_cumulative_percent', 'measurement_id']))->toBe([
            'realized_monthly_percent' => '0.00',
            'realized_cumulative_percent' => '0.00',
            'measurement_id' => null,
        ]);

    // O avanço é do plano: a M06 soma ao avanço inicial, seja qual for a
    // versão vigente.
    $progress = Scenario::progress($scenario);

    expect($progress->currentPercent())->toBe('55.00')
        ->and(array_map(fn ($contribution): array => [
            $contribution->measurementId,
            $contribution->planLineId,
            $contribution->lineageKey,
            $contribution->basisPoints,
        ], $progress->contributions))->toBe([[$m06->id, $june->id, $june->lineage_key, 2500]]);
});

it('keeps a measurement sent under a superseded version on its line and on its version', function () {
    $scenario = planVersionLinkageScenario();

    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');

    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00');
    $v2Lines = planVersionLinkageLinesOf($v2);
    $before = $m06->assets()->sole()->getAttributes();
    $capturedRefusal = sprintf(MeasurementAsset::CAPTURED_VERSION_REFUSAL, 'V1', 'Residencial Aurora');

    // Mudar a M06 para outra competência da V1 a levaria para um mês que a V2
    // já replanejou: a correção é recusar e reenviar sob a vigente.
    expect(fn () => $m06->assets()->sole()->fill(['plan_line_id' => $scenario['lines']['2026-07']->id])->save())
        ->toThrow(new MeasurementWorkflowException(sprintf(MeasurementAsset::SUPERSEDED_VERSION_LINE_CHANGE_REFUSAL, 'V1', 'Residencial Aurora')))
        // A versão capturada no envio não muda: nem por uma linha da V2, nem
        // pela coluna gravada.
        ->and(fn () => $m06->assets()->sole()->fill(['plan_line_id' => $v2Lines['2026-06']->id])->save())
        ->toThrow(new MeasurementWorkflowException($capturedRefusal))
        ->and(fn () => $m06->assets()->sole()->forceFill(['plan_version_id' => $v2->id])->save())
        ->toThrow(new MeasurementWorkflowException($capturedRefusal))
        ->and($m06->assets()->sole()->getAttributes())->toBe($before);
});

it('pays and finalizes under the V1 fund a measurement approved before the cost revision', function () {
    $scenario = planVersionLinkageScenario();
    $workflow = app(MeasurementWorkflow::class);
    $planSetId = $scenario['planSet']->id;

    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');
    planVersionLinkageTravelTo('2026-06-25');
    Scenario::approveEngineering($scenario, $m06, 25);

    // A revisão de custo (R$ 20 mi → R$ 23 mi) entra em vigor com a M06 já
    // aprovada pela Engenharia e ainda sem pagamento.
    planVersionLinkageTravelTo('2026-07-01');
    planVersionLinkageRevise($scenario, '23000000.00');

    planVersionLinkageTravelTo('2026-07-06');
    $workflow->approve($m06->fresh(), $scenario['actor']);
    $workflow->approve($m06->fresh(), $scenario['actor']);

    // 25% de R$ 20.000.000,00: a referência é o fundo da versão em que a
    // medição foi enviada. Com o fundo revisado, o mesmo pagamento divergiria.
    $payment = $workflow->registerPayment($m06->fresh(), $scenario['actor'], [
        'plan_set_id' => $planSetId,
        'pay_date' => '2026-07-06',
        'amount' => '5000000.00',
        'method' => 'TED',
    ]);
    $assessment = $payment->fresh()->financial_assessment;

    expect($payment->plan_set_id)->toBe($planSetId)
        ->and($payment->amount)->toBe('5000000.00')
        ->and($assessment['reference']['fund_amount'])->toBe('20000000.00')
        ->and($assessment['reference']['expected_amount'])->toBe('5000000.00')
        ->and($assessment['reference']['divergence_amount'])->toBe('0.00')
        ->and($assessment['reference']['status'])->toBe('matched')
        ->and($assessment['requires_acceptance'])->toBeFalse()
        ->and($scenario['planSet']->fresh()->currentConstructionFundAmount())->toBe('23000000.00');

    // A Finalização confere o contexto aprovado contra o fundo da versão
    // capturada; contra o vigente, recusaria uma medição íntegra.
    $workflow->approve($m06->fresh(), $scenario['actor']);
    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file());
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['actor']);
    $workflow->finalize($m06->fresh(), $scenario['actor']);

    expect($m06->fresh()->status)->toBe('finalized')
        ->and($m06->assets()->sole()->plan_version_id)->toBe($scenario['v1']->id);
});

// ── Ocupação da medição prevista entre versões ───────────────────────────────

it('keeps the planned measurement claimed across versions while the measurement holding it stands', function () {
    $scenario = planVersionLinkageScenario();
    $june = $scenario['lines']['2026-06'];

    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');

    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00');
    $v2Lines = planVersionLinkageLinesOf($v2);

    // A cópia de junho na V2 é a medição prevista que a M06 ocupa na V1.
    expect($v2Lines['2026-06']->lineage_key)->toBe($june->lineage_key)
        ->and(planVersionLinkageAvailableLineIds($v2))->toBe([
            $v2Lines['2026-07']->id,
            $v2Lines['2026-08']->id,
            $v2Lines['2026-09']->id,
        ]);

    // Uma segunda medição de junho pela V2 mediria a mesma competência duas
    // vezes.
    planVersionLinkageTravelTo('2026-07-02');
    $duplicate = planVersionLinkageMeasurement($scenario, '2026-06');
    $refusal = planVersionLinkageRefusal(fn () => planVersionLinkageAttach($duplicate, $v2Lines['2026-06']));

    expect($refusal->getMessage())->toBe(sprintf(MeasurementAsset::LINE_ALREADY_CLAIMED_REFUSAL, '01', '06/2026', 'Residencial Aurora', $m06->id))
        ->and($refusal->context()['holder_measurement_id'])->toBe($m06->id)
        ->and($duplicate->assets()->exists())->toBeFalse();

    // Fora do modelo, a unique do banco ainda recusa a segunda ocupação.
    $violation = null;

    try {
        DB::table('measurement_assets')->insert([
            'measurement_id' => $duplicate->id,
            'plan_set_id' => $scenario['planSet']->id,
            'plan_version_id' => $v2->id,
            'plan_line_id' => $v2Lines['2026-06']->id,
            'line_claim_key' => $june->lineage_key,
            'filename' => 'medicao-duplicada.pdf',
            'storage_path' => 'nimbus_docs/measurements/assets/medicao-duplicada.pdf',
            'storage_disk' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (UniqueConstraintViolationException $exception) {
        $violation = $exception;
    }

    expect($violation)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($violation?->getMessage())->toMatch('/line_claim_key|ma_line_claim_unique/')
        ->and($duplicate->assets()->exists())->toBeFalse()
        ->and(MeasurementAsset::query()->where('line_claim_key', $june->lineage_key)->pluck('measurement_id')->all())->toBe([$m06->id]);
});

it('releases the planned measurement when the measurement holding it is rejected at Engineering', function () {
    $scenario = planVersionLinkageScenario();
    $june = $scenario['lines']['2026-06'];

    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');

    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00');
    $v2Lines = planVersionLinkageLinesOf($v2);

    // Recusa terminal na Engenharia: a M06 não tem Engenharia aprovada nem
    // pagamento, então deixa de ocupar junho.
    planVersionLinkageTravelTo('2026-07-03');
    app(MeasurementWorkflow::class)->reject($m06->fresh(), $scenario['actor'], 'Arquivo de outra obra: reenviar a medição de junho.');

    // O arquivo recusado continua gravado, na V1, como histórico; a
    // competência volta a aceitar medição.
    expect($m06->fresh()->status)->toBe('rejected')
        ->and($m06->assets()->sole()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $scenario['v1']->id,
            'plan_line_id' => $june->id,
            'line_claim_key' => null,
        ])
        ->and(planVersionLinkageAvailableLineIds($v2))->toBe([
            $v2Lines['2026-06']->id,
            $v2Lines['2026-07']->id,
            $v2Lines['2026-08']->id,
            $v2Lines['2026-09']->id,
        ]);

    // O reenvio de junho nasce sob a vigente e ocupa a mesma medição prevista.
    $resent = Scenario::measurement(planVersionLinkageUnder($scenario, $v2), '2026-06');

    expect($resent->status)->toBe('in_review')
        ->and($resent->assets()->sole()->only(['plan_version_id', 'plan_line_id', 'line_claim_key']))->toBe([
            'plan_version_id' => $v2->id,
            'plan_line_id' => $v2Lines['2026-06']->id,
            'line_claim_key' => $june->lineage_key,
        ])
        ->and(MeasurementAsset::query()->where('line_claim_key', $june->lineage_key)->pluck('measurement_id')->all())->toBe([$resent->id]);
});

// ── Avanço físico do plano através das versões ───────────────────────────────

it('carries the single physical progress of the plan across the revision up to the 100% ceiling', function () {
    $scenario = planVersionLinkageScenario();
    $planSetId = $scenario['planSet']->id;
    $june = $scenario['lines']['2026-06'];

    // 30% de avanço inicial + 25% da M06, aprovada sob a V1.
    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');
    planVersionLinkageTravelTo('2026-06-25');
    Scenario::approveEngineering($scenario, $m06, 25);

    expect(Scenario::progress($scenario)->currentPercent())->toBe('55.00');

    // A revisão não zera o avanço nem cria outro inicial: a V2 nasce com 55%
    // executados e planeja só os 45% que restam; a V1 fica como estava.
    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00');
    $v2Lines = planVersionLinkageLinesOf($v2);

    expect($v2->activation_progress_percent)->toBe('55.00')
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('55.00')
        ->and($scenario['planSet']->fresh()->initial_physical_progress_percent)->toBe('30.00')
        ->and(array_map(fn (MeasurementPlanLine $line): string => $line->planned_cumulative_percent, $v2Lines))->toBe([
            '2026-06' => '40.00',
            '2026-07' => '65.00',
            '2026-08' => '75.00',
            '2026-09' => '85.00',
        ])
        ->and(array_map(fn (MeasurementPlanLine $line): string => $line->fresh()->planned_cumulative_percent, $scenario['lines']))->toBe([
            '2026-06' => '40.00',
            '2026-07' => '50.00',
            '2026-08' => '60.00',
            '2026-09' => '70.00',
        ]);

    // M07, sob a V2: 55% + 10% = 65%.
    $underV2 = planVersionLinkageUnder($scenario, $v2);
    planVersionLinkageTravelTo('2026-07-20');
    $m07 = Scenario::measured($underV2, '2026-07', 10);

    expect(Scenario::progress($scenario)->currentPercent())->toBe('65.00');

    // O teto de 100% é um só, do plano: com o inicial e as medições das duas
    // versões restam 35%, e a M08 não cabe com 40%.
    planVersionLinkageTravelTo('2026-08-20');
    $m08 = Scenario::measurement($underV2, '2026-08');

    expect(planVersionLinkageEngineeringErrors(fn () => Scenario::approveEngineering($underV2, $m08, 40)))->toBe([
        "realized.{$planSetId}" => ['O percentual físico informado para Residencial Aurora ultrapassaria o limite de 100% do empreendimento. Progresso atual: 65,00%. Percentual informado: 40,00%. Máximo restante: 35,00%.'],
    ])
        ->and($m08->fresh()->only(['status', 'current_stage', 'engineering_snapshot']))->toBe([
            'status' => 'in_review',
            'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
            'engineering_snapshot' => null,
        ])
        ->and($m08->fresh()->hasApprovedEngineering())->toBeFalse()
        ->and($v2Lines['2026-08']->fresh()->only(['realized_monthly_percent', 'realized_cumulative_percent', 'measurement_id']))->toBe([
            'realized_monthly_percent' => '0.00',
            'realized_cumulative_percent' => '0.00',
            'measurement_id' => null,
        ]);

    // Cada contribuição diz a medição prevista (linhagem) que mediu: a M07,
    // medida na cópia da V2, é a linhagem de julho da V1.
    expect(Scenario::progress($scenario)->toArray())->toBe([
        'plan_set_id' => $planSetId,
        'initial_percent' => '30.00',
        'initial_reference_date' => '2026-05-31',
        'measured_percent' => '35.00',
        'current_percent' => '65.00',
        'remaining_percent' => '35.00',
        'unverified_measurement_ids' => [],
        'contributions' => [
            [
                'measurement_id' => $m06->id,
                'plan_set_id' => $planSetId,
                'plan_line_id' => $june->id,
                'lineage_key' => $june->lineage_key,
                'sequence_number' => 1,
                'measurement_date' => '2026-06-01',
                'percent' => '25.00',
                'legacy' => false,
            ],
            [
                'measurement_id' => $m07->id,
                'plan_set_id' => $planSetId,
                'plan_line_id' => $v2Lines['2026-07']->id,
                'lineage_key' => $scenario['lines']['2026-07']->lineage_key,
                'sequence_number' => 2,
                'measurement_date' => '2026-07-01',
                'percent' => '10.00',
                'legacy' => false,
            ],
        ],
    ]);

    // Exatamente o que resta cabe: o plano chega a 100%, não a um teto por versão.
    Scenario::approveEngineering($underV2, $m08, 35);
    $progress = Scenario::progress($scenario);

    expect($progress->currentPercent())->toBe('100.00')
        ->and($progress->remainingPercent())->toBe('0.00');
});

// ── Leituras do cronograma em vigor ──────────────────────────────────────────

it('offers and tracks only the active version lines, skipping the planned measurement held under V1', function () {
    $scenario = planVersionLinkageScenario();

    planVersionLinkageTravelTo('2026-06-20');
    $m06 = Scenario::measurement($scenario, '2026-06');

    // A V2 tira julho do cronograma (obra parada) e acrescenta outubro.
    planVersionLinkageTravelTo('2026-07-01');
    $v2 = planVersionLinkageRevise($scenario, '23000000.00', fn (array $draftLines): array => [
        ['id' => $draftLines['2026-06']->id, 'sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2026-06'],
        ['id' => $draftLines['2026-08']->id, 'sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '40.00', 'measurement_date' => '2026-08'],
        ['id' => $draftLines['2026-09']->id, 'sequence_number' => 4, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '50.00', 'measurement_date' => '2026-09'],
        ['sequence_number' => 5, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '60.00', 'measurement_date' => '2026-10'],
    ]);
    $v2Lines = planVersionLinkageLinesOf($v2);
    $this->actingAs($scenario['actor']);

    // Julho só existe na V1, e a cópia de junho está ocupada pela M06: a
    // próxima competência pendente e as opções do envio vêm da V2, a partir
    // de agosto. O planejado de cada opção é o acumulado que a ativação
    // gravou, e não o do rascunho: os 30% executados, mais os 10% de junho --
    // a M06 ocupa junho, mas a Engenharia ainda não a aprovou, então junho
    // continua a medir, atrasado, antes da vigência -- e o mensal de cada mês.
    expect(array_keys($v2Lines))->toBe(['2026-06', '2026-08', '2026-09', '2026-10'])
        ->and(planVersionLinkageNextMonth($scenario['operation']))->toBe('08/2026')
        ->and(planVersionLinkageScheduleOptions($scenario['planSet']))->toBe([
            $v2Lines['2026-08']->id => 'Medição 03 · 08/2026 · 50,0% planejado',
            $v2Lines['2026-09']->id => 'Medição 04 · 09/2026 · 60,0% planejado',
            $v2Lines['2026-10']->id => 'Medição 05 · 10/2026 · 70,0% planejado',
        ]);

    // O acompanhamento mostra o cronograma em vigor, não o histórico.
    Livewire::test(PlanLinesRelationManager::class, ['ownerRecord' => $scenario['operation'], 'pageClass' => ViewOperation::class])
        ->assertCanSeeTableRecords(array_values($v2Lines))
        ->assertCanNotSeeTableRecords(array_values($scenario['lines']))
        ->assertCountTableRecords(4);

    // Recusada a M06, a cópia de junho na V2 volta a ser a próxima pendente.
    app(MeasurementWorkflow::class)->reject($m06->fresh(), $scenario['actor'], 'Arquivo ilegível: reenviar a medição de junho.');

    expect(planVersionLinkageNextMonth($scenario['operation']))->toBe('06/2026');
});
