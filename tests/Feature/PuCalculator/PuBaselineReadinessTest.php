<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\CdiSourceDossierGovernanceService;
use App\Domain\PuCalculator\Services\NationalLegalCalendarReviewService;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceReviewService;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentType;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Widgets\PuCalculator\PuBaselineReadinessWidget;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
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
use App\Models\Obligation;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config([
        'pu_indexes.source_homologation.artifact_disk' => 'local',
        'pu_indexes.source_homologation.artifact_directory' => 'homologations/index-rate-sources',
    ]);
});

it('is completely blocked without contractual evidence integralization calendar coverage or index source dossier', function () {
    $report = app(PuBaselineReadinessService::class)->evaluate(
        cdiEmission(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('contract_basis')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('calendar_technical_coverage')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('index_source_technical_homologation')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('first_integralization_date')->status)->toBe(PuBaselineRequirementStatus::Blocking);
});

it('does not offer the gate for an emission without any PU configuration context', function () {
    $emission = cdiEmission();
    $instrument = LegalInstrument::factory()->create(['emission_id' => $emission->id]);
    LegalInstrumentField::factory()->for($instrument, 'instrument')->create([
        'field_key' => LegalInstrumentFieldKey::MaturityDate,
        'value_type' => LegalInstrumentFieldKey::MaturityDate->valueType(),
        'value' => '2031-05-08',
        'value_numeric' => null,
        'value_date' => '2031-05-08',
        'effective_date' => '2026-05-08',
    ]);

    expect(app(PuBaselineReadinessService::class)->supports($emission->fresh()))->toBeFalse();

    proveContractualBaseline($emission);

    expect(app(PuBaselineReadinessService::class)->supports($emission->fresh()))->toBeTrue();
});

it('keeps technically complete calendar and index dossier blocked while governance is pending', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareNationalCalendar(confirmed: false);
    writeReadyDiDossier();
    proveFirstIntegralizationDate($emission);

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('calendar_technical_coverage')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('calendar_administrative_confirmation')->status)->toBe(PuBaselineRequirementStatus::AdministrativePending)
        ->and($report->requirement('index_source_technical_homologation')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('index_source_operational_approval')->status)->toBe(PuBaselineRequirementStatus::AdministrativePending);
});

it('keeps the baseline blocked when governance is approved but integralization date is unproven', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareNationalCalendar(confirmed: true);
    approveReadyDiDossier();

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('calendar_administrative_confirmation')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('index_source_operational_approval')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('first_integralization_date')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->candidateConfiguration['curve_start_date'])->toBe('PENDING');
});

it('blocks when two current instruments of the same emission disagree about the same field', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidateGovernance($emission);
    $divergent = LegalInstrument::factory()
        ->ofType(LegalInstrumentType::SecuritizationTerm, '002/2026')
        ->create(['emission_id' => $emission->id]);
    LegalInstrumentField::factory()->for($divergent, 'instrument')->create([
        'field_key' => LegalInstrumentFieldKey::BusinessDayBasis,
        'value_type' => LegalInstrumentFieldKey::BusinessDayBasis->valueType(),
        'value' => '360',
        'value_numeric' => 360,
        'effective_date' => '2026-05-08',
    ]);

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('contract_basis')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('contract_basis')->reason)->toContain('valores diferentes')
        ->and($report->candidateConfiguration['business_day_basis'])->toBe('PENDING');
});

it('follows the project vigency policy so a later amendment replaces the previous value without conflict', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    amendContractualField($emission, LegalInstrumentFieldKey::Spread, '0.09', 0.09, effectiveDate: '2026-07-01');

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->requirement('contract_spread')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->candidateConfiguration['spread_rate'])->toBe('9.00000000');
});

it('ignores an amendment whose vigency has not started yet', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    amendContractualField($emission, LegalInstrumentFieldKey::Spread, '0.09', 0.09, effectiveDate: '2027-01-01');

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->requirement('contract_spread')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->candidateConfiguration['spread_rate'])->toBe('6.00000000');
});

it('becomes ready for candidate configuration from verified documentary fields without persisting it', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::ReadyForCandidateConfiguration)
        ->and($report->candidateConfiguration)->toMatchArray([
            'indexer' => 'CDI',
            'index_percentage' => '100.00000000',
            'spread_rate' => '6.00000000',
            'business_day_basis' => 252,
            'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
            'index_rate_lookup_mode' => 'business_day_lag_exact',
            'index_rate_lag_business_days' => -5,
            'curve_start_date' => '2026-05-15',
            'curve_end_date' => '2031-05-08',
            'initial_unit_value' => '1000.0000000000000000',
            'first_coupon_pre_integralization_premium_enabled' => true,
            'first_coupon_pre_integralization_business_days' => 2,
            'first_coupon_pre_integralization_apply_index_factor' => true,
            'first_coupon_pre_integralization_apply_spread_factor' => true,
        ])
        ->and(collect($report->candidateFields)->every(fn (array $field): bool => array_diff([
            'field',
            'value',
            'document',
            'reference',
            'status',
            'confidence',
            'ready_for_future_persistence',
        ], array_keys($field)) === []))->toBeTrue()
        ->and($report->requirement('pu_events_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('index_snapshots_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('never marks the index percentage as ready for persistence because the parameter table has no such column', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );
    $percentageField = collect($report->candidateFields)
        ->firstWhere('field', 'index_percentage');

    expect($percentageField['status'])->toBe('proven')
        ->and($percentageField['ready_for_future_persistence'])->toBeFalse()
        ->and(Schema::hasColumn('emission_pu_parameters', 'index_percentage'))->toBeFalse()
        ->and(Schema::hasColumn('emission_pu_parameters', 'calculation_method'))->toBeTrue()
        ->and($report->requirement('engine_index_percentage_capability')->status)->toBe(PuBaselineRequirementStatus::Satisfied);
});

