<?php

use App\Domain\PuCalculator\DTOs\PuBaselineRequirement;
use App\Domain\PuCalculator\DTOs\PuCandidateCurve;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationPlan;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Services\PuBaselineCandidatePersistenceService;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Domain\PuCalculator\Services\PuCandidateCurveService;
use App\Domain\PuCalculator\Services\PuEventMaterializationService;
use App\Domain\PuCalculator\Services\PuNumericHomologationFinancialDiffService;
use App\Domain\PuCalculator\Services\PuNumericHomologationFingerprintService;
use App\Domain\PuCalculator\Services\PuNumericHomologationPlanService;
use App\Domain\PuCalculator\Services\PuNumericHomologationService;
use App\Domain\PuCalculator\Services\PuNumericHomologationValidationService;
use App\Domain\PuCalculator\Services\PuNumericPreparationPlanService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Models\BusinessCalendarYear;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\IndexRateSourceGovernanceReview;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentField;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Dobre de teste da engine candidata. Existe apenas para (a) provar que a engine
 * oficial NÃO é chamada quando o estado está bloqueado e (b) simular TOCTOU:
 * um pré-requisito desaparecendo entre o plano e o cálculo.
 */
class PuNumericHomologationCurveSpy extends PuCandidateCurveService
{
    public int $calls = 0;

    /** @var (callable():void)|null */
    public $beforeGenerate = null;

    public function generate(Emission $emission, PuNumericHomologationPlan $plan): PuCandidateCurve
    {
        $this->calls++;

        if ($this->beforeGenerate !== null) {
            ($this->beforeGenerate)();
        }

        return parent::generate($emission, $plan);
    }
}

beforeEach(function () {
    Storage::fake('local');
    config([
        'pu_indexes.source_homologation.artifact_disk' => 'local',
        'pu_indexes.source_homologation.artifact_directory' => 'homologations/index-rate-sources',
        'pu_indexes.bcb.chunk_months' => 12,
        'pu_indexes.bcb.retries' => 1,
        'pu_indexes.bcb.retry_sleep_ms' => 0,
    ]);
});

const PU_NUMERIC_HOMOLOGATION_AS_OF = '2026-08-26';

function puNumericHomologationEmission(): Emission
{
    return Emission::factory()->create([
        'name' => 'CRI Alto Bellevue',
        'type' => 'CRI',
        'if_code' => '26E0017614',
        'isin_code' => 'BRALBLCRI008',
        'status' => 'active',
        'issue_date' => '2026-05-08',
        'maturity_date' => '2031-05-08',
        'issued_quantity' => 5000,
        'issued_price' => '1000.00',
        'remuneration_indexer' => 'CDI',
        'remuneration_rate' => '6.00',
        'interest_payment_frequency' => 'Mensal',
        'amortization_frequency' => 'Bullet',
    ]);
}

function puNumericHomologationProveBaseline(Emission $emission): LegalInstrument
{
    $document = Document::factory()->create(['title' => 'Termo de Securitização sintético']);
    $emission->documents()->attach($document);
    $instrument = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::SecuritizationTerm, '001/CONTRATO')
        ->create(['emission_id' => $emission->id]);
    $fields = [
        'issue_date' => ['2026-05-08', null, '2026-05-08'],
        'indexer' => [PuIndexer::Cdi->value, null, null],
        'index_percentage' => ['100%', 1.0, null],
        'spread' => ['6%', 0.06, null],
        'business_day_basis' => ['252', 252.0, null],
        'day_count_rule' => ['DU/252', null, null],
        'business_day_definition' => ['Dia útil conforme feriados nacionais', null, null],
        'calendar_code' => [BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS, null, null],
        'index_rate_lookup_mode' => [PuIndexRateLookupMode::BusinessDayLagExact->value, null, null],
        'index_rate_lag_business_days' => ['-5', -5.0, null],
        'initial_unit_value' => ['1000,00', 1000.0, null],
        'maturity_date' => ['2031-05-08', null, '2031-05-08'],
        'payment_schedule' => ['Anexo II — cronograma mensal de juros', null, null],
        'first_interest_payment_date' => ['2026-06-08', null, '2026-06-08'],
        'interest_payment_frequency' => ['monthly', null, null],
        'amortization' => ['bullet', null, null],
        'payment_convention' => ['following_business_day', null, null],
        'first_coupon_pre_integralization_premium_enabled' => ['1', null, null],
        'first_coupon_pre_integralization_business_days' => ['2', 2.0, null],
        'first_coupon_pre_integralization_apply_index_factor' => ['1', null, null],
        'first_coupon_pre_integralization_apply_spread_factor' => ['1', null, null],
    ];

    foreach ($fields as $fieldKey => [$value, $numeric, $date]) {
        $key = LegalInstrumentFieldKey::from($fieldKey);
        LegalInstrumentField::factory()->for($instrument, 'instrument')->create([
            'field_key' => $key,
            'value_type' => $key->valueType(),
            'value' => $value,
            'value_numeric' => $numeric,
            'value_date' => $date,
            'effective_date' => '2026-05-08',
            'status' => LegalInstrumentFieldStatus::Confirmed,
            'document_id' => $document->id,
            'clause' => '6.1',
            'page' => 12,
            'confidence_score' => 0.95,
            'has_conflict' => false,
        ]);
    }

    return $instrument;
}

function puNumericHomologationCalendar(): void
{
    $responsible = User::factory()->create();
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2031, $responsible->id);

    foreach (range(2026, 2031) as $year) {
        $calendarYear = BusinessCalendarYear::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
            ->where('year', $year)
            ->firstOrFail();

        app(BusinessCalendarYearService::class)->confirm(
            BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
            $year,
            (string) $calendarYear->source,
            (string) $calendarYear->source_document,
            $calendarYear->source_revision,
            $calendarYear->checksum,
            $responsible->id,
        );
    }
}

function puNumericHomologationProveIntegralization(Emission $emission): EmissionPuBaselineEvidence
{
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);

    return EmissionPuBaselineEvidence::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate,
        'document_type' => 'b3_settlement_statement',
        'evidenced_value' => '2026-05-15',
        'reference' => 'Liquidação sintética de 2026-05-15',
        'confidence' => 'high',
        'status' => PuBaselineEvidenceStatus::Approved,
        'reviewed_by' => User::factory(),
        'reviewed_at' => now(),
    ]);
}

