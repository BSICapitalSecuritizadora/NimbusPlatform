<?php

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuBaselineCandidateFactory;
use App\Domain\PuCalculator\Services\PuBaselineCandidatePersistenceService;
use App\Domain\PuCalculator\Services\PuBaselineReadinessService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config([
        'pu_indexes.source_homologation.artifact_disk' => 'local',
        'pu_indexes.source_homologation.artifact_directory' => 'homologations/index-rate-sources',
    ]);
});

function persistenceGateCBlockedEmission(): Emission
{
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareNationalCalendar(confirmed: true);
    approveReadyDiDossier();

    return $emission->fresh();
}

function persistenceReadyEmission(): Emission
{
    $emission = cdiEmission();
    proveContractualBaseline($emission);
    prepareCandidatePrerequisites($emission);

    return $emission->fresh();
}

function authorizedPuParameterActor(array $attributes = []): User
{
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    $actor = User::factory()->create($attributes);
    $actor->givePermissionTo(AccessPermission::PuParametersConfigure->value);

    return $actor;
}

function createExistingCandidateParameter(
    Emission $emission,
    array $overrides = [],
): EmissionPuParameter {
    $dryRun = app(PuBaselineCandidatePersistenceService::class)->dryRun(
        $emission,
        CarbonImmutable::parse('2026-08-27'),
    );

    return $emission->puParameter()->create([
        ...$dryRun->proposedConfiguration,
        ...$overrides,
    ]);
}

it('blocks the current candidate while curve_start_date remains PENDING and changes no financial rows', function () {
    $emission = persistenceGateCBlockedEmission();
    $actor = authorizedPuParameterActor();
    $before = [
        'parameters' => EmissionPuParameter::query()->whereBelongsTo($emission)->count(),
        'rates' => IndexRate::query()->count(),
        'events' => EmissionPuEvent::query()->whereBelongsTo($emission)->count(),
        'curves' => EmissionPuDailyCurve::query()->whereBelongsTo($emission)->count(),
        'history' => PuHistory::query()->whereBelongsTo($emission)->count(),
        'payments' => Payment::query()->whereBelongsTo($emission)->count(),
    ];

    $result = app(PuBaselineCandidatePersistenceService::class)->write(
        $emission,
        $actor->email,
        CarbonImmutable::parse('2026-08-27'),
    );
    $futureWindow = $result->futureSnapshotWindow;
    $confirmedCalendar = $result->provenance['confirmed_calendar'];
    $requiredCalendarWindow = $result->provenance['required_calendar_window'];
    $proposedSentinelFields = collect($result->proposedConfiguration)
        ->filter(fn (mixed $value): bool => is_string($value) && in_array(
            $value,
            ['PENDING', 'UNKNOWN', 'UNRESOLVED', 'N/A'],
            true,
        ))
        ->keys()
        ->all();

    expect($result->readinessStatus)->toBe(PuBaselineReadinessStatus::Blocked->value)
        ->and($result->pendingFields)->toBe(['curve_start_date'])
        ->and($result->blockingRequirements)->toContain('first_integralization_date')
        ->and($result->action)->toBe(PuBaselineCandidatePersistenceService::ACTION_CANDIDATE_NOT_READY)
        ->and($result->writes)->toBe(0)
        ->and($result->candidate['curve_start_date'])->toBe('PENDING')
        ->and($result->proposedConfiguration['curve_start_date'])->toBeNull()
        ->and(collect($result->proposedConfiguration)->containsStrict('PENDING'))->toBeFalse()
        ->and($proposedSentinelFields)->toBe([])
        ->and($futureWindow)->toMatchArray([
            'resolvable' => false,
            'reason' => 'curve_start_date_pending',
            'curve_start_date' => null,
            'calendar_required_from' => null,
            'calendar_required_to' => null,
            'rate_required_from' => null,
            'rate_required_to' => null,
            'required_rate_dates' => [],
            'first_rate_lookups' => [],
        ])
        ->and($confirmedCalendar)->not->toHaveKeys(['required_from', 'required_to'])
        ->and($confirmedCalendar['administratively_confirmed'])->toBeTrue()
        ->and($confirmedCalendar['confirmed_years'])->toBe([2026, 2027, 2028, 2029, 2030, 2031])
        ->and(collect($confirmedCalendar['years'])->every(
            fn (array $year): bool => array_key_exists('year', $year)
                && array_key_exists('coverage_status', $year)
                && array_key_exists('governance_status', $year)
                && array_key_exists('review_state', $year)
                && filled($year['checksum'] ?? null),
        ))->toBeTrue()
        ->and($requiredCalendarWindow)->toBe([
            'resolvable' => false,
            'reason' => 'curve_start_date_pending',
            'curve_start_date' => null,
            'from' => null,
            'to' => null,
        ])
        ->and($result->provenance['candidate_fingerprint_semantics'])->toBe([
            'purpose' => 'diagnostic_candidate',
            'includes_pending_values' => true,
            'authorizes_persistence' => false,
        ])
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->count())->toBe($before['parameters'])
        ->and(IndexRate::query()->count())->toBe($before['rates'])
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe($before['events'])
        ->and(EmissionPuDailyCurve::query()->whereBelongsTo($emission)->count())->toBe($before['curves'])
        ->and(PuHistory::query()->whereBelongsTo($emission)->count())->toBe($before['history'])
        ->and(Payment::query()->whereBelongsTo($emission)->count())->toBe($before['payments']);
});

