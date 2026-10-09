<?php

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuOperationalCondition;
use App\Domain\PuCalculator\DTOs\PuOperationalSnapshot;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Domain\PuCalculator\Enums\PuMonitorRunStatus;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalEligibility;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Exceptions\PuRateDomainException;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuIndexPublicationPolicy;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use App\Domain\PuCalculator\Services\PuOperationalIncidentService;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuSettlement;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuIndexSyncAttempt;
use App\Models\PuMonitorRun;
use App\Models\PuObligationRefreshRequest;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 6 -- monitoramento operacional do PU.
 *
 * Toda falha, divergência, cálculo indisponível ou reprocessamento pendente com
 * peso financeiro tem que ser visível, classificado e rastreável -- e nada do que o
 * monitor faz muda a verdade financeira oficial. Cenário da Fase 5: CDI + 6% a.a.,
 * defasagem de 1 dia útil, calendário B3 de fins de semana, oficial realizada até
 * segunda 16/03/2026 (CDI publicado de 27/02 a 13/03). O CDI de 16/03 é esperado
 * a partir de terça 17/03 às 07:00 (Brasília).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'pu_indexes.bcb.series.cdi.available_after' => '07:00',
        'pu_indexes.bcb.series.cdi.publication_lag_business_days' => 1,
    ]);
});

function p6At(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'America/Sao_Paulo');
}

/**
 * @param  list<array{0: PuEventType, 1: string, 2?: array<string, mixed>}>  $events
 * @return array{0: Emission, 1: EmissionPuCurveVersion}
 */
function p6Official(array $events = []): array
{
    $emission = Fx::emission($events);
    $official = Fx::official($emission);

    return [$emission->fresh(), $official->fresh()];
}

function p6Snapshot(): PuOperationalSnapshot
{
    return app(PuOperationalHealthService::class)->snapshot();
}

function p6Run(): PuMonitorRun
{
    return app(PuOperationalMonitor::class)->run('test');
}

/**
 * @return list<PuOperationalCondition>
 */
function p6Conditions(PuOperationalConditionType $type, ?PuOperationalSnapshot $snapshot = null): array
{
    return ($snapshot ?? p6Snapshot())->conditionsOfType($type);
}

/**
 * @return Collection<int, PuOperationalIncident>
 */
function p6Incidents(PuOperationalConditionType $type, bool $openOnly = true)
{
    return PuOperationalIncident::query()
        ->where('type', $type->value)
        ->when($openOnly, fn ($query) => $query->open())
        ->orderBy('id')
        ->get();
}

/**
 * Uma consulta à fonte do CDI terminada no instante informado (Brasília).
 */
function p6SyncAttempt(string $finishedLocal, PuIndexSyncOutcome $outcome = PuIndexSyncOutcome::NoNewObservation, ?PuOperationalFailureCategory $category = null): PuIndexSyncAttempt
{
    // Gravado no fuso técnico (UTC), como a aplicação grava.
    $finished = p6At($finishedLocal)->utc();

    return PuIndexSyncAttempt::query()->create([
        'indexer' => PuIndexer::Cdi->value,
        'source' => 'bcb_sgs',
        'requested_from' => '2016-03-17',
        'requested_to' => $finished->toDateString(),
        'started_at' => $finished->subMinute(),
        'finished_at' => $finished,
        'outcome' => $outcome,
        'failure_category' => $category,
        'error_message' => $category !== null ? 'Falha de conexão/timeout ao consultar a série 4389 no Banco Central.' : null,
    ]);
}

function p6Extend(Emission $emission): void
{
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
}