function puNumericHomologationApproveDossier(): IndexRateSourceGovernanceReview
{
    $report = [
        'phase' => '2B.5.4 — Homologação da Fonte da Taxa DI',
        'workflow_status' => 'ready_for_review',
        'approved' => false,
        'classification' => 'B — Equivalência comprovada com transformação',
        'started_at' => '2026-08-25T15:18:54+00:00',
        'completed_at' => '2026-08-25T15:19:27+00:00',
        'requested_period' => ['from' => '2025-01-01', 'to' => '2026-08-25'],
        'compared_period' => ['from' => '2025-01-01', 'to' => '2026-08-24'],
        'normalization' => [
            'b3' => '9 dígitos com duas casas implícitas.',
            'bcb' => 'Separador decimal normalizado sem arredondamento.',
            'comparison' => 'Comparação decimal exata; tolerância zero.',
        ],
        'sources' => [
            'b3' => [
                'compared_records' => 413,
                'normalized_checksum' => str_repeat('a', 64),
                'raw_payload_manifest_checksum' => str_repeat('b', 64),
                'payloads' => [['source_reference' => 'b3:manifest', 'sha256' => str_repeat('c', 64)]],
            ],
            'bcb' => [
                'compared_records' => 413,
                'normalized_checksum' => str_repeat('a', 64),
                'raw_payload_manifest_checksum' => str_repeat('d', 64),
                'payloads' => [[
                    'url' => 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.4389/dados',
                    'sha256' => str_repeat('e', 64),
                ]],
            ],
        ],
        'comparison' => [
            'summary' => [
                'present_equal' => 413,
                'present_different' => 0,
                'only_b3' => 0,
                'only_bcb' => 0,
                'common_dates' => 413,
            ],
        ],
        'executor' => [
            'type' => 'console_command',
            'label' => 'pu:index-rates:homologate-di-source',
            'user_id' => null,
        ],
    ];
    $report['report_checksum'] = hash('sha256', (string) json_encode(
        $report,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
    $path = 'homologations/index-rate-sources/cdi-b3-vs-bcb-4389-numeric-homologation.json';
    Storage::disk('local')->put($path, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));

    return IndexRateSourceGovernanceReview::factory()->create([
        'source_code' => 'bcb_sgs_4389',
        'report_checksum' => $report['report_checksum'],
        'artifact_disk' => 'local',
        'artifact_path' => $path,
        'status' => IndexRateSourceGovernanceReview::STATUS_APPROVED,
        'reviewed_by' => User::factory(),
        'reviewed_at' => now(),
        'review_notes' => 'Dossiê revisado e aprovado para uso operacional.',
    ]);
}

function puNumericHomologationActor(array $permissions = []): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

function puNumericHomologationPersistParameter(
    Emission $emission,
    ?CarbonImmutable $asOf = null,
): EmissionPuParameter {
    $asOf = ($asOf ?? CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF))->startOfDay();
    $actor = puNumericHomologationActor([AccessPermission::PuParametersConfigure->value]);
    app(PuBaselineCandidatePersistenceService::class)->write(
        $emission,
        $actor->email,
        $asOf,
    );

    return EmissionPuParameter::query()->whereBelongsTo($emission)->firstOrFail();
}

/** @param list<string> $dates */
function puNumericHomologationLoadRates(array $dates, string $value = '14.90000000'): void
{
    foreach ($dates as $date) {
        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date,
            'rate_value' => $value,
            'source' => 'bcb_sgs',
            'source_reference' => 'bcb_sgs:4389',
            'external_series_code' => '4389',
            'is_projected' => false,
        ]);
    }
}

function puNumericHomologationRateForDate(string $date): IndexRate
{
    return IndexRate::query()
        ->forIndexer(PuIndexer::Cdi)
        ->whereDate('rate_date', $date)
        ->sole();
}

/**
 * Emissão sintética com parâmetro, todos os snapshots exatos e todos os eventos
 * contratuais presentes: `ready_for_numeric_homologation`.
 */
function puNumericHomologationReadyEmission(?CarbonImmutable $asOf = null): Emission
{
    $asOf = ($asOf ?? CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF))->startOfDay();
    $emission = puNumericHomologationEmission();
    puNumericHomologationProveBaseline($emission);
    puNumericHomologationCalendar();
    puNumericHomologationApproveDossier();
    puNumericHomologationProveIntegralization($emission);
    $emission = $emission->fresh();
    puNumericHomologationPersistParameter($emission, $asOf);
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    puNumericHomologationLoadRates($plan->requiredRateDates);
    $actor = puNumericHomologationActor([AccessPermission::PuParametersConfigure->value]);
    app(PuEventMaterializationService::class)->write($emission, $actor->email, $asOf);

    return $emission->fresh();
}

/** @return array<string, int> */
function puNumericHomologationCounts(): array
{
    return [
        'parameters' => EmissionPuParameter::query()->count(),
        'rates' => IndexRate::query()->count(),
        'events' => EmissionPuEvent::query()->count(),
        'curve_versions' => EmissionPuCurveVersion::query()->count(),
        'daily_curves' => EmissionPuDailyCurve::query()->count(),
        'histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
    ];
}

function puNumericHomologationSpy(): PuNumericHomologationCurveSpy
{
    $spy = app()->make(PuNumericHomologationCurveSpy::class);
    app()->instance(PuCandidateCurveService::class, $spy);

    return $spy;
}

/** @param array<string, mixed> $overrides */
function puNumericHomologationRow(PuDailyCurveRowData $row, array $overrides = []): PuDailyCurveRowData
{
    return new PuDailyCurveRowData(...[
        'date' => $row->date,
        'isBusinessDay' => $row->isBusinessDay,
        'unitBaseValue' => $row->unitBaseValue,
        'unitCorrectedValue' => $row->unitCorrectedValue,
        'factorDi' => $row->factorDi,
        'factorDiAccumulated' => $row->factorDiAccumulated,
        'factorSpread' => $row->factorSpread,
        'factorSpreadDi' => $row->factorSpreadDi,
        'interestRealUnitValue' => $row->interestRealUnitValue,
        'updatedUnitValue' => $row->updatedUnitValue,
        'amortizationRatio' => $row->amortizationRatio,
        'amortizationUnitValue' => $row->amortizationUnitValue,
        'amortizationValue' => $row->amortizationValue,
        'residualUnitValue' => $row->residualUnitValue,
        'quantity' => $row->quantity,
        'totalValue' => $row->totalValue,
        'interestPaymentUnitValue' => $row->interestPaymentUnitValue,
        'interestPaymentValue' => $row->interestPaymentValue,
        'paymentTotalUnitValue' => $row->paymentTotalUnitValue,
        'paymentTotalValue' => $row->paymentTotalValue,
        'dupCorrection' => $row->dupCorrection,
        'dutCorrection' => $row->dutCorrection,
        'dupInterest' => $row->dupInterest,
        'dutInterest' => $row->dutInterest,
        'indexRateDate' => $row->indexRateDate,
        'indexRateValue' => $row->indexRateValue,
        'eventOriginalDate' => $row->eventOriginalDate,
        'eventEffectiveDate' => $row->eventEffectiveDate,
        'calculationMemory' => $row->calculationMemory,
        ...$overrides,
    ]);
}