it('reports will_create in dry-run for a naturally ready candidate without persisting it', function () {
    $emission = persistenceReadyEmission();
    $service = app(PuBaselineCandidatePersistenceService::class);
    $asOf = CarbonImmutable::parse('2026-08-27');

    $readiness = app(PuBaselineReadinessService::class)->evaluate($emission, $asOf);
    $first = $service->dryRun($emission, $asOf);
    $second = $service->dryRun($emission, $asOf);
    $indexPercentage = collect($first->mapping)->firstWhere('candidate_field', 'index_percentage');
    $requiredRateDates = array_values($readiness->rateWindow['required_rate_dates']);

    expect($first->readinessStatus)->toBe(PuBaselineReadinessStatus::ReadyForCandidateConfiguration->value)
        ->and($first->pendingFields)->toBe([])
        ->and($first->action)->toBe(PuBaselineCandidatePersistenceService::ACTION_WILL_CREATE)
        ->and($first->writes)->toBe(0)
        ->and($first->candidate['index_percentage'])->toBe('100.00000000')
        ->and($indexPercentage['parameter_field'])->toBeNull()
        ->and($indexPercentage['persisted'])->toBeFalse()
        ->and($indexPercentage['reason'])->toContain('100%')
        ->and($first->proposedConfiguration)->not->toHaveKey('index_percentage')
        ->and(Schema::hasColumn('emission_pu_parameters', 'index_percentage'))->toBeFalse()
        ->and($first->candidateFingerprint)->toBe($second->candidateFingerprint)
        ->and(mb_strlen($first->candidateFingerprint))->toBe(64)
        ->and($first->futureSnapshotWindow['resolvable'])->toBeTrue()
        ->and($first->futureSnapshotWindow['reason'])->toBeNull()
        ->and($first->futureSnapshotWindow['curve_start_date'])->toBe(
            $readiness->rateWindow['curve_start_date'],
        )
        ->and($first->futureSnapshotWindow['calendar_required_from'])->toBe(
            $readiness->calendarDiagnostics['required_from'],
        )
        ->and($first->futureSnapshotWindow['calendar_required_to'])->toBe(
            $readiness->calendarDiagnostics['required_to'],
        )
        ->and($first->futureSnapshotWindow['required_rate_dates'])->not->toBeEmpty()
        ->and($first->futureSnapshotWindow['rate_required_from'])->toBe(
            $requiredRateDates[0],
        )
        ->and($first->futureSnapshotWindow['rate_required_to'])->toBe(
            $requiredRateDates[array_key_last($requiredRateDates)],
        )
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('creates the exact persistable configuration for an authorized actor and records provenance', function () {
    $emission = persistenceReadyEmission();
    $actor = authorizedPuParameterActor();
    $beforeCounts = [
        'rates' => IndexRate::query()->count(),
        'events' => EmissionPuEvent::query()->whereBelongsTo($emission)->count(),
        'curves' => EmissionPuDailyCurve::query()->whereBelongsTo($emission)->count(),
        'history' => PuHistory::query()->whereBelongsTo($emission)->count(),
        'payments' => Payment::query()->whereBelongsTo($emission)->count(),
    ];

    $result = app(PuBaselineCandidatePersistenceService::class)->write(
        $emission,
        (string) $actor->id,
        CarbonImmutable::parse('2026-08-27'),
    );
    $parameter = EmissionPuParameter::query()->whereBelongsTo($emission)->sole();
    $activity = Activity::query()
        ->where('log_name', PuAuditLogService::LOG_NAME)
        ->where('description', 'pu_candidate_configuration_created')
        ->where('subject_type', Emission::class)
        ->where('subject_id', $emission->id)
        ->sole();
    $nonPersistedFields = $activity->properties->get('non_persisted_proven_fields');

    expect($result->action)->toBe(PuBaselineCandidatePersistenceService::ACTION_CREATED)
        ->and($result->writes)->toBe(1)
        ->and($result->parameterId)->toBe($parameter->id)
        ->and($result->actorId)->toBe($actor->id)
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->count())->toBe(1)
        ->and($parameter->indexer)->toBe('CDI')
        ->and($parameter->spread_rate)->toBe('6.00000000')
        ->and($parameter->calculation_method)->toBe(PuCalculationMethod::CdiSpread->value)
        ->and($parameter->business_day_basis)->toBe(252)
        ->and($parameter->calendar_code)->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($parameter->index_rate_lookup_mode)->toBe(PuIndexRateLookupMode::BusinessDayLagExact->value)
        ->and($parameter->index_rate_lag_business_days)->toBe(-5)
        ->and($parameter->curve_start_date->toDateString())->toBe('2026-05-15')
        ->and($parameter->curve_end_date->toDateString())->toBe('2031-05-08')
        ->and($parameter->initial_unit_value)->toBe('1000.0000000000000000')
        ->and($parameter->first_coupon_pre_integralization_premium_enabled)->toBeTrue()
        ->and($parameter->first_coupon_pre_integralization_business_days)->toBe(2)
        ->and($parameter->first_coupon_pre_integralization_apply_index_factor)->toBeTrue()
        ->and($parameter->first_coupon_pre_integralization_apply_spread_factor)->toBeTrue()
        ->and($parameter->legacy_projection_enabled)->toBeFalse()
        ->and($result->financialEffects['existing_parameter_id'])->toBe($parameter->id)
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->properties->get('emission_id'))->toBe($emission->id)
        ->and($activity->properties->get('parameter_id'))->toBe($parameter->id)
        ->and($activity->properties->get('readiness_status'))->toBe(
            PuBaselineReadinessStatus::ReadyForCandidateConfiguration->value,
        )
        ->and($activity->properties->get('candidate_source'))->toBe(PuBaselineCandidateFactory::class)
        ->and($activity->properties->get('candidate_fingerprint'))->toBe($result->candidateFingerprint)
        ->and($activity->properties->get('persisted_at'))->toBeString()->not->toBeEmpty()
        ->and($nonPersistedFields)->toHaveCount(1)
        ->and($nonPersistedFields[0]['candidate_field'])->toBe('index_percentage')
        ->and($nonPersistedFields[0]['value'])->toBe('100.00000000')
        ->and(IndexRate::query()->count())->toBe($beforeCounts['rates'])
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe($beforeCounts['events'])
        ->and(EmissionPuDailyCurve::query()->whereBelongsTo($emission)->count())->toBe($beforeCounts['curves'])
        ->and(PuHistory::query()->whereBelongsTo($emission)->count())->toBe($beforeCounts['history'])
        ->and(Payment::query()->whereBelongsTo($emission)->count())->toBe($beforeCounts['payments']);
});