function p6Admin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('D4: keeps a completed official curve visible as NewVersionRequired when a later maturity extension is registered', function () {
    Queue::fake();
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-13'],
        [PuEventType::Amortization, '2026-03-13', ['amortization_type' => PuAmortizationType::Residual->value, 'amortization_value' => null, 'sequence' => 2]],
    ]);
    $emission->puParameter->update(['curve_end_date' => '2026-03-13']);
    $official = Fx::official($emission->fresh());
    $this->travelTo(p6At('2026-03-16 10:00'));

    expect(app(PuOfficialCurveFreshnessService::class)->status($emission->fresh())->freshness)->toBe(PuOfficialCurveFreshness::Complete)
        ->and(p6Conditions(PuOperationalConditionType::NewVersionRequired))->toBe([]);

    // Prorrogação do vencimento registrada DEPOIS de a v1 completar o horizonte aprovado.
    $emission->puParameter->update(['curve_end_date' => '2026-05-29']);
    $versionsBefore = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count();
    $status = app(PuOfficialCurveFreshnessService::class)->status($emission->fresh());
    $snapshot = p6Snapshot();
    [$condition] = p6Conditions(PuOperationalConditionType::NewVersionRequired, $snapshot);
    p6Run();
    p6Run();
    $incidents = p6Incidents(PuOperationalConditionType::NewVersionRequired);
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::NewVersionRequired)
        ->and($status->contractualChangeFrom?->toDateString())->toBe('2026-03-14')
        // O monitor não confunde "completou o horizonte antigo" com "atual".
        ->and($snapshot->emission($emission->id)->freshness())->toBe(PuOfficialCurveFreshness::NewVersionRequired)
        ->and($snapshot->emission($emission->id)->freshness())->not->toBe(PuOfficialCurveFreshness::Complete)
        ->and($condition->severity)->toBe(PuOperationalSeverity::Warning)
        ->and($condition->emissionId)->toBe($emission->id)
        ->and($condition->curveVersionId)->toBe($official->id)
        ->and($condition->reason)->toContain('nova versão')
        ->and($condition->context['contractual_change_from'])->toBe('2026-03-14')
        // A contagem acionável da tela/alerta, que antes só via a marca da extensão.
        ->and(app(PuOperationalMonitorService::class)->pendingContractualChangeCount())->toBe(1)
        ->and(app(PuOperationalMonitorService::class)->criticalSummary())->toContain('1 curva(s) oficial(is) com mudanca contratual nao homologada: o PU oficial para na vespera da data afetada ate uma nova versao ser homologada.')
        ->and($incidents)->toHaveCount(1)
        ->and($incidents->first()->incident_key)->toBe('new_version_required:emission:'.$emission->id)
        ->and($incidents->first()->curve_version_id)->toBe($official->id)
        ->and($incidents->first()->detection_count)->toBe(2)
        // Nada gerado nem homologado sozinho.
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe($versionsBefore)
        ->and($official->fresh()->status)->toBe(PuCurveStatus::Homologated);

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);
});

it('opens no missing-CDI incident before the configured publication time, and fabricates nothing', function (string $local) {
    [$emission, $official] = p6Official();
    $this->travelTo(p6At($local));
    $rows = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->count();

    $status = app(PuOfficialCurveFreshnessService::class)->status($emission->fresh());
    $run = p6Run();

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($status->expectedLatestRateDate?->toDateString())->toBe('2026-03-13')
        ->and($run->status)->toBe(PuMonitorRunStatus::Succeeded)
        ->and(PuOperationalIncident::query()->count())->toBe(0)
        ->and(p6Conditions(PuOperationalConditionType::OfficialCurveMissingIndex))->toBe([])
        ->and(IndexRate::query()->whereDate('rate_date', '>', '2026-03-13')->exists())->toBeFalse()
        ->and(EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->count())->toBe($rows);
})->with([
    'madrugada, quando a sincronização das 06:30 UTC roda' => ['2026-03-17 03:30'],
    'um minuto antes da divulgação' => ['2026-03-17 06:59'],
    'UTC já passou das 07:00, Brasília não (09:30 UTC)' => ['2026-03-17 06:30'],
    'virada do dia em Brasília (02:30 UTC de 17/03)' => ['2026-03-16 23:30'],
]);