/** @param list<PuDailyCurveRowData> $rows */
function puNumericHomologationCandidate(array $rows): PuCandidateCurve
{
    $first = $rows[0] ?? null;
    $last = $rows === [] ? null : $rows[array_key_last($rows)];

    return new PuCandidateCurve(
        rows: $rows,
        checksum: app(PuNumericHomologationFingerprintService::class)->curveChecksum($rows),
        rowCount: count($rows),
        from: $first?->date->toDateString(),
        to: $last?->date->toDateString(),
        initialUnitValue: $first?->updatedUnitValue,
        lastUnitValue: $last?->residualUnitValue,
        checkpoints: [],
    );
}

/** @param array<string, mixed> $overrides */
function puNumericHomologationPlanWith(
    PuNumericHomologationPlan $plan,
    array $overrides,
): PuNumericHomologationPlan {
    return new PuNumericHomologationPlan(...[
        'emissionId' => $plan->emissionId,
        'asOf' => $plan->asOf,
        'readiness' => $plan->readiness,
        'requiredReadiness' => $plan->requiredReadiness,
        'action' => $plan->action,
        'reason' => $plan->reason,
        'canEvaluate' => $plan->canEvaluate,
        'parameterId' => $plan->parameterId,
        'parameterSnapshot' => $plan->parameterSnapshot,
        'curveStartDate' => $plan->curveStartDate,
        'curveEndDate' => $plan->curveEndDate,
        'homologationEndDate' => $plan->homologationEndDate,
        'calendarWindow' => $plan->calendarWindow,
        'rateWindow' => $plan->rateWindow,
        'requiredRateDates' => $plan->requiredRateDates,
        'rates' => $plan->rates,
        'events' => $plan->events,
        'inputFingerprint' => $plan->inputFingerprint,
        'inputPayload' => $plan->inputPayload,
        'existingCurves' => $plan->existingCurves,
        'externalReference' => $plan->externalReference,
        'writes' => $plan->writes,
        ...$overrides,
    ]);
}

/** @param list<array<string, mixed>> $entries @return list<string> */
function puNumericHomologationCodes(array $entries): array
{
    return array_values(array_map(
        fn (array $entry): string => (string) ($entry['code'] ?? ''),
        $entries,
    ));
}

it('short-circuits the real blocked Alto Bellevue state without calling the official engine', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationEmission();
    puNumericHomologationProveBaseline($emission);
    $spy = puNumericHomologationSpy();
    $before = puNumericHomologationCounts();

    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );

    expect($result->action)->toBe(PuNumericHomologationService::ACTION_NOT_READY)
        ->and($result->plan->readiness)->toBe(PuBaselineReadinessStatus::Blocked->value)
        ->and($result->plan->requiredReadiness)->toBe('ready_for_numeric_homologation')
        ->and($result->plan->canEvaluate)->toBeFalse()
        ->and($result->plan->parameterId)->toBeNull()
        ->and($result->plan->curveStartDate)->toBe('PENDING')
        ->and($result->plan->homologationEndDate)->toBeNull()
        ->and($result->plan->calendarWindow['from'])->toBeNull()
        ->and($result->plan->calendarWindow['to'])->toBeNull()
        ->and($result->plan->rateWindow['from'])->toBeNull()
        ->and($result->plan->rateWindow['to'])->toBeNull()
        ->and($result->plan->inputFingerprint)->toBeNull()
        ->and($result->plan->requiredRateDates)->toBe([])
        ->and($result->plan->rates)->toBe([])
        ->and($result->plan->events)->toBe([])
        ->and($result->candidate)->toBeNull()
        ->and($result->validation)->toBeNull()
        ->and($result->comparison)->toBeNull()
        ->and($result->writes)->toBe(0)
        ->and($spy->calls)->toBe(0)
        ->and(puNumericHomologationCounts())->toBe($before);

    Http::assertNothingSent();
});

it('reports the blocked dry-run without any write and exits successfully', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationEmission();
    puNumericHomologationProveBaseline($emission);
    $spy = puNumericHomologationSpy();
    $before = puNumericHomologationCounts();

    $this->artisan('pu:alto-bellevue:homologate-numeric', [
        '--dry-run' => true,
        '--as-of' => PU_NUMERIC_HOMOLOGATION_AS_OF,
    ])
        ->expectsOutputToContain('Phase 2B.5.15 — Numeric PU Homologation Dry-run')
        ->expectsOutputToContain('Readiness: blocked')
        ->expectsOutputToContain('Required readiness: ready_for_numeric_homologation')
        ->expectsOutputToContain('EmissionPuParameter: absent')
        ->expectsOutputToContain('curve_start_date: PENDING')
        ->expectsOutputToContain('Calendar window: not resolved')
        ->expectsOutputToContain('Rate window: not resolved')
        ->expectsOutputToContain('Action: numeric_homologation_not_ready')
        ->expectsOutputToContain('Candidate curve: not generated')
        ->expectsOutputToContain('Candidate rows: 0')
        ->expectsOutputToContain('Input fingerprint: not available')
        ->expectsOutputToContain('Curve checksum: not available')
        ->expectsOutputToContain('Internal validation: not executed')
        ->expectsOutputToContain('External comparison: not evaluated')
        ->expectsOutputToContain('Writes: 0')
        ->expectsOutputToContain('No BCB fetch performed.')
        ->expectsOutputToContain('No candidate curve persisted.')
        ->assertExitCode(0);

    expect($spy->calls)->toBe(0)
        ->and(puNumericHomologationCounts())->toBe($before);
    Http::assertNothingSent();
});

it('rejects an --as-of option that is not a real calendar date', function () {
    puNumericHomologationEmission();

    $this->artisan('pu:alto-bellevue:homologate-numeric', ['--as-of' => '2026-02-30'])
        ->assertExitCode(1);
});