it('is idempotent and serializes repeated creation attempts without duplicates', function () {
    $emission = persistenceReadyEmission();
    $actor = authorizedPuParameterActor();
    $service = app(PuBaselineCandidatePersistenceService::class);

    $first = $service->write($emission, $actor->email, CarbonImmutable::parse('2026-08-27'));
    $second = $service->write($emission, $actor->email, CarbonImmutable::parse('2026-08-27'));

    expect($first->action)->toBe(PuBaselineCandidatePersistenceService::ACTION_CREATED)
        ->and($second->action)->toBe(PuBaselineCandidatePersistenceService::ACTION_ALREADY_MATCHES)
        ->and($second->writes)->toBe(0)
        ->and($second->diff)->toBe([])
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->count())->toBe(1)
        ->and(Activity::query()->where('description', 'pu_candidate_configuration_created')->count())->toBe(1);
});

it('reports every material divergence and never overwrites an existing financial configuration', function () {
    $emission = persistenceReadyEmission();
    $parameter = createExistingCandidateParameter($emission, ['spread_rate' => '7.50000000']);
    EmissionPuDailyCurve::factory()->create(['emission_id' => $emission->id]);
    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-05-15',
        'unit_value' => '1000.000000',
    ]);
    EmissionPuEvent::factory()->create(['emission_id' => $emission->id]);
    Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => '2026-06-08',
        'premium_value' => '0.00',
        'interest_value' => '1.00',
        'amortization_value' => '0.00',
        'extra_amortization_value' => '0.00',
    ]);
    EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'status' => PuCurveStatus::Validated->value,
    ]);
    EmissionPuCurveVersion::factory()->homologated()->create(['emission_id' => $emission->id]);
    $originalUpdatedAt = $parameter->updated_at?->toIso8601String();
    $actor = authorizedPuParameterActor();

    expect($parameter->spread_rate)->toBe('7.50000000');

    $result = app(PuBaselineCandidatePersistenceService::class)->write(
        $emission,
        $actor->email,
        CarbonImmutable::parse('2026-08-27'),
    );
    $spreadDiff = collect($result->diff)->firstWhere('field', 'spread_rate');

    expect($result->action)->toBe(PuBaselineCandidatePersistenceService::ACTION_CONFIGURATION_CONFLICT)
        ->and($result->writes)->toBe(0)
        ->and($result->parameterId)->toBe($parameter->id)
        ->and($spreadDiff)->toBe([
            'field' => 'spread_rate',
            'existing' => '7.50000000',
            'candidate' => '6.00000000',
        ])
        ->and($result->financialEffects['existing_parameter_id'])->toBe($parameter->id)
        ->and($result->financialEffects['has_curves'])->toBeTrue()
        ->and($result->financialEffects['has_history'])->toBeTrue()
        ->and($result->financialEffects['has_events'])->toBeTrue()
        ->and($result->financialEffects['has_payments'])->toBeTrue()
        ->and($result->financialEffects['has_validated_curves'])->toBeTrue()
        ->and($result->financialEffects['has_homologated_curves'])->toBeTrue()
        ->and($parameter->fresh()->spread_rate)->toBe('7.50000000')
        ->and($parameter->fresh()->updated_at?->toIso8601String())->toBe($originalUpdatedAt)
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->count())->toBe(1)
        ->and(Activity::query()->where('description', 'pu_candidate_configuration_created')->exists())->toBeFalse();
});

