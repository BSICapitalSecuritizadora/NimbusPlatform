<?php

use App\Domain\PuCalculator\DTOs\PuBaselineCandidate;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Services\PuBaselineCandidatePersistenceService;
use App\Domain\PuCalculator\Services\PuBaselineEventRequirementService;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Domain\PuCalculator\Services\PuEventMaterializationService;
use App\Domain\PuCalculator\Services\PuIndexRateRequirementResolver;
use App\Domain\PuCalculator\Services\PuIndexSnapshotPreparationService;
use App\Domain\PuCalculator\Services\PuNumericPreparationActorService;
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
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

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

function numericPreparationEmission(): Emission
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

function numericPreparationProveBaseline(Emission $emission): LegalInstrument
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

function numericPreparationCalendar(): void
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

function numericPreparationProveIntegralization(Emission $emission): EmissionPuBaselineEvidence
{
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);

    return EmissionPuBaselineEvidence::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate,
        'document_type' => 'b3_settlement_statement',
        'evidenced_value' => '2026-05-15',
        'reference' => 'Liquidação de 2026-05-15',
        'confidence' => 'high',
        'status' => PuBaselineEvidenceStatus::Approved,
        'reviewed_by' => User::factory(),
        'reviewed_at' => now(),
    ]);
}

function numericPreparationApproveDossier(): IndexRateSourceGovernanceReview
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
    $path = 'homologations/index-rate-sources/cdi-b3-vs-bcb-4389-numeric-preparation.json';
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

function numericPreparationReadyEmission(): Emission
{
    $emission = numericPreparationEmission();
    numericPreparationProveBaseline($emission);
    numericPreparationCalendar();
    numericPreparationApproveDossier();
    numericPreparationProveIntegralization($emission);

    return $emission->fresh();
}

function numericPreparationActor(array $permissions = []): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

function numericPreparationPersistParameter(Emission $emission): EmissionPuParameter
{
    $actor = numericPreparationActor([AccessPermission::PuParametersConfigure->value]);
    $result = app(PuBaselineCandidatePersistenceService::class)->write(
        $emission,
        $actor->email,
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($result->action)->toBe(PuBaselineCandidatePersistenceService::ACTION_CREATED);

    return EmissionPuParameter::query()->whereBelongsTo($emission)->firstOrFail();
}

/** @param list<string> $dates */
function numericPreparationLoadRates(array $dates, ?string $except = null): void
{
    foreach ($dates as $date) {
        if ($date === $except) {
            continue;
        }

        IndexRate::factory()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date,
            'rate_value' => '14.90000000',
            'source' => 'bcb_sgs',
            'source_reference' => 'bcb_sgs:4389',
            'external_series_code' => '4389',
            'is_projected' => false,
        ]);
    }
}

/** @param list<string> $dates @return list<array{data:string,valor:string}> */
function numericPreparationBcbRows(array $dates, string $value = '14.90'): array
{
    return array_map(fn (string $date): array => [
        'data' => CarbonImmutable::parse($date)->format('d/m/Y'),
        'valor' => $value,
    ], $dates);
}