it('blocks before the engine when the parameter exists but exact snapshots are missing', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationEmission();
    puNumericHomologationProveBaseline($emission);
    puNumericHomologationCalendar();
    puNumericHomologationApproveDossier();
    puNumericHomologationProveIntegralization($emission);
    $emission = $emission->fresh();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $parameter = puNumericHomologationPersistParameter($emission, $asOf);
    $spy = puNumericHomologationSpy();
    $before = puNumericHomologationCounts();

    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        $asOf,
    );

    expect($result->action)->toBe(PuNumericHomologationService::ACTION_NOT_READY)
        ->and($result->plan->parameterId)->toBe($parameter->id)
        ->and($result->plan->canEvaluate)->toBeFalse()
        ->and($result->plan->inputFingerprint)->toBeNull()
        ->and($result->candidate)->toBeNull()
        ->and($result->writes)->toBe(0)
        ->and($spy->calls)->toBe(0)
        ->and(puNumericHomologationCounts())->toBe($before);

    Http::assertNothingSent();
});

it('blocks before the engine when the snapshots are present but the events are missing', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationEmission();
    puNumericHomologationProveBaseline($emission);
    puNumericHomologationCalendar();
    puNumericHomologationApproveDossier();
    puNumericHomologationProveIntegralization($emission);
    $emission = $emission->fresh();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    puNumericHomologationPersistParameter($emission, $asOf);
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    expect($plan->requiredRateDates)->not->toBe([]);
    puNumericHomologationLoadRates($plan->requiredRateDates);
    $readiness = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);
    $spy = puNumericHomologationSpy();
    $before = puNumericHomologationCounts();

    $result = app(PuNumericHomologationService::class)->evaluate($emission, $asOf);

    expect($readiness->requirement('index_snapshots_loaded')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($readiness->requirement('pu_events_loaded')->status)
        ->toBe(PuBaselineRequirementStatus::Blocking)
        ->and(EmissionPuEvent::query()->count())->toBe(0)
        ->and($result->action)->toBe(PuNumericHomologationService::ACTION_NOT_READY)
        ->and($result->plan->canEvaluate)->toBeFalse()
        ->and($result->candidate)->toBeNull()
        ->and($result->writes)->toBe(0)
        ->and($spy->calls)->toBe(0)
        ->and(puNumericHomologationCounts())->toBe($before);

    Http::assertNothingSent();
});

it('builds a synthetic state that is truly ready for numeric homologation', function () {
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $emission = puNumericHomologationReadyEmission($asOf);
    $preparation = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    $readiness = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);
    $candidateConfigurationBlockers = collect($readiness->requirements)
        ->filter(fn (PuBaselineRequirement $requirement): bool => in_array(
            'candidate_configuration',
            $requirement->blocks,
            true,
        ) && ! $requirement->isSatisfied());

    expect($readiness->status)->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation)
        ->and($candidateConfigurationBlockers)->toBeEmpty()
        ->and($readiness->pendingFields)->toBe([])
        ->and($readiness->requirement('first_integralization_date')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($readiness->requirement('calendar_technical_coverage')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($readiness->requirement('calendar_administrative_confirmation')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($readiness->requirement('index_source_technical_homologation')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($readiness->requirement('index_source_operational_approval')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($preparation->state)->toBe(PuNumericPreparationPlanService::STATE_READY)
        ->and($preparation->requiredRateDates)->toHaveCount(76)
        ->and($preparation->requiredRateDates[0])->toBe('2026-05-06')
        ->and($preparation->requiredRateDates[array_key_last($preparation->requiredRateDates)])
        ->toBe('2026-08-19')
        ->and($preparation->eventRequirements)->toHaveCount(3)
        ->and($preparation->presentEvents)->toHaveCount(3)
        ->and($readiness->rateWindow['required_rate_count'])->toBe(76)
        ->and($readiness->rateWindow['loaded_rate_count'])->toBe(76)
        ->and($readiness->eventDiagnostics['required_event_count'])->toBe(3)
        ->and($readiness->eventDiagnostics['loaded_required_event_count'])->toBe(3)
        ->and($readiness->requirement('index_snapshots_loaded')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($readiness->requirement('index_snapshots_loaded')->blocks)
        ->toBe(['numeric_homologation'])
        ->and($readiness->requirement('pu_events_loaded')->status)
        ->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($readiness->requirement('pu_events_loaded')->blocks)
        ->toBe(['numeric_homologation'])
        ->and($readiness->requirement('integralized_quantity')->status)
        ->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($readiness->requirement('integralized_quantity')->blocks)
        ->toBe(['aggregate_outputs'])
        ->and($readiness->requirement('external_independent_validation')->status)
        ->toBe(PuBaselineRequirementStatus::Recommended)
        ->and($readiness->requirement('external_independent_validation')->blocks)
        ->toBe(['external_validation'])
        ->and($readiness->calendarDiagnostics['years'])->toHaveCount(6)
        ->and(collect($readiness->calendarDiagnostics['years'])->pluck('year')->all())
        ->toBe(range(2026, 2031))
        ->and($readiness->calendarDiagnostics['required_from'])->toBe('2026-05-06')
        ->and($readiness->calendarDiagnostics['required_to'])->toBe('2031-05-08')
        ->and($readiness->indexSourceDiagnostics['source_code'])->toBe('bcb_sgs_4389')
        ->and($readiness->indexSourceDiagnostics['rate_source_value'])->toBe('bcb_sgs')
        ->and($readiness->indexSourceDiagnostics['approved'])->toBeTrue()
        ->and(IndexRate::query()->count())->toBe(76)
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(3)
        ->and(EmissionPuBaselineEvidence::query()
            ->whereBelongsTo($emission)
            ->where('evidence_type', PuBaselineEvidenceType::IntegralizedQuantity->value)
            ->exists())->toBeFalse()
        ->and(EmissionPuBaselineEvidence::query()
            ->whereBelongsTo($emission)
            ->where('evidence_type', PuBaselineEvidenceType::ExternalPuReference->value)
            ->exists())->toBeFalse();
});

it('evaluates a deterministic in-memory candidate when the synthetic state is ready', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $readiness = app(PuBaselineReadinessService::class)->evaluate($emission, $asOf);
    $before = puNumericHomologationCounts();

    $result = app(PuNumericHomologationService::class)->evaluate($emission, $asOf);
    $candidate = $result->candidate;
    $validation = $result->validation;

    expect($readiness->status)->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation)
        ->and($result->action)->toBe(PuNumericHomologationService::ACTION_READY_FOR_REVIEW)
        ->and($result->plan->canEvaluate)->toBeTrue()
        ->and($result->plan->inputFingerprint)->toBeString()
        ->and($result->plan->homologationEndDate)->toBe(PU_NUMERIC_HOMOLOGATION_AS_OF)
        ->and($candidate)->not->toBeNull()
        ->and($candidate->rowCount)->toBeGreaterThan(0)
        ->and($candidate->rowCount)->toBe(count($candidate->rows))
        ->and($candidate->from)->toBe($result->plan->curveStartDate)
        ->and($candidate->to)->toBe(PU_NUMERIC_HOMOLOGATION_AS_OF)
        ->and($candidate->checksum)->toBeString()
        ->and($candidate->summary()['persistence'])->toBe('in_memory_only')
        ->and($validation->status)->toBe('passed')
        ->and($validation->blockingFailures)->toBe([])
        ->and($result->writes)->toBe(0)
        ->and(puNumericHomologationCounts())->toBe($before);

    Http::assertNothingSent();
});

it('keeps every financial table untouched while evaluating the candidate', function () {
    $emission = puNumericHomologationReadyEmission();
    $parameter = EmissionPuParameter::query()->whereBelongsTo($emission)->sole();
    $before = puNumericHomologationCounts();
    $parameterSnapshot = $parameter->only($parameter->getFillable());

    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );

    expect($result->candidate)->not->toBeNull()
        ->and(puNumericHomologationCounts())->toBe($before)
        ->and(EmissionPuCurveVersion::query()->count())->toBe(0)
        ->and(EmissionPuDailyCurve::query()->count())->toBe(0)
        ->and(PuHistory::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($parameter->fresh()->only($parameter->getFillable()))->toEqual($parameterSnapshot);
});

it('reproduces the same input fingerprint and curve checksum for identical inputs', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $homologation = app(PuNumericHomologationService::class);

    $first = $homologation->evaluate($emission, $asOf);
    $second = $homologation->evaluate($emission, $asOf);

    expect($first->candidate)->not->toBeNull()
        ->and($second->candidate)->not->toBeNull()
        ->and($first->plan->inputFingerprint)->toBe($second->plan->inputFingerprint)
        ->and($first->candidate->checksum)->toBe($second->candidate->checksum)
        ->and($first->candidate->rowCount)->toBe($second->candidate->rowCount)
        ->and($first->candidate->checkpoints)->toBe($second->candidate->checkpoints)
        ->and($first->candidate->initialUnitValue)->toBe($second->candidate->initialUnitValue)
        ->and($first->candidate->lastUnitValue)->toBe($second->candidate->lastUnitValue)
        ->and($first->writes)->toBe(0)
        ->and($second->writes)->toBe(0);
});

it('changes the input fingerprint when the homologation cutoff changes', function () {
    $emission = puNumericHomologationReadyEmission();
    $plans = app(PuNumericHomologationPlanService::class);

    $current = $plans->plan($emission, CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF));
    $previous = $plans->plan($emission, CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF)->subDay());

    expect($current->canEvaluate)->toBeTrue()
        ->and($previous->canEvaluate)->toBeTrue()
        ->and($previous->homologationEndDate)->not->toBe($current->homologationEndDate)
        ->and($previous->inputFingerprint)->not->toBe($current->inputFingerprint);
});