it('blocks when the proven index percentage is not the one hundred percent the engine composes', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    amendContractualField($emission, LegalInstrumentFieldKey::IndexPercentage, '1.05', 1.05, effectiveDate: '2026-07-01');

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->candidateConfiguration['index_percentage'])->toBe('105.00000000')
        ->and($report->requirement('engine_index_percentage_capability')->status)->toBe(PuBaselineRequirementStatus::Blocking);
});

it('derives the calculation method from the proven indexer without requiring its own contractual evidence', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );
    $methodField = collect($report->candidateFields)->firstWhere('field', 'calculation_method');

    expect($report->candidateConfiguration['calculation_method'])->not->toBe('PENDING')
        ->and($methodField['status'])->toBe('proven')
        ->and($methodField['ready_for_future_persistence'])->toBeTrue()
        ->and($report->pendingFields)->not->toContain('calculation_method');
});

it('becomes ready for numeric homologation only after required events and resolver-derived snapshots exist', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $pendingReport = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    loadRequiredEvents($emission, $pendingReport->eventDiagnostics['required_events']);
    loadRequiredRates($pendingReport->rateWindow['required_rate_dates']);

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    expect($report->status)->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation)
        ->and($report->rateWindow['derived_with'])->toBe('PuIndexRateRequirementResolver')
        ->and($report->rateWindow['missing_rate_dates'])->toBe([])
        ->and($report->eventDiagnostics['missing_events'])->toBe([])
        ->and($report->requirement('external_independent_validation')->status)->toBe(PuBaselineRequirementStatus::Recommended);
});

it('rejects snapshots loaded from a source other than the homologated one', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $pendingReport = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);
    loadRequiredEvents($emission, $pendingReport->eventDiagnostics['required_events']);
    loadRequiredRates($pendingReport->rateWindow['required_rate_dates'], source: 'manual_upload');

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    expect($report->indexSourceDiagnostics['rate_source_value'])->toBe('bcb_sgs')
        ->and($report->rateWindow['missing_rate_dates'])->not->toBe([])
        ->and($report->rateWindow['source_mismatch_dates'])->not->toBe([])
        ->and($report->requirement('index_snapshots_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking);
});

it('derives the snapshot window from the saved CDI publication calendar', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => '2026-06-04',
        'is_business_day' => false,
        'description' => 'Corpus Christi',
        'data_origin' => 'imported',
        'source' => 'ANBIMA',
        'source_is_official' => true,
    ]);
    $asOf = CarbonImmutable::parse('2026-06-15');
    $readiness = app(PuBaselineReadinessService::class);

    $contractual = $readiness->evaluate($emission->fresh(), $asOf)->requirement('index_snapshots_loaded')->expected;

    EmissionPuParameter::factory()->create([
        'emission_id' => $emission->id,
        'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
        'index_rate_calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
    ]);
    app(BusinessDayCalendarService::class)->flushCache();

    $publication = $readiness->evaluate($emission->fresh(), $asOf)->requirement('index_snapshots_loaded')->expected;

    expect($contractual)->toContain('2026-06-04')
        ->and($publication)->not->toContain('2026-06-04')
        ->and($publication)->toContain('2026-06-03');
});

it('allows numeric homologation without an external reference but never labels it externally validated', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $pendingReport = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);
    loadRequiredEvents($emission, $pendingReport->eventDiagnostics['required_events']);
    loadRequiredRates($pendingReport->rateWindow['required_rate_dates']);

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    expect($report->status)->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation)
        ->and($report->status)->not->toBe(PuBaselineReadinessStatus::ExternallyValidated)
        ->and($report->requirement('external_independent_validation')->blocks)->toBe(['external_validation'])
        ->and($report->limitations)->toContain('Homologação externa independente pendente.');
});

it('uses externally validated only after an approved independent PU comparison is matched', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $pendingReport = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);
    loadRequiredEvents($emission, $pendingReport->eventDiagnostics['required_events']);
    loadRequiredRates($pendingReport->rateWindow['required_rate_dates']);
    proveExternalPuReference($emission);

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    expect($report->status)->toBe(PuBaselineReadinessStatus::ExternallyValidated)
        ->and($report->requirement('external_independent_validation')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->limitations)->toBe([]);
});