it('short-circuits the current blocked state without parameter API fetch or writes', function () {
    Http::preventStrayRequests();
    $emission = numericPreparationEmission();
    numericPreparationProveBaseline($emission);
    $before = [
        'rates' => IndexRate::query()->count(),
        'events' => EmissionPuEvent::query()->count(),
        'curves' => EmissionPuDailyCurve::query()->count(),
        'histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
    ];

    $plan = app(PuNumericPreparationPlanService::class)->plan(
        $emission,
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($plan->state)->toBe(PuNumericPreparationPlanService::STATE_FINANCIAL_PREPARATION_NOT_READY)
        ->and($plan->action)->toBe(PuNumericPreparationPlanService::STATE_FINANCIAL_PREPARATION_NOT_READY)
        ->and($plan->parameterId)->toBeNull()
        ->and($plan->curveStartDate)->toBe('PENDING')
        ->and($plan->curveEndDate)->toBeNull()
        ->and($plan->calendarWindow['from'])->toBeNull()
        ->and($plan->calendarWindow['to'])->toBeNull()
        ->and($plan->rateWindow['from'])->toBeNull()
        ->and($plan->rateWindow['to'])->toBeNull()
        ->and($plan->requiredRateDates)->toBe([])
        ->and($plan->missingRateDates)->toBe([])
        ->and($plan->eventRequirements)->toBe([])
        ->and($plan->writes)->toBe(0)
        ->and([
            'rates' => IndexRate::query()->count(),
            'events' => EmissionPuEvent::query()->count(),
            'curves' => EmissionPuDailyCurve::query()->count(),
            'histories' => PuHistory::query()->count(),
            'payments' => Payment::query()->count(),
        ])->toBe($before);

    $this->artisan('pu:alto-bellevue:prepare-numeric-homologation', [
        '--dry-run' => true,
        '--as-of' => '2026-08-26',
    ])
        ->expectsOutputToContain('Readiness: blocked')
        ->expectsOutputToContain('EmissionPuParameter: absent')
        ->expectsOutputToContain('curve_start_date: PENDING')
        ->expectsOutputToContain('curve_end_date: PENDING')
        ->expectsOutputToContain('Action: financial_preparation_not_ready')
        ->expectsOutputToContain('Calendar window: not resolved')
        ->expectsOutputToContain('Rate window: not resolved')
        ->expectsOutputToContain('Required rates: 0')
        ->expectsOutputToContain('Rates to load: 0')
        ->expectsOutputToContain('Events to materialize: 0')
        ->expectsOutputToContain('Writes: 0')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('uses the persisted parameter and official resolvers to build exact premium rate and event requirements', function () {
    $emission = numericPreparationReadyEmission();
    $parameter = numericPreparationPersistParameter($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $plans = app(PuNumericPreparationPlanService::class);
    $resolver = app(PuIndexRateRequirementResolver::class);
    $plan = $plans->plan($emission, $asOf);
    $previousAsOfPlan = $plans->plan($emission, $asOf->subDay());
    $premiumRequirements = $resolver->firstCouponPreIntegralizationRateRequirements($parameter);
    $premiumDates = collect($premiumRequirements)
        ->map(fn ($requirement): string => $requirement->requiredRateDate()->toDateString())
        ->all();
    $asOfRequirement = $resolver->resolve($parameter, $asOf);

    expect($plan->parameterId)->toBe($parameter->id)
        ->and($plan->parameterFingerprint)->not->toBeNull()
        ->and($plan->parameterProvenance['recognition'])->toBe('controlled_candidate_persistence')
        ->and($plan->rateWindow['derived_with'])->toBe(PuIndexRateRequirementResolver::class)
        ->and($plan->requiredRateDates)->not->toBe([])
        ->and($plan->requiredRateDates[0])->toBe('2026-05-06')
        ->and($plan->requiredRateDates[array_key_last($plan->requiredRateDates)])->toBe('2026-08-19')
        ->and($plan->eventRequirements)->not->toBe([])
        ->and($plan->requiredRateDates)->toContain(...$premiumDates)
        ->and($plan->missingRateDates)->toBe($plan->requiredRateDates)
        ->and($premiumRequirements[0]->curveDate->toDateString())->toBe('2026-05-13')
        ->and($premiumRequirements[0]->requiredRateDate()?->toDateString())->toBe('2026-05-06')
        ->and($asOfRequirement->curveDate->toDateString())->toBe('2026-08-26')
        ->and($asOfRequirement->requiredRateDate()?->toDateString())->toBe('2026-08-19')
        ->and($asOfRequirement->lookupMode)->toBe(PuIndexRateLookupMode::BusinessDayLagExact)
        ->and($asOfRequirement->businessDayLag)->toBe(-5)
        ->and($previousAsOfPlan->requiredRateDates)->not->toBe($plan->requiredRateDates)
        ->and($previousAsOfPlan->requiredRateDates[array_key_last($previousAsOfPlan->requiredRateDates)])
        ->toBe('2026-08-18')
        ->and($plan->writes)->toBe(0)
        ->and($parameter->curve_start_date->toDateString())->toBe('2026-05-15')
        ->and($parameter->index_rate_lag_business_days)->toBe(-5);
});

it('requires the exact BusinessDayLagExact date even when the previous date exists', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $initial = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    $missingDate = $initial->requiredRateDates[0];
    numericPreparationLoadRates($initial->requiredRateDates, except: $missingDate);
    IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => CarbonImmutable::parse($missingDate)->subDay(),
        'rate_value' => '14.90000000',
        'source' => 'bcb_sgs',
        'source_reference' => 'bcb_sgs:4389',
        'external_series_code' => '4389',
        'is_projected' => false,
    ]);

    $missing = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);

    expect($missing->requiredRateDates)->toBe($initial->requiredRateDates)
        ->and($missing->missingRateDates)->toBe([$missingDate])
        ->and($missing->state)->toBe(PuNumericPreparationPlanService::STATE_RATES_AND_EVENTS_MISSING)
        ->and(app(PuBaselineReadinessService::class)
            ->evaluate($emission->fresh(), $asOf)
            ->requirement('index_snapshots_loaded')
            ->isSatisfied())->toBeFalse();

    numericPreparationLoadRates([$missingDate]);
    $complete = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);

    expect($complete->requiredRateDates)->toBe($initial->requiredRateDates)
        ->and($complete->missingRateDates)->toBe([])
        ->and(app(PuBaselineReadinessService::class)
            ->evaluate($emission->fresh(), $asOf)
            ->requirement('index_snapshots_loaded')
            ->isSatisfied())->toBeTrue();
});