it('keeps the input fingerprint stable when identical rates are stored with different ids and order', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $plans = app(PuNumericHomologationPlanService::class);
    $before = $plans->plan($emission, $asOf);

    expect($before->canEvaluate)->toBeTrue()
        ->and($before->requiredRateDates)->not->toBe([]);

    $stored = IndexRate::query()
        ->orderBy('rate_date')
        ->get()
        ->map(fn (IndexRate $rate): array => [
            'indexer' => $rate->indexer,
            'rate_date' => $rate->rate_date->toDateString(),
            'rate_value' => $rate->rate_value,
            'source' => $rate->source,
            'source_reference' => $rate->source_reference,
            'external_series_code' => $rate->external_series_code,
            'is_projected' => false,
        ])
        ->all();

    IndexRate::query()->delete();

    foreach (array_reverse($stored) as $attributes) {
        IndexRate::factory()->create($attributes);
    }

    $after = $plans->plan($emission, $asOf);

    expect($before->canEvaluate)->toBeTrue()
        ->and($after->canEvaluate)->toBeTrue()
        ->and($after->inputFingerprint)->toBe($before->inputFingerprint);
});

it('changes the input fingerprint when a required rate value changes', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $plans = app(PuNumericHomologationPlanService::class);
    $before = $plans->plan($emission, $asOf);
    $requiredRateDate = $before->requiredRateDates[0] ?? null;

    expect($before->canEvaluate)->toBeTrue()
        ->and($requiredRateDate)->toBeString();

    $rate = puNumericHomologationRateForDate($requiredRateDate);
    $newRateValue = '13.75000000';
    $rateIdentity = [
        'id' => $rate->id,
        'indexer' => $rate->indexer,
        'rate_date' => $rate->rate_date->toDateString(),
        'source' => $rate->source,
        'source_reference' => $rate->source_reference,
        'external_series_code' => $rate->external_series_code,
        'is_projected' => $rate->is_projected,
    ];
    $beforePayloadRate = collect($before->inputPayload['rates'])
        ->firstWhere('date', $requiredRateDate);

    expect($rate->rate_date->toDateString())->toBe($requiredRateDate)
        ->and($rate->rate_value)->toBe('14.90000000')
        ->and($rate->rate_value)->not->toBe($newRateValue)
        ->and($beforePayloadRate)->toBeArray();

    expect($beforePayloadRate['value'])->toBe('14.90000000');

    $updatedRows = IndexRate::query()
        ->whereKey($rate->getKey())
        ->update(['rate_value' => $newRateValue]);
    $updatedRate = puNumericHomologationRateForDate($requiredRateDate);

    expect($updatedRows)->toBe(1)
        ->and([
            'id' => $updatedRate->id,
            'indexer' => $updatedRate->indexer,
            'rate_date' => $updatedRate->rate_date->toDateString(),
            'source' => $updatedRate->source,
            'source_reference' => $updatedRate->source_reference,
            'external_series_code' => $updatedRate->external_series_code,
            'is_projected' => $updatedRate->is_projected,
        ])->toBe($rateIdentity)
        ->and($updatedRate->rate_value)->toBe($newRateValue);

    $after = $plans->plan($emission, $asOf);
    $afterPayloadRate = collect($after->inputPayload['rates'])
        ->firstWhere('date', $requiredRateDate);

    expect($before->inputFingerprint)->toBeString()
        ->and($after->canEvaluate)->toBeTrue()
        ->and($afterPayloadRate)->toBeArray();

    expect($afterPayloadRate['value'])->toBe($newRateValue)
        ->and($after->inputFingerprint)->not->toBe($before->inputFingerprint);
});