it('treats a CDI expected but not yet looked for as awaiting synchronization, never as missing', function () {
    [$emission] = p6Official();
    $this->travelTo(p6At('2026-03-17 07:20'));

    $snapshot = p6Snapshot();
    [$condition] = p6Conditions(PuOperationalConditionType::OfficialCurveMissingIndex, $snapshot);
    p6Run();

    // A atualidade (consumidores) já não toma o PU de hoje como atual...
    expect($snapshot->emission($emission->id)->freshness())->toBe(PuOfficialCurveFreshness::MissingIndex)
        // ...mas ninguém consultou a fonte depois das 07:00: não é incidente.
        ->and($condition->severity)->toBe(PuOperationalSeverity::Info)
        ->and($condition->context['awaiting_synchronization'])->toBeTrue()
        ->and($condition->context['expected_available_at'])->toBe(p6At('2026-03-17 07:00')->utc()->toIso8601String())
        ->and(p6Conditions(PuOperationalConditionType::IndexAwaitingSynchronization, $snapshot))->toHaveCount(1)
        ->and(PuOperationalIncident::query()->count())->toBe(0);
});

it('opens exactly one missing-index incident once the source was consulted after the expected publication and did not bring the CDI', function () {
    [$emission, $official] = p6Official();
    p6SyncAttempt('2026-03-17 07:31');
    $this->travelTo(p6At('2026-03-17 08:00'));

    p6Run();
    $this->travelTo(p6At('2026-03-17 08:15'));
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::OfficialCurveMissingIndex)->sole();
    $indexLevel = p6Incidents(PuOperationalConditionType::IndexObservationMissing)->sole();

    expect($incident->severity)->toBe(PuOperationalSeverity::Warning)
        ->and($incident->emission_id)->toBe($emission->id)
        ->and($incident->curve_version_id)->toBe($official->id)
        ->and($incident->business_date?->toDateString())->toBe('2026-03-16')
        ->and($incident->context['awaiting_synchronization'])->toBeFalse()
        ->and($incident->context['sync_outcome'])->toBe(PuIndexSyncOutcome::NoNewObservation->value)
        ->and($incident->detection_count)->toBe(2)
        ->and($indexLevel->indexer)->toBe('CDI')
        ->and($indexLevel->reason)->toContain('Nenhum CDI é repetido nem projetado')
        // Nenhuma projeção do CDI para esconder a falta.
        ->and(IndexRate::query()->where('is_projected', true)->exists())->toBeFalse()
        ->and(IndexRate::query()->whereDate('rate_date', '2026-03-16')->exists())->toBeFalse()
        ->and(Fx::lastDate($official))->toBe('2026-03-16');
});