it('blocks invalid actors before creating a ready candidate', function (
    string $actorState,
    string $expectedAction,
) {
    $emission = persistenceReadyEmission();
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value);
    $identifier = match ($actorState) {
        'missing' => null,
        'nonexistent' => 'ghost@example.com',
        'inactive' => authorizedPuParameterActor(['is_active' => false])->email,
        'unapproved' => authorizedPuParameterActor(['approved_at' => null])->email,
        'unauthorized' => User::factory()->create()->email,
    };

    $result = app(PuBaselineCandidatePersistenceService::class)->write(
        $emission,
        $identifier,
        CarbonImmutable::parse('2026-08-27'),
    );

    expect($result->action)->toBe($expectedAction)
        ->and($result->writes)->toBe(0)
        ->and(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
})->with([
    'missing' => ['missing', PuBaselineCandidatePersistenceService::ACTION_ACTOR_REQUIRED],
    'nonexistent' => ['nonexistent', PuBaselineCandidatePersistenceService::ACTION_ACTOR_NOT_FOUND],
    'inactive' => ['inactive', PuBaselineCandidatePersistenceService::ACTION_ACTOR_INACTIVE],
    'unapproved' => ['unapproved', PuBaselineCandidatePersistenceService::ACTION_ACTOR_UNAPPROVED],
    'unauthorized' => ['unauthorized', PuBaselineCandidatePersistenceService::ACTION_ACTOR_UNAUTHORIZED],
]);