it('plans the proven monthly interest schedule and only one bullet principal event at maturity', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $plan = app(PuNumericPreparationPlanService::class)->plan(
        $emission,
        CarbonImmutable::parse('2031-05-08'),
    );
    $interestEvents = collect($plan->eventRequirements)
        ->where('event_type', PuEventType::InterestPayment->value)
        ->values();
    $principalEvents = collect($plan->eventRequirements)
        ->where('event_type', PuEventType::Amortization->value)
        ->values();

    expect($interestEvents->first())->toMatchArray([
        'original_date' => '2026-06-08',
        'event_type' => PuEventType::InterestPayment->value,
        'amortization_type' => PuAmortizationType::None->value,
    ])
        ->and($interestEvents->firstWhere('original_date', '2028-06-08'))->not->toBeNull()
        ->and($interestEvents->last())->toMatchArray([
            'original_date' => '2031-05-08',
            'event_type' => PuEventType::InterestPayment->value,
        ])
        ->and($principalEvents)->toHaveCount(1)
        ->and($principalEvents->first())->toMatchArray([
            'original_date' => '2031-05-08',
            'event_type' => PuEventType::Amortization->value,
            'amortization_type' => PuAmortizationType::Residual->value,
        ])
        ->and($principalEvents->where('original_date', '!=', '2031-05-08'))->toBeEmpty();
});

it('uses the official calendar service for Saturday Sunday and national holiday following adjustment', function (
    string $nominalDate,
    string $effectiveDate,
) {
    numericPreparationCalendar();
    $emission = numericPreparationEmission()->load('puEvents');
    $candidate = new PuBaselineCandidate(
        configuration: ['calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS],
        fields: [],
        contractRequirements: [],
        contractualSchedule: [
            'first_interest_payment_date' => $nominalDate,
            'interest_payment_frequency' => 'monthly',
            'amortization' => 'bullet',
            'payment_convention' => 'following_business_day',
        ],
        indexer: PuIndexer::Cdi,
        lookupMode: PuIndexRateLookupMode::BusinessDayLagExact,
        calendarFromDate: CarbonImmutable::parse('2026-01-01'),
        curveEndDate: CarbonImmutable::parse($nominalDate),
    );
    $diagnostics = app(PuBaselineEventRequirementService::class)->evaluate(
        $emission,
        $candidate,
        CarbonImmutable::parse($nominalDate),
        true,
    );

    expect($diagnostics['required_events'][0])->toMatchArray([
        'original_date' => $nominalDate,
        'effective_date' => $effectiveDate,
    ]);
})->with([
    'Saturday' => ['2026-08-08', '2026-08-10'],
    'Sunday' => ['2026-08-09', '2026-08-10'],
    'national holiday' => ['2026-09-07', '2026-09-08'],
]);

it('materializes the full contractual schedule create-only and remains idempotent', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $actor = numericPreparationActor([AccessPermission::PuParametersConfigure->value]);
    $asOf = CarbonImmutable::parse('2031-05-08');
    $before = [
        'curves' => EmissionPuDailyCurve::query()->count(),
        'histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
    ];

    $first = app(PuEventMaterializationService::class)->write($emission, $actor->email, $asOf);
    $second = app(PuEventMaterializationService::class)->write($emission, $actor->email, $asOf);
    $principalEvents = EmissionPuEvent::query()
        ->whereBelongsTo($emission)
        ->where('event_type', PuEventType::Amortization->value)
        ->get();

    expect($first->action)->toBe(PuEventMaterializationService::ACTION_EVENTS_MATERIALIZED)
        ->and($first->writes)->toBeGreaterThan(0)
        ->and($first->plan->state)->toBe(PuNumericPreparationPlanService::STATE_RATES_MISSING)
        ->and($second->action)->toBe(PuEventMaterializationService::ACTION_ALREADY_PRESENT)
        ->and($second->writes)->toBe(0)
        ->and($second->plan->missingEvents)->toBe([])
        ->and($principalEvents)->toHaveCount(1)
        ->and($principalEvents->first()->original_date->toDateString())->toBe('2031-05-08')
        ->and($principalEvents->first()->amortization_type)->toBe(PuAmortizationType::Residual->value)
        ->and([
            'curves' => EmissionPuDailyCurve::query()->count(),
            'histories' => PuHistory::query()->count(),
            'payments' => Payment::query()->count(),
        ])->toBe($before)
        ->and(Activity::query()->where('description', 'pu_numeric_events_prepared')->count())->toBe(1);
});