it('prevents the next readiness level when one material field is pending', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);

    BusinessCalendarYear::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->where('year', 2031)
        ->update([
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ]);

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->requirement('calendar_administrative_confirmation')->status)->toBe(PuBaselineRequirementStatus::AdministrativePending)
        ->and($report->calendarDiagnostics['years'][2031]['review_state'])->toBe('ready_for_administrative_review');
});

it('never reports coverage for a calendar window with no evaluated year', function () {
    $diagnostics = app(NationalLegalCalendarReviewService::class)->reviewRange(2031, 2026);

    expect($diagnostics['years'])->toBe([])
        ->and($diagnostics['technical_coverage_satisfied'])->toBeFalse()
        ->and($diagnostics['administratively_confirmed'])->toBeFalse();
});

it('requires calendar coverage before the curve start because of the premium and the index lag', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    // A janela precisa recuar além da própria data de emissão: 2 Dias Úteis de
    // prêmio + 5 de lag antes de 2026-05-15 caem em 2026-05-06.
    expect($report->calendarDiagnostics['required_from'] < '2026-05-08')->toBeTrue()
        ->and($report->calendarDiagnostics['required_to'] >= '2031-05-08')->toBeTrue()
        ->and($report->rateWindow['financial_requirement_start_date'] < '2026-05-15')->toBeTrue()
        ->and($report->limitations)->toBe(['Homologação externa independente pendente.']);
});

it('does not require integralized quantity for unit PU while keeping aggregate outputs unavailable', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $asOf = CarbonImmutable::parse('2026-08-26');
    $pendingReport = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);
    loadRequiredEvents($emission, $pendingReport->eventDiagnostics['required_events']);
    loadRequiredRates($pendingReport->rateWindow['required_rate_dates']);

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    expect($report->status)->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation)
        ->and($report->requirement('integralized_quantity')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('integralized_quantity')->blocks)->toBe(['aggregate_outputs'])
        ->and($report->quantityDiagnostics['required_for_unit_pu'])->toBeFalse()
        ->and($report->quantityDiagnostics['required_for_aggregate_balance_and_payments'])->toBeTrue();
});

it('evaluates readiness without generating or importing any financial data', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $before = financialSideEffectCounts();

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::ReadyForCandidateConfiguration)
        ->and(financialSideEffectCounts())->toBe($before);
});

it('serves a second synthetic emission with different data through the same service', function () {
    $emission = prefixedEmission();
    provePrefixedContractualBaseline($emission);
    prepareContractualCalendar('CONTRACT_SYNTHETIC_2027', 2027, 2028);
    proveFirstIntegralizationDate($emission, '2027-01-11');
    $asOf = CarbonImmutable::parse('2027-06-30');

    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), $asOf);

    expect($report->candidateConfiguration)->toMatchArray([
        'indexer' => PuIndexer::Prefixed->value,
        'index_percentage' => null,
        'spread_rate' => null,
        'index_rate_lookup_mode' => null,
        'index_rate_lag_business_days' => null,
        'business_day_basis' => 360,
        'calendar_code' => 'CONTRACT_SYNTHETIC_2027',
        'curve_start_date' => '2027-01-11',
        'curve_end_date' => '2028-01-10',
        'initial_unit_value' => '5000.0000000000000000',
        'first_coupon_pre_integralization_premium_enabled' => false,
    ])
        ->and($report->requirements)->not->toHaveKey('contract_spread')
        ->and($report->requirements)->not->toHaveKey('contract_lookup')
        ->and($report->requirement('index_source_technical_homologation')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('index_source_operational_approval')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->indexSourceDiagnostics['required'])->toBeFalse()
        ->and($report->calendarDiagnostics['calendar_code'])->toBe('CONTRACT_SYNTHETIC_2027')
        ->and($report->calendarDiagnostics['is_national_legal_calendar'])->toBeFalse()
        ->and($report->requirement('calendar_technical_coverage')->status)->toBe(PuBaselineRequirementStatus::Satisfied);
});

it('demands no premium requirement at all when the contract proves the premium is disabled', function () {
    $emission = prefixedEmission();
    provePrefixedContractualBaseline($emission);
    prepareContractualCalendar('CONTRACT_SYNTHETIC_2027', 2027, 2028);
    proveFirstIntegralizationDate($emission, '2027-01-11');

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2027-06-30'),
    );
    $premiumFields = collect($report->candidateFields)
        ->whereIn('field', [
            'first_coupon_pre_integralization_business_days',
            'first_coupon_pre_integralization_apply_index_factor',
            'first_coupon_pre_integralization_apply_spread_factor',
        ]);

    expect($report->requirements)->not->toHaveKey('engine_opening_premium_capability')
        ->and($report->requirement('contract_opening_premium')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and($report->requirement('contract_opening_premium')->evidence['fields'])->toHaveCount(1)
        ->and($report->candidateConfiguration['first_coupon_pre_integralization_business_days'])->toBeNull()
        ->and($report->candidateConfiguration['first_coupon_pre_integralization_apply_index_factor'])->toBeFalse()
        ->and($report->candidateConfiguration['first_coupon_pre_integralization_apply_spread_factor'])->toBeFalse()
        ->and($premiumFields)->toHaveCount(3)
        ->and($premiumFields->every(fn (array $field): bool => $field['status'] === 'not_required'))->toBeTrue()
        ->and($report->pendingFields)->toBe([]);
});