it('keeps numeric homologation and aggregate outputs blocked after parameter persistence', function () {
    $emission = persistenceReadyEmission();
    $actor = authorizedPuParameterActor();
    app(PuBaselineCandidatePersistenceService::class)->write(
        $emission,
        $actor->email,
        CarbonImmutable::parse('2026-08-27'),
    );

    $report = app(PuBaselineReadinessService::class)->evaluate(
        $emission->fresh(),
        CarbonImmutable::parse('2026-08-27'),
    );

    expect($report->status)->toBe(PuBaselineReadinessStatus::ReadyForCandidateConfiguration)
        ->and($report->status)->not->toBe(PuBaselineReadinessStatus::ReadyForNumericHomologation)
        ->and($report->requirement('index_snapshots_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('pu_events_loaded')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('integralized_quantity')->status)->toBe(PuBaselineRequirementStatus::Blocking)
        ->and($report->requirement('integralized_quantity')->blocks)->toBe(['aggregate_outputs'])
        ->and($report->quantityDiagnostics['required_for_unit_pu'])->toBeFalse()
        ->and($report->quantityDiagnostics['required_for_aggregate_balance_and_payments'])->toBeTrue()
        ->and(EmissionPuBaselineEvidence::query()
            ->whereBelongsTo($emission)
            ->where('evidence_type', PuBaselineEvidenceType::IntegralizedQuantity->value)
            ->exists())->toBeFalse();
});

it('defaults the pilot command to a safe blocked dry-run on the current Gate C state', function () {
    $emission = persistenceGateCBlockedEmission();

    $this->artisan('pu:alto-bellevue:persist-candidate')
        ->expectsOutputToContain('Readiness: blocked')
        ->expectsOutputToContain('Pending fields: ["curve_start_date"]')
        ->expectsOutputToContain('Action: candidate_configuration_not_ready')
        ->expectsOutputToContain('Writes: 0')
        ->expectsOutputToContain('Diagnostic candidate fingerprint')
        ->expectsOutputToContain('Future snapshot window (report only; no import):')
        ->assertExitCode(0);

    expect(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});

it('requires an explicit actor when the pilot command is asked to write', function () {
    $emission = persistenceReadyEmission();

    $this->artisan('pu:alto-bellevue:persist-candidate', ['--write' => true])
        ->expectsOutputToContain('Action: actor_required')
        ->expectsOutputToContain('Writes: 0')
        ->assertExitCode(1);

    expect(EmissionPuParameter::query()->whereBelongsTo($emission)->exists())->toBeFalse();
});