it('detects an event collision and performs zero overwrite', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $actor = numericPreparationActor([AccessPermission::PuParametersConfigure->value]);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    $expected = $plan->eventRequirements[0];
    EmissionPuEvent::factory()->create([
        'emission_id' => $emission->id,
        'event_type' => $expected['event_type'],
        'original_date' => CarbonImmutable::parse($expected['original_date'])->subDay(),
        'effective_date' => $expected['effective_date'],
        'amortization_type' => $expected['amortization_type'],
        'amortization_value' => $expected['amortization_value'],
        'sequence' => $expected['sequence'],
    ]);
    $before = EmissionPuEvent::query()->whereBelongsTo($emission)->count();

    $result = app(PuEventMaterializationService::class)->write($emission, $actor->email, $asOf);

    expect($result->action)->toBe(PuEventMaterializationService::ACTION_EVENT_CONFLICT)
        ->and($result->writes)->toBe(0)
        ->and($result->plan->conflictingEvents)->not->toBe([])
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe($before);
});

it('rejects a republished CDI value conflict without overwriting or partially inserting', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $actor = numericPreparationActor([AccessPermission::PuIndexSync->value]);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    $conflictingDate = $plan->requiredRateDates[0];
    numericPreparationLoadRates([$conflictingDate]);
    IndexRate::query()->whereDate('rate_date', $conflictingDate)->update(['rate_value' => '14.80000000']);
    Http::fake([
        'api.bcb.gov.br/*' => Http::response(numericPreparationBcbRows($plan->requiredRateDates), 200),
    ]);
    $before = IndexRate::query()->count();

    $result = app(PuIndexSnapshotPreparationService::class)->write($emission, $actor->email, $asOf);

    expect($result->action)->toBe(PuIndexSnapshotPreparationService::ACTION_INDEX_RATE_CONFLICT)
        ->and($result->writes)->toBe(0)
        ->and($result->details['conflicts'][0]['date'])->toBe($conflictingDate)
        ->and(IndexRate::query()->count())->toBe($before)
        ->and(IndexRate::query()->whereDate('rate_date', $conflictingDate)->value('rate_value'))
        ->toEqual('14.80000000');
});

it('treats identical existing CDI snapshots as already present without API or duplicates', function () {
    Http::preventStrayRequests();
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $actor = numericPreparationActor([AccessPermission::PuIndexSync->value]);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    numericPreparationLoadRates($plan->requiredRateDates);
    $before = IndexRate::query()->count();

    $result = app(PuIndexSnapshotPreparationService::class)->write($emission, $actor->email, $asOf);

    expect($result->action)->toBe(PuIndexSnapshotPreparationService::ACTION_ALREADY_PRESENT)
        ->and($result->writes)->toBe(0)
        ->and($result->plan->requiredRateDates)->toBe($plan->requiredRateDates)
        ->and($result->plan->missingRateDates)->toBe([])
        ->and(IndexRate::query()->count())->toBe($before);
    Http::assertNothingSent();
});