it('changes the input fingerprint or blocks readiness when a required event disappears', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $plans = app(PuNumericHomologationPlanService::class);
    $before = $plans->plan($emission, $asOf);

    expect($before->canEvaluate)->toBeTrue()
        ->and($before->events)->not->toBe([]);

    EmissionPuEvent::query()->whereBelongsTo($emission)->orderBy('effective_date')->firstOrFail()->delete();

    $after = $plans->plan($emission, $asOf);

    expect($before->canEvaluate)->toBeTrue()
        ->and($before->events)->not->toBe([])
        ->and($after->canEvaluate)->toBeFalse()
        ->and($after->inputFingerprint)->toBeNull()
        ->and($after->inputFingerprint)->not->toBe($before->inputFingerprint);
});

it('carries the calendar provenance into the input fingerprint', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $plans = app(PuNumericHomologationPlanService::class);
    $before = $plans->plan($emission, $asOf);

    expect($before->canEvaluate)->toBeTrue()
        ->and($before->inputFingerprint)->toBeString();

    $calendarYears = collect($before->inputPayload['calendar']['years'] ?? []);

    BusinessCalendarYear::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->where('year', 2026)
        ->update(['revision' => 7]);

    $after = $plans->plan($emission, $asOf);

    expect($calendarYears)->not->toBeEmpty()
        ->and($calendarYears->pluck('year')->all())->toBe($calendarYears->pluck('year')->sort()->values()->all())
        ->and($calendarYears->first())->toHaveKeys(['year', 'revision', 'checksum', 'governance_status'])
        ->and($after->inputFingerprint)->not->toBe($before->inputFingerprint);
});

it('aborts without writes when a required rate disappears during candidate generation', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $plan = app(PuNumericHomologationPlanService::class)->plan($emission, $asOf);

    expect($plan->canEvaluate)->toBeTrue()
        ->and($plan->requiredRateDates)->not->toBe([]);

    $vanishingDate = $plan->requiredRateDates[array_key_last($plan->requiredRateDates)];
    $vanishingRate = puNumericHomologationRateForDate($vanishingDate);
    $vanishingRateId = $vanishingRate->id;
    $deletedRateId = null;
    $deletedRows = 0;
    $rateExistsAfterDelete = true;

    expect($vanishingRate->rate_date->toDateString())->toBe($vanishingDate)
        ->and($vanishingRateId)->toBeInt();

    $spy = puNumericHomologationSpy();
    $spy->beforeGenerate = function () use (
        $vanishingDate,
        &$deletedRateId,
        &$deletedRows,
        &$rateExistsAfterDelete,
    ): void {
        $rate = puNumericHomologationRateForDate($vanishingDate);
        $deletedRateId = $rate->id;
        $deletedRows = IndexRate::query()->whereKey($rate->getKey())->delete();
        $rateExistsAfterDelete = IndexRate::query()
            ->forIndexer(PuIndexer::Cdi)
            ->whereDate('rate_date', $vanishingDate)
            ->exists();
    };
    $before = puNumericHomologationCounts();

    $result = app(PuNumericHomologationService::class)->evaluate($emission, $asOf);
    $postDeletePreparation = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);

    expect($spy->calls)->toBe(1)
        ->and($deletedRateId)->toBe($vanishingRateId)
        ->and($deletedRows)->toBe(1)
        ->and($rateExistsAfterDelete)->toBeFalse()
        ->and($postDeletePreparation->state)->toBe(PuNumericPreparationPlanService::STATE_RATES_MISSING)
        ->and($postDeletePreparation->missingRateDates)->toContain($vanishingDate)
        ->and($result->action)->toBe(PuNumericHomologationService::ACTION_STATE_CHANGED)
        ->and($result->candidate)->toBeNull()
        ->and($result->validation)->toBeNull()
        ->and($result->comparison)->toBeNull()
        ->and($result->plan->canEvaluate)->toBeFalse()
        ->and($result->plan->inputFingerprint)->toBeNull()
        ->and($result->writes)->toBe(0)
        ->and(puNumericHomologationCounts())->toBe([
            ...$before,
            'rates' => $before['rates'] - 1,
        ]);

    Http::assertNothingSent();
});

it('aborts without writes when a required event disappears during candidate generation', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $plan = app(PuNumericHomologationPlanService::class)->plan($emission, $asOf);

    expect($plan->canEvaluate)->toBeTrue()
        ->and($plan->events)->not->toBe([]);

    $spy = puNumericHomologationSpy();
    $spy->beforeGenerate = function () use ($emission): void {
        EmissionPuEvent::query()->whereBelongsTo($emission)->orderBy('effective_date')->firstOrFail()->delete();
    };
    $before = puNumericHomologationCounts();

    $result = app(PuNumericHomologationService::class)->evaluate($emission, $asOf);

    expect($spy->calls)->toBe(1)
        ->and($result->action)->toBe(PuNumericHomologationService::ACTION_STATE_CHANGED)
        ->and($result->candidate)->toBeNull()
        ->and($result->plan->canEvaluate)->toBeFalse()
        ->and($result->writes)->toBe(0)
        ->and(puNumericHomologationCounts())->toBe([
            ...$before,
            'events' => $before['events'] - 1,
        ]);

    Http::assertNothingSent();
});

it('explains the premium and the regular exact CDI lookups in the dossier', function () {
    $emission = puNumericHomologationReadyEmission();
    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );

    expect($result->candidate)->not->toBeNull()
        ->and($result->validation)->not->toBeNull();

    $samples = collect($result->validation->rateSamples);
    $premium = $samples->firstWhere('origin', 'first_coupon_pre_integralization_premium');
    $regular = $samples->firstWhere('origin', 'regular_curve_accrual');
    $requiredRateDates = $result->plan->requiredRateDates;

    expect($result->validation->status)->toBe('passed')
        ->and($premium)->not->toBeNull()
        ->and($premium['lookup_mode'])->toBe(PuIndexRateLookupMode::BusinessDayLagExact->value)
        ->and($premium['lag_business_days'])->toBe(-5)
        ->and($requiredRateDates)->toContain($premium['rate_date'])
        ->and($premium['rate_value'])->not->toBeNull()
        ->and($samples->where('origin', 'first_coupon_pre_integralization_premium')->count())->toBe(2)
        ->and($regular)->not->toBeNull()
        ->and($regular['lookup_mode'])->toBe(PuIndexRateLookupMode::BusinessDayLagExact->value)
        ->and($regular['lag_business_days'])->toBe(-5)
        ->and($requiredRateDates)->toContain($regular['rate_date'])
        ->and($regular['curve_date'])->not->toBe($result->candidate->from)
        ->and(puNumericHomologationCodes($result->validation->information))
        ->toContain('exact_rate_coverage');
});