it('resolves the missing-index incident only when the CDI arrives and the official curve extends, and reopens a recurrence as a new incident', function () {
    [$emission, $official] = p6Official();
    p6SyncAttempt('2026-03-17 07:31');
    $this->travelTo(p6At('2026-03-17 08:00'));
    p6Run();
    $first = p6Incidents(PuOperationalConditionType::OfficialCurveMissingIndex)->sole();

    // O CDI chega: a oficial fica atrasada (índice existe, extensão ainda não rodou).
    Fx::publish('2026-03-16', '2026-03-16');
    p6SyncAttempt('2026-03-17 08:20', PuIndexSyncOutcome::NewObservations);
    $this->travelTo(p6At('2026-03-17 08:30'));
    p6Run();
    $whileStale = p6Snapshot()->emission($emission->id)->freshness();

    p6Extend($emission);
    p6Run();

    expect($whileStale)->toBe(PuOfficialCurveFreshness::Stale)
        ->and($first->fresh()->status)->toBe(PuIncidentStatus::Resolved)
        ->and($first->fresh()->resolution)->toBe('condition_cleared')
        ->and(app(PuOfficialCurveFreshnessService::class)->status($emission->fresh())->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and(Fx::lastDate($official))->toBe('2026-03-17')
        ->and(PuOperationalIncident::query()->open()->count())->toBe(0);

    // No dia seguinte o CDI de 17/03 falta de novo: reincidência ligada à primeira.
    p6SyncAttempt('2026-03-18 07:31');
    $this->travelTo(p6At('2026-03-18 08:00'));
    p6Run();
    $recurrence = p6Incidents(PuOperationalConditionType::OfficialCurveMissingIndex)->sole();

    expect($recurrence->id)->not->toBe($first->id)
        ->and($recurrence->recurrence_of_id)->toBe($first->id)
        ->and($recurrence->business_date?->toDateString())->toBe('2026-03-17')
        ->and(p6Incidents(PuOperationalConditionType::OfficialCurveMissingIndex, openOnly: false))->toHaveCount(2);
});

it('records a structured extension failure, escalates it to critical when it repeats and keeps the official history intact', function () {
    config(['pu_calculator.monitoring.extension_failure_critical_after' => 3]);
    [$emission, $official] = p6Official();
    Fx::publish('2026-03-16', '2026-03-18');
    $this->travelTo(p6At('2026-03-19 10:00'));
    $rowsBefore = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->orderBy('id')->pluck('residual_unit_value', 'id')->all();
    app()->instance(PuCurveGeneratorService::class, new class extends PuCurveGeneratorService
    {
        public function __construct() {}

        public function handle(
            Emission $emission,
            ?string $indexRateCalendarCode = null,
            ?string $accrualCalendarCode = null,
            PuCalculationProfile $profile = PuCalculationProfile::Contractual,
        ): PuCurveGenerationResult {
            throw PuRateDomainException::nonPositiveCompoundingBase('-100.5', '-0.005');
        }
    });

    foreach ([1, 2] as $attempt) {
        expect(fn () => p6Extend($emission))->toThrow(PuRateDomainException::class);
    }

    p6Run();
    $warning = p6Incidents(PuOperationalConditionType::OfficialExtensionFailed)->sole();

    expect(fn () => p6Extend($emission))->toThrow(PuRateDomainException::class);
    p6Run();
    $critical = $warning->fresh();
    $failure = $official->fresh()->extension_failure;

    expect($warning->severity)->toBe(PuOperationalSeverity::Warning)
        ->and($critical->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($critical->id)->toBe($warning->id)
        ->and($critical->context['consecutive_failures'])->toBe(3)
        ->and($critical->context['failure_category'])->toBe(PuOperationalFailureCategory::Numerical->value)
        ->and($critical->context['retryable'])->toBeFalse()
        ->and($failure['category'])->toBe(PuOperationalFailureCategory::Numerical->value)
        ->and(app(PuOfficialCurveFreshnessService::class)->status($emission->fresh())->freshness)->not->toBe(PuOfficialCurveFreshness::Current)
        ->and(EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->orderBy('id')->pluck('residual_unit_value', 'id')->all())->toBe($rowsBefore);

    // Causa removida: a próxima extensão roda, limpa a falha e o incidente se resolve.
    app()->forgetInstance(PuCurveGeneratorService::class);
    app()->forgetInstance(PuCurveExtensionService::class);
    p6Extend($emission);
    p6Run();

    expect($official->fresh()->extension_failed_at)->toBeNull()
        ->and(Fx::lastDate($official))->toBe('2026-03-19')
        ->and($critical->fresh()->status)->toBe(PuIncidentStatus::Resolved);
});

it('keeps historical reprocessing visible with its cause until a governed new version is homologated', function () {
    [$emission, $official] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $this->travelTo(p6At('2026-03-17 06:00'));
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-04', '15.10000000', 'Revisão do BCB.', User::factory()->create()->id);

    p6Run();
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::ReprocessingRequired)->sole();
    $homologatedBefore = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->homologated()->count();

    expect($incident->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($incident->context['cause'])->toBe('index_rate_corrected')
        ->and($incident->context['reprocessing_from'])->not->toBeNull()
        ->and($incident->detection_count)->toBe(2)
        ->and($official->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($homologatedBefore)->toBe(1);

    // Uma nova versão gerada NÃO resolve: só a homologação dela.
    $v2 = Fx::generate($emission->fresh());
    p6Run();

    expect($incident->fresh()->status)->toBe(PuIncidentStatus::Active);

    Fx::homologate($emission->fresh(), $v2);
    p6Run();

    expect($incident->fresh()->status)->toBe(PuIncidentStatus::Resolved)
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($v2->id);
});

it('stays silent for emissions that are not configured, not launched, inactive or of an unsupported indexer', function () {
    Queue::fake();
    Fx::calendar();
    Fx::publish('2026-02-27', '2026-03-13');
    $notConfigured = Emission::factory()->active()->create(['type' => 'CRI']);
    $notLaunched = Fx::emission(withCalendar: false);
    $inactive = Fx::emission(withCalendar: false);
    $inactive->update(['status' => 'closed']);
    $ipca = Fx::emission(withCalendar: false);
    $ipca->puParameter->update(['indexer' => PuIndexer::Ipca->value, 'annual_rate' => '5.00000000', 'base_index_date' => '2026-03-02', 'index_lag_months' => 2]);
    // Sincronização falhando no ambiente sem nenhuma curva oficial.
    p6SyncAttempt('2026-03-17 07:31', PuIndexSyncOutcome::Failed, PuOperationalFailureCategory::ProviderUnavailable);
    $this->travelTo(p6At('2026-03-17 10:00'));

    $snapshot = p6Snapshot();
    $run = p6Run();
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    expect($run->status)->toBe(PuMonitorRunStatus::Succeeded)
        ->and(PuOperationalIncident::query()->count())->toBe(0)
        ->and($snapshot->emission($notConfigured->id))->toBeNull()
        ->and($snapshot->emission($notLaunched->id)->eligibility)->toBe(PuOperationalEligibility::ConfiguredNotLaunched)
        ->and($snapshot->emission($inactive->id)->eligibility)->toBe(PuOperationalEligibility::Inactive)
        ->and($snapshot->emission($ipca->id)->eligibility)->toBe(PuOperationalEligibility::UnsupportedIndexer)
        ->and(collect($snapshot->actionableConditions())->map(fn (PuOperationalCondition $condition): string => $condition->type->value)->all())->toBe([])
        ->and(p6Conditions(PuOperationalConditionType::IndexSyncFailed, $snapshot)[0]->severity)->toBe(PuOperationalSeverity::Info)
        ->and(EmissionPuCurveVersion::query()->homologated()->exists())->toBeFalse()
        ->and(EmissionPuSettlement::query()->exists())->toBeFalse()
        ->and(app(PuOperationalMonitorService::class)->monitorHealthIssue())->toBeNull();

    // A rotina diária não gera nada para quem não configurou, está inativa ou tem indexador sem homologação.
    Queue::assertNotPushed(GeneratePuDailyCurveJob::class, fn (GeneratePuDailyCurveJob $job): bool => in_array($job->emissionId, [$notConfigured->id, $inactive->id, $ipca->id], true));
});

it('reports a closed settlement below or above the expected value as divergent, never as partial, with the exact difference', function (string $delta) {
    [$emission] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $expected = Fx::expectedTotal($obligation);
    $obligationsBefore = EmissionPuObligation::query()->count();
    $rowsBefore = EmissionPuDailyCurve::query()->pluck('residual_unit_value', 'id')->all();

    Fx::settle($obligation, bcadd($expected, $delta, 2), reference: 'B3-DIV');
    $this->travelTo(p6At('2026-03-17 06:00'));
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::SettlementDivergent)->sole();

    expect($obligation->fresh()->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and(PuSettlementState::cases())->toHaveCount(2)
        ->and($incident->obligation_id)->toBe($obligation->id)
        ->and($incident->severity)->toBe(PuOperationalSeverity::Warning)
        ->and($incident->context['difference'])->toBe(bcadd('0', $delta, 2))
        ->and($incident->context['expected_total'])->toBe($expected)
        ->and($incident->context['kinds'])->toBe(['amount'])
        // Nada de saldo residual, obrigação nova ou principal alterado.
        ->and(EmissionPuObligation::query()->count())->toBe($obligationsBefore)
        ->and(EmissionPuDailyCurve::query()->pluck('residual_unit_value', 'id')->all())->toBe($rowsBefore)
        ->and(p6Incidents(PuOperationalConditionType::ObligationsUnsettledPastDue))->toHaveCount(0);
})->with([
    'liquidado a menor (100 → 95)' => ['-5.00'],
    'liquidado a maior (100 → 105)' => ['5.00'],
]);

it('keeps one incident per open settlement conflict until the governed decision, without picking a winner', function () {
    [$emission] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $expected = Fx::expectedTotal($obligation);
    $original = Fx::settle($obligation, $expected, reference: 'B3-CONF')->settlement;
    $conflict = Fx::settle($obligation, bcsub($expected, '1.00', 2), reference: 'B3-CONF')->conflict;
    $this->travelTo(p6At('2026-03-17 06:00'));

    p6Run();
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::SettlementConflictOpen)->sole();

    expect($incident->settlement_conflict_id)->toBe($conflict->id)
        ->and($incident->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($incident->context['operator_action_required'])->toBeTrue()
        ->and($incident->context['existing_settlement_id'])->toBe($original->id)
        ->and($incident->detection_count)->toBe(2)
        ->and((string) $original->fresh()->amount)->toBe($expected)
        ->and($original->fresh()->status)->toBe(PuSettlementStatus::Active);

    app(PuSettlementService::class)->resolveConflict($conflict->id, false, 'A B3 confirmou o valor original.', Fx::integration());
    p6Run();

    expect($incident->fresh()->status)->toBe(PuIncidentStatus::Resolved)
        ->and((string) $original->fresh()->amount)->toBe($expected);
});

it('distinguishes an indeterminate reconciliation from a matched one while the expected value awaits reprocessing', function () {
    [$emission] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $settlement = Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-IND')->settlement;

    expect($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);

    $this->travelTo(p6At('2026-03-17 06:00'));
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-04', '15.10000000', 'Revisão do BCB.', User::factory()->create()->id);
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::ReconciliationIndeterminate)->sole();

    expect($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired)
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Indeterminate)
        ->and($incident->context['reasons'])->toBe([PuObligationCalculationState::ReprocessingRequired->value => 1])
        ->and($incident->context['settlement_ids'])->toBe([$settlement->id])
        ->and($settlement->fresh()->status)->toBe(PuSettlementStatus::Active)
        ->and(p6Incidents(PuOperationalConditionType::ReprocessingRequired))->toHaveCount(1);
});

it('classifies an unsupported financial effect without a calculated zero, a reconciliation or a retry loop', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => '2026-03-09',
        'premium_value' => '0.00',
        'interest_value' => '0.00',
        'amortization_value' => '0.00',
        'extra_amortization_value' => '25.00',
    ]);
    Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($obligation, '9999.00', reference: 'B3-UNS');
    $this->travelTo(p6At('2026-03-17 06:00'));

    p6Run();
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::ObligationUnsupportedEffect)->sole();

    expect($obligation->fresh()->calculation_state)->toBe(PuObligationCalculationState::Unsupported)
        ->and($obligation->fresh()->currentCalculation->total_amount)->toBeNull()
        ->and($obligation->fresh()->reconciliation_status)->not->toBe(PuReconciliationStatus::Matched)
        ->and($incident->context['retryable'])->toBeFalse()
        ->and($incident->detection_count)->toBe(2)
        ->and(PuObligationRefreshRequest::query()->open()->count())->toBe(0);
});