it('blocks a contractual schedule the event engine does not represent', function () {
    $emission = prefixedEmission();
    provePrefixedContractualBaseline($emission);
    prepareContractualCalendar('CONTRACT_SYNTHETIC_2027', 2027, 2028);
    proveFirstIntegralizationDate($emission, '2027-01-11');
    amendContractualField($emission, LegalInstrumentFieldKey::InterestPaymentFrequency, 'quarterly', null, effectiveDate: '2027-02-01');

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2027-06-30'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::Blocked)
        ->and($report->eventDiagnostics['schedule_supported'])->toBeFalse()
        ->and($report->eventDiagnostics['required_events'])->toBe([])
        ->and($report->requirement('engine_contractual_schedule_capability')->status)->toBe(PuBaselineRequirementStatus::Blocking);
});

it('exposes every index source dossier audit fact while keeping operational approval explicit', function () {
    writeReadyDiDossier();

    $dossier = app(CdiSourceDossierGovernanceService::class)->latest();

    expect($dossier['technical_homologation_satisfied'])->toBeTrue()
        ->and($dossier['workflow_status'])->toBe('ready_for_review')
        ->and($dossier['approved'])->toBeFalse()
        ->and(data_get($dossier, 'report.requested_period'))->toBe([
            'from' => '2025-01-01',
            'to' => '2026-08-25',
        ])
        ->and(data_get($dossier, 'report.comparison.summary'))->toMatchArray([
            'common_dates' => 413,
            'present_equal' => 413,
            'present_different' => 0,
            'only_b3' => 0,
            'only_bcb' => 0,
        ])
        ->and(data_get($dossier, 'report.normalization'))->toHaveKeys(['b3', 'bcb', 'comparison'])
        ->and(data_get($dossier, 'report.sources.b3.payloads.0.sha256'))->not->toBeEmpty()
        ->and(data_get($dossier, 'report.sources.bcb.payloads.0.sha256'))->not->toBeEmpty()
        ->and($dossier['checksum_valid'])->toBeTrue()
        ->and($dossier['classification'])->toStartWith('B')
        ->and($dossier['executor'])->toMatchArray([
            'type' => 'console_command',
            'label' => 'pu:index-rates:homologate-di-source',
        ])
        ->and($dossier['executed_at'])->toBe('2026-08-25T15:19:27+00:00')
        ->and(IndexRateSourceGovernanceReview::query()->exists())->toBeFalse();
});

it('approves the index source dossier only through an authorized explicit review without financial side effects', function () {
    writeReadyDiDossier();
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $before = financialSideEffectCounts();

    $review = app(CdiSourceDossierGovernanceService::class)->approveLatest(
        $reviewer,
        'Revisei período, transformação, fontes, payloads e checksums.',
    );

    expect($review->reviewed_by)->toBe($reviewer->id)
        ->and($review->review_notes)->toBe('Revisei período, transformação, fontes, payloads e checksums.')
        ->and(app(CdiSourceDossierGovernanceService::class)->latest()['approved'])->toBeTrue()
        ->and(financialSideEffectCounts())->toBe($before);
});

it('preserves maker checker when the index source dossier identifies an application user as executor', function () {
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $executor = User::factory()->create();
    $executor->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    writeReadyDiDossier($executor->id);

    expect(fn () => app(CdiSourceDossierGovernanceService::class)->approveLatest(
        $executor,
        'Tentativa de autorrevisão.',
    ))->toThrow(PuMakerCheckerException::class)
        ->and(IndexRateSourceGovernanceReview::query()->exists())->toBeFalse();
});

it('marks each technically complete national calendar year as ready for administrative review without confirming it', function () {
    prepareNationalCalendar(confirmed: false);

    $calendar = app(NationalLegalCalendarReviewService::class)->reviewRange(2026, 2031);

    expect($calendar['technical_coverage_satisfied'])->toBeTrue()
        ->and($calendar['administratively_confirmed'])->toBeFalse()
        ->and($calendar['years'])->toHaveCount(6)
        ->and(collect($calendar['years'])->every(fn (array $year): bool => $year['review_state'] === 'ready_for_administrative_review'))->toBeTrue()
        ->and(collect($calendar['years'])->every(fn (array $year): bool => $year['holiday_count'] === 9))->toBeTrue()
        ->and(collect($calendar['years'])->every(fn (array $year): bool => $year['conflicts'] === 0 && $year['overrides'] === 0))->toBeTrue()
        ->and(collect($calendar['years'])->every(fn (array $year): bool => $year['checksum_reproducible']))->toBeTrue()
        ->and(collect($calendar['years'])->every(fn (array $year): bool => ! in_array(false, $year['excluded_observances'], true)))->toBeTrue()
        ->and(collect($calendar['years'])->flatMap(fn (array $year): array => $year['holidays'])->every(
            fn (array $holiday): bool => filled($holiday['source'])
                && filled($holiday['effective_from'])
                && filled($holiday['fingerprint']),
        ))->toBeTrue()
        ->and(BusinessCalendarYear::query()->where('status', BusinessCalendarYear::STATUS_CONFIRMED)->exists())->toBeFalse();
});

