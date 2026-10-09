<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Calculators\IpcaCurveCalculator;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationPlan;
use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Exceptions\PuIndexerCapabilityException;
use App\Domain\PuCalculator\Services\PuCandidateCurveService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurvePersistenceService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use App\Domain\PuCalculator\Services\PuIndexerCapabilityPolicy;
use App\Domain\PuCalculator\Services\PuIpcaHomologationStatusService;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Domain\PuCalculator\Services\PuOperationalMonitor;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\IndexRate;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 6 (P0-05) -- indexador sem homologação financeira e operacional própria
 * nunca produz PU oficial. IPCA continua só na simulação (a engine em memória,
 * para conferência contra gabarito); nenhum caminho -- tela, job, comando,
 * serviço direto, candidata, validação, homologação, extensão, obrigações -- o
 * leva a uma versão, ao PU oficial ou a um valor esperado. O CDI validado segue
 * funcionando de ponta a ponta.
 */
uses(RefreshDatabase::class);

/**
 * Cenário IPCA mínimo e completo (curva 01..03/01/2024, defasagem 2 meses,
 * projeção de série aprovada): todos os pré-requisitos de dado presentes, para
 * que a única recusa seja a do indexador.
 */
function p6iIpcaEmission(): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    for ($date = CarbonImmutable::parse('2024-01-01'); $date->lte(CarbonImmutable::parse('2024-01-03')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create(['calendar_code' => 'B3', 'calendar_date' => $date->toDateString(), 'is_business_day' => ! $date->isWeekend(), 'description' => null]);
    }

    $emission->integralizationHistories()->create(['date' => '2024-01-01', 'quantity' => '100.0000', 'unit_value' => '1000.00000000', 'financial_value' => '100000.00', 'investor_fund' => 'Head Invest']);
    $emission->puParameter()->create([
        'curve_start_date' => '2024-01-01',
        'curve_end_date' => '2024-01-03',
        'initial_unit_value' => '1000.0000000000000000',
        'annual_rate' => '5.00000000',
        'indexer' => PuIndexer::Ipca->value,
        'base_index_date' => '2024-01-01',
        'index_lag_months' => 2,
        'correction_frequency' => 'monthly',
        'index_projection_policy' => IpcaProjectionPolicy::PublishedOnly->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    foreach (['2023-09-01' => '6500.00000000', '2023-10-01' => '6550.00000000', '2023-11-01' => '6600.00000000'] as $month => $value) {
        IndexRate::query()->create(['indexer' => PuIndexer::Ipca->value, 'rate_date' => $month, 'rate_value' => $value, 'source' => 'manual_import', 'is_projected' => false]);
    }

    return $emission->fresh();
}

/**
 * Curva oficial anterior ao portão: uma oficial de CDI que, no banco, passa a
 * declarar IPCA -- o estado que só um dado antigo teria.
 *
 * @return array{0: Emission, 1: EmissionPuCurveVersion}
 */
function p6iLegacyIpcaOfficial(): array
{
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $official = Fx::official($emission);
    DB::table('emission_pu_parameters')->where('emission_id', $emission->id)->update(['indexer' => PuIndexer::Ipca->value]);
    DB::table('emission_pu_curve_versions')->where('id', $official->id)->update([
        'curve_inputs' => null,
        'curve_inputs_fingerprint' => null,
        'curve_inputs_schema' => null,
        'parameters_snapshot' => json_encode(['indexer' => PuIndexer::Ipca->value]),
    ]);

    return [$emission->fresh(), $official->fresh()];
}

it('declares the operational capability matrix: CDI and prefixed as declared homologated, IPCA only for simulation', function () {
    $matrix = app(PuIndexerCapabilityPolicy::class)->matrix();

    expect($matrix['CDI'])->each->toBeTrue()
        ->and($matrix['PREFIXED'])->each->toBeTrue()
        ->and($matrix['IPCA'][PuIndexerCapability::Simulation->value])->toBeTrue()
        ->and(collect($matrix['IPCA'])->except(PuIndexerCapability::Simulation->value)->unique()->values()->all())->toBe([false])
        ->and(app(PuIndexerCapabilityPolicy::class)->allows(null, PuIndexerCapability::Homologation))->toBeFalse();
});

it('blocks IPCA curve generation at the prerequisites with a structured reason', function () {
    $emission = p6iIpcaEmission();

    $check = app(PuCurvePrerequisiteService::class)->handle($emission);

    expect($check->passes())->toBeFalse()
        ->and(collect($check->toArray()['blocking'])->pluck('key')->all())->toBe(['indexer_not_operationally_homologated'])
        ->and($check->blockingSummary())->toContain(PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED)
        ->and($check->blockingSummary())->toContain('IPCA');
});

it('refuses IPCA generation through the action, the queued job and a direct persistence call, writing nothing', function () {
    $emission = p6iIpcaEmission();
    $user = User::factory()->create();

    expect(fn () => app(GeneratePuDailyCurve::class)->handle($emission))->toThrow(InvalidArgumentException::class, PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED);

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);

    // Nem a engine em memória entra pela porta da gravação.
    $rows = app(IpcaCurveCalculator::class)->calculate($emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']))->rows;

    try {
        app(PuCurvePersistenceService::class)->handle($emission, new PuCurveGenerationResult($rows));
        $refused = null;
    } catch (PuIndexerCapabilityException $exception) {
        $refused = $exception;
    }

    expect(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->exists())->toBeFalse()
        ->and(Cache::get("pu_curve_generation_{$emission->id}_status")['status'])->toBe('failed')
        ->and(Cache::get("pu_curve_generation_{$emission->id}_status")['error'])->toContain(PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED)
        ->and($refused)->toBeInstanceOf(PuIndexerCapabilityException::class)
        ->and($refused->context())->toMatchArray([
            'reason' => PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED,
            'indexer' => 'IPCA',
            'capability' => PuIndexerCapability::CurveGeneration->value,
            'emission_id' => $emission->id,
        ])
        ->and($refused->nextAction)->toContain('homologação');
});

it('keeps the IPCA engine available for simulation only, without any persisted curve', function () {
    $emission = p6iIpcaEmission();

    $result = app(IpcaCurveCalculator::class)->calculate($emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']));

    expect($result->rows)->toHaveCount(3)
        ->and(EmissionPuCurveVersion::query()->exists())->toBeFalse();
});

it('refuses the IPCA candidate path before any engine call', function () {
    $emission = p6iIpcaEmission();
    $plan = new PuNumericHomologationPlan(
        emissionId: $emission->id,
        asOf: '2024-01-03',
        readiness: 'ready',
        requiredReadiness: 'ready',
        action: 'ready',
        reason: 'teste',
        canEvaluate: true,
        parameterId: $emission->puParameter->id,
        parameterSnapshot: [],
        curveStartDate: '2024-01-01',
        curveEndDate: '2024-01-03',
        homologationEndDate: '2024-01-03',
        calendarWindow: [],
        rateWindow: [],
        requiredRateDates: [],
        rates: [],
        events: [],
        inputFingerprint: null,
        inputPayload: [],
        existingCurves: [],
        externalReference: [],
    );

    expect(fn () => app(PuCandidateCurveService::class)->generate($emission, $plan))->toThrow(PuIndexerCapabilityException::class);
});

it('refuses to validate, homologate or extend an official curve of an indexer without operational homologation', function () {
    [$emission, $official] = p6iLegacyIpcaOfficial();
    $candidate = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v9',
        'status' => PuCurveStatus::Validated->value,
        'parameters_snapshot' => ['indexer' => PuIndexer::Ipca->value],
        'generated_by' => User::factory()->create()->id,
    ]);

    expect(fn () => app(HomologatePuCurve::class)->handle($emission, 'v9', User::factory()->create()->id, 'Tentativa.'))
        ->toThrow(PuIndexerCapabilityException::class, 'INDEXER_NOT_OPERATIONALLY_HOMOLOGATED')
        ->and(fn () => app(PuCurveVersionService::class)->markValidated($candidate, true, [], User::factory()->create()->id))
        ->toThrow(PuIndexerCapabilityException::class);

    $extension = app(PuCurveExtensionService::class)->extendOfficial($emission);

    expect($candidate->fresh()->status)->toBe(PuCurveStatus::Validated)
        ->and($candidate->fresh()->homologated_at)->toBeNull()
        ->and($extension->action)->toBe(PuCurveExtensionService::ACTION_NOT_EXTENDABLE)
        ->and($official->fresh()->extension_failure)->not->toBeNull()
        ->and(app(PuIpcaHomologationStatusService::class)->isOperationallyHomologated($emission))->toBeFalse();
});

it('fails the obligations of a legacy unsupported official curve closed: no calculated zero, no reconciliation, visible as critical', function () {
    [$emission, $official] = p6iLegacyIpcaOfficial();
    $calculationsBefore = EmissionPuObligationCalculation::query()->where('obligation_id', Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09')->id)->count();

    $this->artisan('pu:obligations:reconcile', ['--emission' => [$emission->id]])->assertSuccessful();
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    app(PuOperationalMonitor::class)->run('test');
    $incident = PuOperationalIncident::query()->open()->where('type', PuOperationalConditionType::UnsupportedIndexerOfficialCurve->value)->sole();

    expect($obligation->calculation_state)->toBe(PuObligationCalculationState::Unsupported)
        ->and($obligation->calculation_state_reason)->toContain(PuIndexerCapabilityException::REASON_NOT_HOMOLOGATED)
        // Nenhum cálculo novo nasce da curva sem homologação operacional, e nenhum zero.
        ->and(EmissionPuObligationCalculation::query()->where('obligation_id', $obligation->id)->count())->toBe($calculationsBefore)
        ->and(EmissionPuObligationCalculation::query()->where('obligation_id', $obligation->id)->where('total_amount', 0)->exists())->toBeFalse()
        ->and($obligation->reconciliation_status)->not->toBe(PuReconciliationStatus::Matched)
        ->and($incident->severity)->toBe(PuOperationalSeverity::Critical)
        ->and($incident->curve_version_id)->toBe($official->id)
        ->and(app(PuOperationalMonitorService::class)->unsupportedIndexerOfficialCurveCount())->toBe(1);
});

it('never lets the daily routine touch an IPCA emission', function () {
    Queue::fake();
    $emission = p6iIpcaEmission();

    $this->artisan('pu:curves:generate-realized', ['--emission' => $emission->id])->assertSuccessful();

    Queue::assertNothingPushed();
});

it('keeps the validated CDI flow working end to end: configure, generate, validate, homologate, extend, oblige, settle, reconcile, monitor', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09'], [PuEventType::InterestPayment, '2026-03-20']]);
    $version = Fx::generate($emission);
    app(PuCurveVersionService::class)->markValidated($version, true, ['source' => 'conferência'], User::factory()->create()->id);
    $official = Fx::homologate($emission, $version);
    Fx::publish('2026-03-16', '2026-03-20');
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $first = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $second = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-20');
    Fx::settle($first, Fx::expectedTotal($first), reference: 'B3-CDI-1');
    Fx::settle($second, Fx::expectedTotal($second), date: '2026-03-20', reference: 'B3-CDI-2');
    $this->travelTo(CarbonImmutable::parse('2026-03-23 06:00', 'America/Sao_Paulo'));
    $run = app(PuOperationalMonitor::class)->run('test');

    expect($official->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and(Fx::lastDate($official))->toBe('2026-03-23')
        ->and(app(PuOfficialCurveFreshnessService::class)->status($emission->fresh())->freshness)->toBe(PuOfficialCurveFreshness::Current)
        ->and($first->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched)
        ->and($second->fresh()->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and($second->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched)
        ->and($run->incidents_opened)->toBe(0)
        ->and(PuOperationalIncident::query()->count())->toBe(0)
        ->and(app(PuFinancialObligationService::class)->refresh($emission->fresh(), 'verification')->changedAnything())->toBeFalse()
        ->and(EmissionPuObligation::query()->where('emission_id', $emission->id)->count())->toBeGreaterThanOrEqual(2);
});