it('does not resolve incidents of a check that failed, and records the monitor run as not completed', function () {
    [$emission] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($obligation, bcsub(Fx::expectedTotal($obligation), '5.00', 2), reference: 'B3-FAIL');
    $this->travelTo(p6At('2026-03-17 06:00'));
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::SettlementDivergent)->sole();

    // A verificação das obrigações quebra na rodada seguinte.
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'reconciliation_status') && str_contains($query->sql, 'group by')) {
            throw new RuntimeException('Banco indisponível no meio da verificação (simulado).');
        }
    });
    $partial = p6Run();

    expect($partial->status)->toBe(PuMonitorRunStatus::Partial)
        ->and($partial->checks['obligations']['status'])->toBe('failed')
        ->and($incident->fresh()->status)->toBe(PuIncidentStatus::Active)
        ->and(app(PuOperationalMonitorService::class)->monitorHealthIssue())->toContain('nao concluiu a ultima verificacao');
});

it('records a monitor run that could not complete at all as failed, keeping every open incident', function () {
    [$emission] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($obligation, bcsub(Fx::expectedTotal($obligation), '5.00', 2), reference: 'B3-FAIL2');
    $this->travelTo(p6At('2026-03-17 06:00'));
    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::SettlementDivergent)->sole();
    // Gravar incidentes quebra: a rodada inteira não conclui.
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'pu_operational_incidents') && str_contains($query->sql, 'open_key')) {
            throw new RuntimeException('Falha ao gravar incidentes (simulado).');
        }
    });

    $failed = p6Run();
    $this->artisan('pu:operations:monitor')->assertFailed();

    expect($failed->status)->toBe(PuMonitorRunStatus::Failed)
        ->and($failed->error)->toContain('Falha ao gravar incidentes')
        ->and($incident->fresh()->status)->toBe(PuIncidentStatus::Active)
        ->and(app(PuOperationalMonitorService::class)->criticalSummary())->toContain('O monitor operacional do PU nao concluiu a ultima verificacao (falhou): incidentes podem estar desatualizados.');
});