it('keeps a missing exact CDI response as a blocker and writes nothing', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $actor = numericPreparationActor([AccessPermission::PuIndexSync->value]);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    $missingDate = $plan->requiredRateDates[0];
    Http::fake([
        'api.bcb.gov.br/*' => Http::response(numericPreparationBcbRows(
            array_values(array_diff($plan->requiredRateDates, [$missingDate])),
        ), 200),
    ]);

    $result = app(PuIndexSnapshotPreparationService::class)->write($emission, $actor->email, $asOf);

    expect($result->action)->toBe(PuIndexSnapshotPreparationService::ACTION_MISSING_INDEX_SNAPSHOT)
        ->and($result->writes)->toBe(0)
        ->and($result->details['missing_in_source'])->toContain($missingDate)
        ->and(IndexRate::query()->count())->toBe(0);
});

it('creates only required SGS 4389 CDI rows with the homologated transformation and audit trail', function () {
    $emission = numericPreparationReadyEmission();
    $parameter = numericPreparationPersistParameter($emission);
    $actor = numericPreparationActor([AccessPermission::PuIndexSync->value]);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    Http::fake([
        'api.bcb.gov.br/*' => Http::response(numericPreparationBcbRows($plan->requiredRateDates, '14,90'), 200),
    ]);

    $result = app(PuIndexSnapshotPreparationService::class)->write($emission, $actor->email, $asOf);
    $storedDates = IndexRate::query()->orderBy('rate_date')->pluck('rate_date')
        ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
        ->all();
    $sample = IndexRate::query()->oldest('rate_date')->firstOrFail();
    $activity = Activity::query()->where('description', 'pu_numeric_snapshots_prepared')->sole();

    expect($result->action)->toBe(PuIndexSnapshotPreparationService::ACTION_RATES_PREPARED)
        ->and($result->writes)->toBe(count($plan->requiredRateDates))
        ->and($result->plan->requiredRateDates)->toBe($plan->requiredRateDates)
        ->and($result->plan->missingRateDates)->toBe([])
        ->and($result->plan->state)->toBe(PuNumericPreparationPlanService::STATE_EVENTS_MISSING)
        ->and($storedDates)->toBe($plan->requiredRateDates)
        ->and($sample->rate_value)->toEqual('14.90000000')
        ->and($sample->source)->toBe('bcb_sgs')
        ->and($sample->source_reference)->toBe('bcb_sgs:4389')
        ->and($sample->external_series_code)->toBe('4389')
        ->and($activity->properties->get('emission_id'))->toBe($emission->id)
        ->and($activity->properties->get('parameter_id'))->toBe($parameter->id)
        ->and($activity->properties->get('actor_id'))->toBe($actor->id)
        ->and($activity->properties->get('source'))->toBe('bcb_sgs')
        ->and($activity->properties->get('series'))->toBe('4389')
        ->and(EmissionPuEvent::query()->count())->toBe(0)
        ->and(EmissionPuDailyCurve::query()->count())->toBe(0)
        ->and(PuHistory::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('validates all rejected actor states before any BCB fetch', function (
    string $actorState,
    string $expectedAction,
) {
    Http::preventStrayRequests();
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    Permission::findOrCreate(AccessPermission::PuIndexSync->value);
    $identifier = match ($actorState) {
        'missing' => null,
        'nonexistent' => 'nobody@example.test',
        'inactive' => User::factory()->create(['is_active' => false])->email,
        'unapproved' => User::factory()->unapproved()->create()->email,
        'unauthorized' => User::factory()->create()->email,
    };

    $result = app(PuIndexSnapshotPreparationService::class)->write(
        $emission,
        $identifier,
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($result->action)->toBe($expectedAction)
        ->and($result->writes)->toBe(0)
        ->and(IndexRate::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing' => ['missing', PuNumericPreparationActorService::ACTION_ACTOR_REQUIRED],
    'nonexistent' => ['nonexistent', PuNumericPreparationActorService::ACTION_ACTOR_NOT_FOUND],
    'inactive' => ['inactive', PuNumericPreparationActorService::ACTION_ACTOR_INACTIVE],
    'unapproved' => ['unapproved', PuNumericPreparationActorService::ACTION_ACTOR_UNAPPROVED],
    'unauthorized' => ['unauthorized', PuNumericPreparationActorService::ACTION_ACTOR_UNAUTHORIZED],
]);

it('accepts an active approved authorized rate actor while preserving source gaps', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $actor = numericPreparationActor([AccessPermission::PuIndexSync->value]);
    Http::fake(['api.bcb.gov.br/*' => Http::response([], 200)]);

    $result = app(PuIndexSnapshotPreparationService::class)->write(
        $emission,
        $actor->email,
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($result->action)->toBe(PuIndexSnapshotPreparationService::ACTION_MISSING_INDEX_SNAPSHOT)
        ->and($result->actorId)->toBe($actor->id)
        ->and($result->writes)->toBe(0);
});

it('uses pu.parameters.configure independently for event materialization', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $rateOnlyActor = numericPreparationActor([AccessPermission::PuIndexSync->value]);

    $result = app(PuEventMaterializationService::class)->write(
        $emission,
        $rateOnlyActor->email,
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($result->action)->toBe(PuNumericPreparationActorService::ACTION_ACTOR_UNAUTHORIZED)
        ->and($result->writes)->toBe(0)
        ->and(EmissionPuEvent::query()->count())->toBe(0);
});

it('reaches unit-PU numeric readiness without quantity curves history payments or external homologation', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $initial = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    numericPreparationLoadRates($initial->requiredRateDates);
    $actor = numericPreparationActor([AccessPermission::PuParametersConfigure->value]);
    $eventResult = app(PuEventMaterializationService::class)->write($emission, $actor->email, $asOf);
    $plan = app(PuNumericPreparationPlanService::class)->plan($emission, $asOf);
    $readiness = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    expect($eventResult->action)->toBe(PuEventMaterializationService::ACTION_EVENTS_MATERIALIZED)
        ->and($eventResult->plan->requiredRateDates)->toBe($initial->requiredRateDates)
        ->and($eventResult->plan->missingRateDates)->toBe([])
        ->and($plan->state)->toBe(PuNumericPreparationPlanService::STATE_READY)
        ->and($plan->readinessAfterHypothetical)->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation->value)
        ->and($readiness->status)->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation)
        ->and($readiness->requirement('integralized_quantity')->isSatisfied())->toBeFalse()
        ->and($readiness->requirement('integralized_quantity')->blocks)->toBe(['aggregate_outputs'])
        ->and($readiness->requirement('external_independent_validation')->isSatisfied())->toBeFalse()
        ->and(EmissionPuBaselineEvidence::query()
            ->whereBelongsTo($emission)
            ->where('evidence_type', PuBaselineEvidenceType::IntegralizedQuantity->value)
            ->exists())->toBeFalse()
        ->and(EmissionPuDailyCurve::query()->count())->toBe(0)
        ->and(PuHistory::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('blocks a persisted parameter that diverges from the current documentary baseline', function () {
    Http::preventStrayRequests();
    $emission = numericPreparationReadyEmission();
    $parameter = numericPreparationPersistParameter($emission);
    $parameter->update(['spread_rate' => '7.00000000']);

    $plan = app(PuNumericPreparationPlanService::class)->plan(
        $emission,
        CarbonImmutable::parse('2026-08-26'),
    );
    $result = app(PuIndexSnapshotPreparationService::class)->write(
        $emission,
        null,
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($plan->state)->toBe(PuNumericPreparationPlanService::STATE_CONFLICT)
        ->and($plan->action)->toBe(PuNumericPreparationPlanService::ACTION_CONFIGURATION_CONFLICT)
        ->and($plan->requiredRateDates)->toBe([])
        ->and($plan->eventRequirements)->toBe([])
        ->and($result->action)->toBe(PuNumericPreparationPlanService::ACTION_CONFIGURATION_CONFLICT)
        ->and($result->writes)->toBe(0);
    Http::assertNothingSent();
});

it('blocks missing event changes when existing financial effects show prior consumption', function () {
    $emission = numericPreparationReadyEmission();
    numericPreparationPersistParameter($emission);
    EmissionPuDailyCurve::factory()->create(['emission_id' => $emission->id]);
    $actor = numericPreparationActor([AccessPermission::PuParametersConfigure->value]);

    $plan = app(PuNumericPreparationPlanService::class)->plan(
        $emission,
        CarbonImmutable::parse('2026-08-26'),
    );
    $result = app(PuEventMaterializationService::class)->write(
        $emission,
        $actor->email,
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($plan->action)->toBe(PuNumericPreparationPlanService::ACTION_EXISTING_FINANCIAL_EFFECTS)
        ->and($plan->financialEffects['guard_blocking'])->toBeTrue()
        ->and($result->action)->toBe(PuNumericPreparationPlanService::ACTION_EXISTING_FINANCIAL_EFFECTS)
        ->and($result->writes)->toBe(0)
        ->and(EmissionPuEvent::query()->count())->toBe(0);
});