it('starts the candidate at the persisted VNU with the engine unit factors', function () {
    $emission = puNumericHomologationReadyEmission();
    $parameter = EmissionPuParameter::query()->whereBelongsTo($emission)->sole();
    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );

    expect($result->candidate)->not->toBeNull()
        ->and($result->validation)->not->toBeNull();

    $first = $result->candidate->rows[0];
    $firstCheckpoint = collect($result->candidate->checkpoints)->firstWhere('label', 'first_curve_line');

    expect($first->date->toDateString())->toBe($parameter->curve_start_date->toDateString())
        ->and(bccomp($first->factorDi, '1', 16))->toBe(0)
        ->and(bccomp($first->factorDiAccumulated, '1', 16))->toBe(0)
        ->and(bccomp($first->factorSpread, '0', 16))->toBe(0)
        ->and(bccomp($first->factorSpreadDi, '0', 16))->toBe(0)
        ->and(bccomp($first->unitBaseValue, (string) $parameter->initial_unit_value, 16))->toBe(0)
        ->and(bccomp($first->updatedUnitValue, (string) $parameter->initial_unit_value, 16))->toBe(0)
        ->and(bccomp($first->interestRealUnitValue, '0', 16))->toBe(0)
        ->and($firstCheckpoint['curve_date'])->toBe($first->date->toDateString())
        ->and(puNumericHomologationCodes($result->validation->information))
        ->toContain('first_line_semantics');
});

it('applies the pre-integralization premium exactly once as first-coupon remuneration', function () {
    $emission = puNumericHomologationReadyEmission();
    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );

    expect($result->candidate)->not->toBeNull()
        ->and($result->validation)->not->toBeNull();

    $premiumRows = collect($result->candidate->rows)->filter(
        fn (PuDailyCurveRowData $row): bool => (bool) ($row->calculationMemory['first_coupon_pre_integralization_premium_applied'] ?? false),
    )->values();
    $premiumRow = $premiumRows->first();
    $firstCoupon = collect($result->candidate->rows)->first(
        fn (PuDailyCurveRowData $row): bool => in_array(
            PuEventType::InterestPayment->value,
            $row->calculationMemory['event_types'] ?? [],
            true,
        ),
    );

    expect($result->validation->status)->toBe('passed')
        ->and($premiumRows)->toHaveCount(1)
        ->and($premiumRow->date->toDateString())->toBe($firstCoupon->date->toDateString())
        ->and($premiumRow->calculationMemory['first_coupon_pre_integralization_premium']['application'])
        ->toBe('first_interest_payment_only')
        ->and($premiumRow->calculationMemory['first_coupon_pre_integralization_premium']['business_days_before_start'])
        ->toBe(2)
        ->and(bccomp($premiumRow->interestPaymentUnitValue, '0', 16))->toBe(1)
        ->and(bccomp(
            $premiumRow->residualUnitValue,
            bcsub($premiumRow->unitBaseValue, $premiumRow->amortizationUnitValue, 16),
            16,
        ))->toBe(0)
        ->and(puNumericHomologationCodes($result->validation->information))
        ->toContain('premium_one_time_first_coupon_remuneration');
});

it('applies every monthly coupon and the following business day convention inside the window', function () {
    $emission = puNumericHomologationReadyEmission();
    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );

    expect($result->candidate)->not->toBeNull()
        ->and($result->validation)->not->toBeNull();

    $coverage = collect($result->validation->information)->firstWhere('code', 'event_coverage');
    $planEvents = collect($result->plan->events);
    $interestEvents = $planEvents->where('event_type', PuEventType::InterestPayment->value);
    $adjustedEvents = $planEvents->filter(
        fn (array $event): bool => $event['original_date'] !== $event['effective_date'],
    );
    $adjustedRow = collect($result->candidate->rows)->first(
        fn (PuDailyCurveRowData $row): bool => $row->eventOriginalDate !== null
            && $row->eventEffectiveDate !== null
            && ! $row->eventOriginalDate->equalTo($row->eventEffectiveDate),
    );

    expect($result->validation->status)->toBe('passed')
        ->and($interestEvents->count())->toBeGreaterThan(1)
        ->and($coverage['context']['interest_events'])->toBe($interestEvents->count())
        ->and($coverage['context']['following_adjustments'])->toBe($adjustedEvents->count())
        ->and($adjustedEvents->count())->toBeGreaterThan(0)
        ->and($adjustedRow)->not->toBeNull()
        ->and($adjustedRow->eventEffectiveDate->toDateString())
        ->toBe($adjustedRow->date->toDateString())
        ->and($adjustedRow->eventEffectiveDate->greaterThan($adjustedRow->eventOriginalDate))->toBeTrue();
});

it('reports the external benchmark as unavailable without blocking the internal validation', function () {
    $emission = puNumericHomologationReadyEmission();
    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );

    expect($result->candidate)->not->toBeNull()
        ->and($result->validation)->not->toBeNull()
        ->and($result->comparison)->not->toBeNull()
        ->and($result->action)->toBe(PuNumericHomologationService::ACTION_READY_FOR_REVIEW)
        ->and($result->plan->externalReference['availability'])->toBe('unavailable')
        ->and($result->plan->externalReference['machine_readable_curve_available'])->toBeFalse()
        ->and($result->comparison->status)->toBe('unavailable')
        ->and($result->comparison->differences)->toBe([])
        ->and($result->comparison->tolerancePolicy)->toBeNull()
        ->and($result->validation->status)->toBe('passed')
        ->and($result->validation->blockingFailures)->toBe([])
        ->and(puNumericHomologationCodes($result->validation->warnings))
        ->toContain('external_benchmark_unavailable');
});

it('reports absolute and relative differences without inventing a tolerance', function () {
    $emission = puNumericHomologationReadyEmission();
    $result = app(PuNumericHomologationService::class)->evaluate(
        $emission,
        CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF),
    );
    $candidate = $result->candidate;

    expect($candidate)->not->toBeNull();

    $firstRow = $candidate->rows[0];
    $secondRow = $candidate->rows[1];
    $comparison = app(PuNumericHomologationFinancialDiffService::class)->compare($candidate, [
        'source' => 'Planilha de referência sintética',
        'reference_date' => PU_NUMERIC_HOMOLOGATION_AS_OF,
        'status' => 'approved',
        'confidence' => 'high',
        'rows' => [
            ['curve_date' => $firstRow->date->toDateString(), 'unit_value' => $firstRow->updatedUnitValue],
            ['curve_date' => $secondRow->date->toDateString(), 'unit_value' => '0.0000000000000000'],
        ],
    ]);
    $differences = collect($comparison->differences)->keyBy('curve_date');
    $exact = $differences->get($firstRow->date->toDateString());
    $zeroReference = $differences->get($secondRow->date->toDateString());

    expect($comparison->status)->toBe('compared')
        ->and($comparison->tolerancePolicy)->toBeNull()
        ->and($comparison->provenance['source'])->toBe('Planilha de referência sintética')
        ->and(bccomp($exact['absolute_difference'], '0', 16))->toBe(0)
        ->and($exact['classification'])->toBe('reported_without_tolerance')
        ->and($zeroReference['relative_difference_percentage'])->toBeNull()
        ->and(bccomp($zeroReference['absolute_difference'], '0', 16))->toBe(1)
        ->and(collect($comparison->differences)->pluck('curve_date')->all())
        ->toBe(collect($comparison->differences)->pluck('curve_date')->sort()->values()->all());
});