it('lets an authorized operator acknowledge an incident without resolving it, and re-notifies only on escalation', function () {
    $admin = p6Admin();
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    config(['pu_calculator.monitoring.extension_failure_critical_after' => 2]);
    [$emission, $official] = p6Official();
    Fx::publish('2026-03-16', '2026-03-18');
    $this->travelTo(p6At('2026-03-19 10:00'));
    $official->forceFill([
        'extension_failed_at' => now(),
        'extension_failure' => ['action' => 'error', 'purpose' => 'official', 'reason' => 'Falha simulada.', 'category' => 'unknown', 'retryable' => true, 'consecutive_failures' => 1],
    ])->save();

    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::OfficialExtensionFailed)->sole();
    $notifications = fn (User $user): int => $user->notifications()->count();

    expect($incident->notified_at)->not->toBeNull()
        ->and($notifications($admin))->toBe(1)
        ->and($notifications($editor))->toBe(0)
        ->and(fn () => app(PuOperationalIncidentService::class)->acknowledge($incident, $editor, 'tentativa'))->toThrow(AuthorizationException::class);

    app(PuOperationalIncidentService::class)->acknowledge($incident, $admin, 'Investigando o worker.');
    p6Run();

    expect($incident->fresh()->status)->toBe(PuIncidentStatus::Acknowledged)
        ->and($incident->fresh()->resolved_at)->toBeNull()
        ->and($notifications($admin))->toBe(1)
        ->and(Activity::query()->where('event', 'operational_incident_acknowledged')->sole()->causer_id)->toBe($admin->id);

    // A falha se repete: escala para crítico, volta a ativo e avisa de novo.
    $official->fresh()->forceFill(['extension_failure' => [...$official->fresh()->extension_failure, 'consecutive_failures' => 2]])->save();
    p6Run();

    expect($incident->fresh()->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($incident->fresh()->status)->toBe(PuIncidentStatus::Active)
        ->and($notifications($admin))->toBe(2);
});