it('rejects premature parameter persistence through the calculated gate', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);

    expect(fn () => app(PuBaselineReadinessService::class)->assertCandidateCanBePersisted(
        $emission->fresh(),
        [],
    ))->toThrow(ValidationException::class)
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('rejects a submitted configuration that diverges from the proven candidate', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $report = app(PuBaselineReadinessService::class)->evaluate($emission->fresh(), CarbonImmutable::today());
    $submitted = collect($report->candidateConfiguration)
        ->only([
            'curve_start_date',
            'curve_end_date',
            'initial_unit_value',
            'spread_rate',
            'indexer',
            'business_day_basis',
            'calendar_code',
            'index_rate_lookup_mode',
            'index_rate_lag_business_days',
            'first_coupon_pre_integralization_premium_enabled',
            'first_coupon_pre_integralization_business_days',
            'first_coupon_pre_integralization_apply_index_factor',
            'first_coupon_pre_integralization_apply_spread_factor',
            'legacy_projection_enabled',
        ])
        ->all();
    $submitted['spread_rate'] = '7.00000000';

    expect(fn () => app(PuBaselineReadinessService::class)->assertCandidateCanBePersisted(
        $emission->fresh(),
        $submitted,
    ))->toThrow(ValidationException::class)
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('does not suggest an inferred curve start date in the configuration form', function () {
    foreach (['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value] as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo(['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value]);
    $this->actingAs($user);
    $emission = cdiEmission();
    proveContractualBaseline($emission);

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('configurePuCalculation')
        ->assertActionDataSet([
            'curve_start_date' => null,
            'curve_end_date' => '2031-05-08',
            'spread_rate' => '6.00000000',
            'index_rate_lookup_mode' => 'business_day_lag_exact',
            'index_rate_lag_business_days' => -5,
            'first_coupon_pre_integralization_premium_enabled' => true,
            'first_coupon_pre_integralization_business_days' => 2,
            'legacy_projection_enabled' => false,
        ]);
});

it('prefills calendar and premium evidence from the confirmed contractual clauses', function () {
    foreach (['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value] as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo(['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value]);
    $this->actingAs($user);
    $emission = cdiEmission();
    $instrument = proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);
    $clauses = [
        'calendar_code' => ['Definição “Dia(s) Útil(eis)”', 8, 'Qualquer dia que não seja sábado, domingo ou feriado nacional.'],
        'first_coupon_pre_integralization_premium_enabled' => ['4.1.8, observação (vii)', 26, 'Prêmio equivalente ao produtório de 2 Dias Úteis.'],
    ];

    foreach ($clauses as $fieldKey => [$clause, $page, $excerpt]) {
        LegalInstrumentField::query()
            ->whereBelongsTo($instrument, 'instrument')
            ->where('field_key', $fieldKey)
            ->update(['clause' => $clause, 'page' => $page, 'excerpt' => $excerpt]);
    }

    $documentTitle = 'Instrumento contratual da emissão '.$emission->id;
    $reviewer = EmissionPuBaselineEvidence::query()->whereBelongsTo($emission)->firstOrFail()->reviewedBy->name;

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('configurePuCalculation')
        ->assertActionDataSet([
            'curve_start_date' => '2026-05-15',
            'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
            'calendar_evidence_document' => $documentTitle,
            'calendar_evidence_clause' => 'Definição “Dia(s) Útil(eis)”',
            'calendar_evidence_page' => '8',
            'calendar_evidence_excerpt' => 'Qualquer dia que não seja sábado, domingo ou feriado nacional.',
            'calendar_evidence_confirmed' => false,
            'first_coupon_premium_evidence_document' => $documentTitle,
            'first_coupon_premium_evidence_clause' => '4.1.8, observação (vii)',
            'first_coupon_premium_evidence_page' => '26',
            'first_coupon_premium_evidence_excerpt' => 'Prêmio equivalente ao produtório de 2 Dias Úteis.',
            'first_coupon_premium_evidence_confirmed' => false,
        ])
        ->assertFormFieldExists('curve_start_date', fn (Field $field): bool => fieldHelperText($field) === sprintf(
            'Comprovado por Extrato de liquidação B3 (Liquidação de 2026-05-15), aprovado por %s.',
            $reviewer,
        ))
        ->assertFormFieldExists('calendar_evidence_document', fn (Field $field): bool => fieldHelperText($field)
            === 'Preenchido com a cláusula confirmada em Instrumentos Jurídicos. Altere só se a fonte for outra.');
});