it('rejects a candidate whose rows repeat, skip or corrupt a curve date', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $parameter = EmissionPuParameter::query()->whereBelongsTo($emission)->sole();
    $plan = app(PuNumericHomologationPlanService::class)->plan($emission, $asOf);

    expect($plan->canEvaluate)->toBeTrue();

    $rows = app(PuCandidateCurveService::class)->generate($emission, $plan)->rows;
    $validator = app(PuNumericHomologationValidationService::class);

    $duplicated = $validator->validate(
        puNumericHomologationCandidate([$rows[0], $rows[0], ...array_slice($rows, 1)]),
        $plan,
        $parameter,
    );
    $unordered = $rows;
    [$unordered[1], $unordered[2]] = [$unordered[2], $unordered[1]];
    $outOfOrder = $validator->validate(
        puNumericHomologationCandidate($unordered),
        $plan,
        $parameter,
    );
    $corrupted = $rows;
    $corrupted[3] = puNumericHomologationRow($rows[3], ['updatedUnitValue' => 'NaN']);
    $invalidDecimal = $validator->validate(
        puNumericHomologationCandidate($corrupted),
        $plan,
        $parameter,
    );
    $empty = $validator->validate(puNumericHomologationCandidate([]), $plan, $parameter);

    expect(puNumericHomologationCodes($duplicated->blockingFailures))->toContain('duplicate_curve_date')
        ->and($duplicated->passed())->toBeFalse()
        ->and(puNumericHomologationCodes($outOfOrder->blockingFailures))->toContain('non_deterministic_daily_order')
        ->and(puNumericHomologationCodes($invalidDecimal->blockingFailures))->toContain('invalid_decimal')
        ->and(puNumericHomologationCodes($empty->blockingFailures))->toContain('candidate_curve_empty')
        ->and($empty->rateSamples)->toBe([]);
});

it('blocks a residual bullet event that does not amortize the principal', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $parameter = EmissionPuParameter::query()->whereBelongsTo($emission)->sole();
    $plan = app(PuNumericHomologationPlanService::class)->plan($emission, $asOf);

    expect($plan->canEvaluate)->toBeTrue();

    $rows = app(PuCandidateCurveService::class)->generate($emission, $plan)->rows;
    $lastIndex = array_key_last($rows);
    $last = $rows[$lastIndex];
    $rows[$lastIndex] = puNumericHomologationRow($last, [
        'calculationMemory' => [
            ...$last->calculationMemory,
            'event_types' => [PuEventType::Amortization->value],
        ],
    ]);
    $bulletPlan = puNumericHomologationPlanWith($plan, [
        'events' => [
            ...$plan->events,
            [
                'event_type' => PuEventType::Amortization->value,
                'original_date' => $last->date->toDateString(),
                'effective_date' => $last->date->toDateString(),
                'amortization_type' => PuAmortizationType::Residual->value,
                'amortization_value' => null,
                'sequence' => 1,
            ],
        ],
    ]);

    $validation = app(PuNumericHomologationValidationService::class)->validate(
        puNumericHomologationCandidate($rows),
        $bulletPlan,
        $parameter,
    );

    expect(bccomp($last->amortizationUnitValue, '0', 16))->toBe(0)
        ->and(bccomp($last->unitBaseValue, '0', 16))->toBe(1)
        ->and(puNumericHomologationCodes($validation->blockingFailures))
        ->toContain('bullet_principal_not_applied')
        ->and($validation->passed())->toBeFalse();
});

it('treats an event pushed beyond the cutoff as diagnostic instead of an anomaly', function () {
    $emission = puNumericHomologationReadyEmission();
    $asOf = CarbonImmutable::parse(PU_NUMERIC_HOMOLOGATION_AS_OF);
    $parameter = EmissionPuParameter::query()->whereBelongsTo($emission)->sole();
    $plan = app(PuNumericHomologationPlanService::class)->plan($emission, $asOf);

    expect($plan->canEvaluate)->toBeTrue();

    $candidate = app(PuCandidateCurveService::class)->generate($emission, $plan);
    $deferredPlan = puNumericHomologationPlanWith($plan, [
        'events' => [
            ...$plan->events,
            [
                'event_type' => PuEventType::InterestPayment->value,
                'original_date' => PU_NUMERIC_HOMOLOGATION_AS_OF,
                'effective_date' => $asOf->addDays(3)->toDateString(),
                'amortization_type' => null,
                'amortization_value' => null,
                'sequence' => 1,
            ],
        ],
    ]);

    $validation = app(PuNumericHomologationValidationService::class)->validate(
        $candidate,
        $deferredPlan,
        $parameter,
    );

    expect($validation->passed())->toBeTrue()
        ->and(puNumericHomologationCodes($validation->blockingFailures))
        ->not->toContain('event_reference_unresolved')
        ->and(puNumericHomologationCodes($validation->information))
        ->toContain('event_effective_after_candidate_window');
});

it('renders the ready dossier without persisting anything', function () {
    Http::preventStrayRequests();
    $emission = puNumericHomologationReadyEmission();
    $before = puNumericHomologationCounts();

    $this->artisan('pu:alto-bellevue:homologate-numeric', [
        '--as-of' => PU_NUMERIC_HOMOLOGATION_AS_OF,
        '--details' => true,
    ])
        ->expectsOutputToContain('Readiness: ready_for_numeric_homologation')
        ->expectsOutputToContain('Action: ready_for_review')
        ->expectsOutputToContain('Candidate curve: generated in memory')
        ->expectsOutputToContain('Internal validation: passed')
        ->expectsOutputToContain('Blocking failures: 0')
        ->expectsOutputToContain('External comparison: unavailable')
        ->expectsOutputToContain('Writes: 0')
        ->expectsOutputToContain('No operational curve modified.')
        ->expectsOutputToContain('No PuHistory or Payment generated.')
        ->assertExitCode(0);

    expect(puNumericHomologationCounts())->toBe($before);
    Http::assertNothingSent();
});