it('keeps an incident due for notification when nobody can receive it, without hiding it', function () {
    [$emission, $official] = p6Official();
    Fx::publish('2026-03-16', '2026-03-18');
    $this->travelTo(p6At('2026-03-19 10:00'));
    $official->forceFill([
        'extension_failed_at' => now(),
        'extension_failure' => ['action' => 'error', 'purpose' => 'official', 'reason' => 'Falha simulada.', 'category' => 'unknown', 'retryable' => true, 'consecutive_failures' => 1],
    ])->save();

    p6Run();
    $incident = p6Incidents(PuOperationalConditionType::OfficialExtensionFailed)->sole();

    expect($incident->notified_at)->toBeNull()
        ->and($incident->notification_error)->toContain('pu.operations.monitor')
        ->and(app(PuOperationalIncidentService::class)->needsNotification($incident))->toBeTrue();
});

it('decides CDI publication on the publication calendar: weekend, holiday and the first business day after it', function (string $local, string $expected) {
    Fx::calendar();
    BusinessCalendarDate::query()->where('calendar_code', 'B3')->whereDate('calendar_date', '2026-03-18')->update(['is_business_day' => false]);
    app(BusinessDayCalendarService::class)->flushCache();
    $policy = app(PuIndexPublicationPolicy::class);

    expect($policy->expectedLatestRateDate('B3', p6At($local))->toDateString())->toBe($expected);
})->with([
    'terça 06:59, antes da divulgação' => ['2026-03-17 06:59', '2026-03-13'],
    'terça 07:00, divulgação esperada' => ['2026-03-17 07:00', '2026-03-16'],
    'quarta (feriado) às 10:00' => ['2026-03-18 10:00', '2026-03-16'],
    'quinta, primeiro dia útil depois do feriado, antes das 07:00' => ['2026-03-19 06:00', '2026-03-16'],
    'quinta, primeiro dia útil depois do feriado, depois das 07:00' => ['2026-03-19 08:00', '2026-03-17'],
    'sábado' => ['2026-03-21 10:00', '2026-03-19'],
    'segunda 06:00' => ['2026-03-23 06:00', '2026-03-19'],
    'segunda 07:30' => ['2026-03-23 07:30', '2026-03-20'],
]);