it('leaves the calendar evidence empty while the gate withholds the proven calendar', function () {
    foreach (['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value] as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo(['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value]);
    $this->actingAs($user);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Boletim de Subscrição']);
    $emission->documents()->attach($document);
    EmissionPuBaselineEvidence::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate,
        'document_type' => 'subscription_bulletin',
        'evidenced_value' => '2026-05-15',
        'confidence' => 'medium',
        'status' => PuBaselineEvidenceStatus::PendingReview,
    ]);

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->mountAction('configurePuCalculation')
        ->assertActionDataSet([
            'curve_start_date' => null,
            'calendar_code' => null,
            'calendar_evidence_document' => null,
            'calendar_evidence_clause' => null,
            'calendar_evidence_page' => null,
            'calendar_evidence_excerpt' => null,
            'first_coupon_premium_evidence_document' => 'Instrumento contratual da emissão '.$emission->id,
        ])
        ->assertFormFieldExists('curve_start_date', fn (Field $field): bool => fieldHelperText($field)
            === '1 evidência da data de integralização aguarda revisão na aba "Evidências do baseline de PU". A data é preenchida aqui depois da aprovação.')
        ->assertFormFieldExists('calendar_evidence_document', fn (Field $field): bool => fieldHelperText($field)
            === 'Opcional. Informe somente quando houver evidência contratual ou normativa aplicável.');
});

it('keeps linked integralization evidence pending until an authorized reviewer explicitly approves it', function () {
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $actor = User::factory()->create();
    $reviewer = User::factory()->create();
    $actor->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    $document = Document::factory()->create(['title' => 'Posição do escriturador na liquidação']);
    $emission->documents()->attach($document);
    $service = app(PuBaselineEvidenceReviewService::class);

    $evidence = $service->create($emission, [
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate->value,
        'document_type' => 'registrar_position',
        'evidenced_value' => '2026-05-15',
        'reference' => 'Posição de liquidação do dia',
        'confidence' => 'high',
    ], $actor);

    expect($evidence->status)->toBe(PuBaselineEvidenceStatus::PendingReview)
        ->and(app(PuBaselineReadinessService::class)->evaluate(
            $emission->fresh(),
            CarbonImmutable::parse('2026-08-26'),
        )->candidateConfiguration['curve_start_date'])->toBe('PENDING');

    $service->approve($evidence, $reviewer, 'Documento comprova a posição usada no cálculo.');
    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-26'),
    );

    expect($report->candidateConfiguration['curve_start_date'])->toBe('2026-05-15')
        ->and($report->requirement('first_integralization_date')->status)->toBe(PuBaselineRequirementStatus::Satisfied)
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('renders the readiness explanation with index source and calendar review facts', function () {
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareNationalCalendar(confirmed: false);
    writeReadyDiDossier();
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);
    $this->actingAs($reviewer);

    Livewire::test(PuBaselineReadinessWidget::class, ['record' => $emission->fresh()])
        ->assertOk()
        ->assertActionVisible('approveDiDossier')
        ->assertSee('Gate de prontidão do PU')
        ->assertSee('413 datas')
        ->assertSee('Pronto para revisão administrativa')
        ->assertSee('Bloqueios em vigor')
        ->assertSee('Homologação externa independente pendente')
        ->assertDontSee('Alto Bellevue');
});

/**
 * Emissão sintética com contexto de CDI.
 *
 * O nome do CRI aparece apenas como rótulo da fixture: nenhuma regra do gate
 * consulta nome, IF ou ISIN. Toda a configuração vem dos
 * `LegalInstrumentField` criados por `proveContractualBaseline()`.
 */
/**
 * Texto de ajuda do campo como o usuário o lê. No Filament v5 o `helperText()`
 * vira um componente do schema abaixo do campo, sem getter próprio.
 */
function fieldHelperText(Field $field): string
{
    $html = (string) $field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString();

    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
}

function cdiEmission(): Emission
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

function prefixedEmission(): Emission
{
    return Emission::factory()->create([
        'name' => 'Debênture Sintética Prefixada',
        'type' => 'DEB',
        'if_code' => '27S0000001',
        'isin_code' => 'BRSYNTDEB001',
        'status' => 'active',
        'issue_date' => '2027-01-04',
        'maturity_date' => '2028-01-10',
        'issued_quantity' => 1200,
        'issued_price' => '5000.00',
        'remuneration_indexer' => 'PREFIXED',
        'remuneration_rate' => '0.00',
        'interest_payment_frequency' => 'Mensal',
        'amortization_frequency' => 'Bullet',
    ]);
}

/**
 * @param  array<string, array{0:?string,1:?float,2:?string}>  $fields
 */
function proveContractualFields(Emission $emission, array $fields, string $effectiveDate): LegalInstrument
{
    $document = Document::factory()->create(['title' => 'Instrumento contratual da emissão '.$emission->id]);
    $emission->documents()->attach($document);
    $instrumentNumber = sprintf('%03d/CONTRATO', $emission->id);
    $instrumentType = $emission->type === 'CRI'
        ? LegalInstrumentType::SecuritizationTerm
        : LegalInstrumentType::Other;
    $instrument = LegalInstrument::factory()
        ->ofType($instrumentType, $instrumentNumber)
        ->create(['emission_id' => $emission->id]);

    foreach ($fields as $fieldKey => [$value, $numeric, $date]) {
        $key = LegalInstrumentFieldKey::from($fieldKey);
        LegalInstrumentField::factory()->for($instrument, 'instrument')->create([
            'field_key' => $key,
            'value_type' => $key->valueType(),
            'value' => $value,
            'value_numeric' => $numeric,
            'value_date' => $date,
            'effective_date' => $effectiveDate,
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

function proveContractualBaseline(Emission $emission): LegalInstrument
{
    return proveContractualFields($emission, [
        'issue_date' => ['2026-05-08', null, '2026-05-08'],
        'indexer' => [PuIndexer::Cdi->value, null, null],
        // Percentage guarda fração: 1.0 = 100%, 0.06 = 6%.
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
    ], '2026-05-08');
}

function provePrefixedContractualBaseline(Emission $emission): LegalInstrument
{
    return proveContractualFields($emission, [
        'issue_date' => ['2027-01-04', null, '2027-01-04'],
        'indexer' => [PuIndexer::Prefixed->value, null, null],
        'business_day_basis' => ['360', 360.0, null],
        'day_count_rule' => ['DU/360', null, null],
        'business_day_definition' => ['Dia útil conforme calendário contratual', null, null],
        'calendar_code' => ['CONTRACT_SYNTHETIC_2027', null, null],
        'initial_unit_value' => ['5000,00', 5000.0, null],
        'maturity_date' => ['2028-01-10', null, '2028-01-10'],
        'payment_schedule' => ['Anexo I — cronograma mensal de juros', null, null],
        'first_interest_payment_date' => ['2027-02-10', null, '2027-02-10'],
        'interest_payment_frequency' => ['monthly', null, null],
        'amortization' => ['bullet', null, null],
        'payment_convention' => ['following_business_day', null, null],
        'first_coupon_pre_integralization_premium_enabled' => ['0', null, null],
    ], '2027-01-04');
}

/**
 * Aditamento: nova linha confirmada, anterior passa a `superseded` — a mesma
 * mecânica de `InstrumentChangeReviewService::confirm()`.
 */
function amendContractualField(
    Emission $emission,
    LegalInstrumentFieldKey $key,
    ?string $value,
    ?float $numeric,
    string $effectiveDate,
    ?string $date = null,
): LegalInstrumentField {
    /** @var LegalInstrumentField $previous */
    $previous = LegalInstrumentField::query()
        ->whereIn('legal_instrument_id', $emission->legalInstruments()->pluck('id'))
        ->where('field_key', $key->value)
        ->where('status', LegalInstrumentFieldStatus::Confirmed->value)
        ->orderByDesc('effective_date')
        ->orderByDesc('id')
        ->firstOrFail();

    $amended = LegalInstrumentField::factory()->create([
        'legal_instrument_id' => $previous->legal_instrument_id,
        'field_key' => $key,
        'value_type' => $key->valueType(),
        'value' => $value,
        'value_numeric' => $numeric,
        'value_date' => $date,
        'effective_date' => $effectiveDate,
        'status' => LegalInstrumentFieldStatus::Confirmed,
        'document_id' => $previous->document_id,
        'supersedes_id' => $previous->id,
        'clause' => '1.1 do aditamento',
        'page' => 2,
        'confidence_score' => 0.95,
        'has_conflict' => false,
    ]);

    $previous->forceFill(['status' => LegalInstrumentFieldStatus::Superseded])->save();

    return $amended;
}

function prepareCandidateGovernance(Emission $emission): void
{
    prepareNationalCalendar(confirmed: true);
    approveReadyDiDossier();
    proveFirstIntegralizationDate($emission);
}

function prepareCandidatePrerequisites(Emission $emission): void
{
    prepareCandidateGovernance($emission);
}

function prepareNationalCalendar(bool $confirmed): void
{
    $responsible = User::factory()->create();
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2031, $responsible->id);

    if (! $confirmed) {
        return;
    }

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

/**
 * Calendário contratual sintético coberto e confirmado, sem materializar dia a
 * dia: a base `weekday_with_official_exceptions` considera cobertura completa
 * quando ano e execução oficial fecham o mesmo checksum.
 */
function prepareContractualCalendar(string $calendarCode, int $fromYear, int $toYear): void
{
    $responsible = User::factory()->create();
    BusinessCalendar::factory()->create([
        'code' => $calendarCode,
        'name' => 'Calendário contratual sintético',
        'materialization_policy' => BusinessCalendar::MATERIALIZATION_POLICY_WEEKDAY_WITH_OFFICIAL_EXCEPTIONS,
        'is_official' => true,
        'financial_use_allowed' => true,
        'available_for_new_configurations' => true,
    ]);

    foreach (range($fromYear, $toYear) as $year) {
        $checksum = hash('sha256', $calendarCode.$year);
        $calendarYear = BusinessCalendarYear::factory()->create([
            'calendar_code' => $calendarCode,
            'year' => $year,
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            'source' => 'contrato',
            'source_is_official' => true,
            'source_document' => 'anexo-calendario.pdf',
            'checksum' => $checksum,
        ]);
        BusinessCalendarImportRun::factory()->create([
            'business_calendar_year_id' => $calendarYear->id,
            'calendar_code' => $calendarCode,
            'year' => $year,
            'source' => 'contrato',
            'source_is_official' => true,
            'source_document' => 'anexo-calendario.pdf',
            'checksum' => $checksum,
            'dry_run' => false,
            'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
        ]);

        app(BusinessCalendarYearService::class)->confirm(
            $calendarCode,
            $year,
            'contrato',
            'anexo-calendario.pdf',
            null,
            $checksum,
            $responsible->id,
        );
    }
}

function proveFirstIntegralizationDate(Emission $emission, string $date = '2026-05-15'): EmissionPuBaselineEvidence
{
    $document = Document::factory()->create(['title' => 'Extrato de liquidação B3']);
    $emission->documents()->attach($document);

    return EmissionPuBaselineEvidence::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate,
        'document_type' => 'b3_settlement_statement',
        'evidenced_value' => $date,
        'reference' => 'Liquidação de '.$date,
        'confidence' => 'high',
        'status' => PuBaselineEvidenceStatus::Approved,
        'reviewed_by' => User::factory(),
        'reviewed_at' => now(),
    ]);
}

function proveExternalPuReference(Emission $emission): EmissionPuBaselineEvidence
{
    $document = Document::factory()->create(['title' => 'Memória oficial de PU do escriturador']);
    $emission->documents()->attach($document);

    return EmissionPuBaselineEvidence::factory()->create([
        'emission_id' => $emission->id,
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::ExternalPuReference,
        'document_type' => 'official_pu_memory',
        'evidenced_value' => 'matched',
        'reference' => 'Comparação independente aderente',
        'confidence' => 'high',
        'status' => PuBaselineEvidenceStatus::Approved,
        'reviewed_by' => User::factory(),
        'reviewed_at' => now(),
    ]);
}

function writeReadyDiDossier(?int $executorUserId = null): string
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
                'payloads' => [['url' => 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.4389/dados', 'sha256' => str_repeat('e', 64)]],
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
            'user_id' => $executorUserId,
        ],
    ];
    $report['report_checksum'] = hash('sha256', (string) json_encode(
        $report,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
    $path = 'homologations/index-rate-sources/cdi-b3-vs-bcb-4389-20260825-151927-153858.json';
    Storage::disk('local')->put($path, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));

    return $report['report_checksum'];
}

function approveReadyDiDossier(): IndexRateSourceGovernanceReview
{
    $reportChecksum = writeReadyDiDossier();

    return IndexRateSourceGovernanceReview::factory()->create([
        'source_code' => 'bcb_sgs_4389',
        'report_checksum' => $reportChecksum,
        'artifact_disk' => 'local',
        'artifact_path' => 'homologations/index-rate-sources/cdi-b3-vs-bcb-4389-20260825-151927-153858.json',
        'status' => 'approved',
        'reviewed_by' => User::factory(),
        'reviewed_at' => now(),
        'review_notes' => 'Dossiê revisado e aprovado para uso operacional.',
    ]);
}

/** @param list<array<string, mixed>> $requirements */
function loadRequiredEvents(Emission $emission, array $requirements): void
{
    foreach ($requirements as $requirement) {
        EmissionPuEvent::factory()->create([
            'emission_id' => $emission->id,
            'event_type' => $requirement['event_type'],
            'original_date' => $requirement['original_date'],
            'effective_date' => $requirement['effective_date'],
            'amortization_type' => $requirement['amortization_type'] ?? PuAmortizationType::None->value,
            'amortization_value' => $requirement['amortization_value'] ?? null,
            'sequence' => $requirement['sequence'] ?? 1,
        ]);
    }
}

/** @param list<string> $rateDates */
function loadRequiredRates(array $rateDates, string $source = 'bcb_sgs'): void
{
    foreach ($rateDates as $rateDate) {
        IndexRate::factory()->create([
            'indexer' => 'CDI',
            'rate_date' => $rateDate,
            'rate_value' => '14.90000000',
            'source' => $source,
            'source_reference' => $source.':4389:'.$rateDate,
            'external_series_code' => '4389',
        ]);
    }
}

/** @return array<string, int> */
function financialSideEffectCounts(): array
{
    return [
        'parameters' => EmissionPuParameter::query()->count(),
        'rates' => IndexRate::query()->count(),
        'events' => EmissionPuEvent::query()->count(),
        'curves' => EmissionPuDailyCurve::query()->count(),
        'pu_histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
        'obligations' => Obligation::query()->count(),
    ];
}