it('expects each observation from the configured hour of its publication business day', function () {
    Fx::calendar();
    BusinessCalendarDate::query()->where('calendar_code', 'B3')->whereDate('calendar_date', '2026-03-18')->update(['is_business_day' => false]);
    app(BusinessDayCalendarService::class)->flushCache();
    $policy = app(PuIndexPublicationPolicy::class);

    expect($policy->expectedAvailabilityAt(CarbonImmutable::parse('2026-03-16'), 'B3')->toIso8601String())->toBe(p6At('2026-03-17 07:00')->utc()->toIso8601String())
        ->and($policy->expectedAvailabilityAt(CarbonImmutable::parse('2026-03-17'), 'B3')->toIso8601String())->toBe(p6At('2026-03-19 07:00')->utc()->toIso8601String())
        ->and($policy->expectedAvailabilityAt(CarbonImmutable::parse('2026-03-20'), 'B3')->toIso8601String())->toBe(p6At('2026-03-23 07:00')->utc()->toIso8601String());
});

it('exposes a machine-readable snapshot with the operational fields of each emission', function () {
    [$emission, $official] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $this->travelTo(p6At('2026-03-17 06:00'));

    $health = p6Snapshot()->emission($emission->id)->toArray();
    $this->artisan('pu:operations:status', ['--json' => true])->assertSuccessful();

    expect($health)->toMatchArray([
        'emission_id' => $emission->id,
        'eligibility' => PuOperationalEligibility::Operational->value,
        'indexer' => 'CDI',
        'indexer_operational' => true,
        'official_version_id' => $official->id,
        'freshness' => PuOfficialCurveFreshness::Current->value,
        'realized_through' => '2026-03-16',
        'expected_latest_rate_date' => '2026-03-13',
        'reprocessing_required' => false,
    ])
        // O cupom de 09/03 venceu sem liquidação registrada: é o que bloqueia.
        ->and($health['blocking_reason'])->toContain('vencida(s) sem liquidação')
        ->and($health['obligations']['calculation_state:calculated'])->toBe(1)
        ->and($health['obligations']['settlement_state:unsettled'])->toBeGreaterThanOrEqual(1)
        ->and($health['last_successful_operations']['homologated_at'])->not->toBeNull();
});

it('reads the official PU reader unchanged while monitoring: monitoring never writes financial facts', function () {
    [$emission, $official] = p6Official([[PuEventType::InterestPayment, '2026-03-09']]);
    $this->travelTo(p6At('2026-03-17 10:00'));
    $calculations = EmissionPuObligationCalculation::query()->count();
    $rows = EmissionPuDailyCurve::query()->count();
    $rates = IndexRate::query()->count();

    p6Run();
    p6Run();

    expect(EmissionPuObligationCalculation::query()->count())->toBe($calculations)
        ->and(EmissionPuDailyCurve::query()->count())->toBe($rows)
        ->and(IndexRate::query()->count())->toBe($rates)
        ->and(EmissionPuSettlement::query()->count())->toBe(0)
        ->and(app(EmissionPuReader::class)->officialVersion($emission->fresh())?->id)->toBe($official->id);
});
