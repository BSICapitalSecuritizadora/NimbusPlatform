<?php

use App\Domain\PuCalculator\Services\B3ListedCalendarSanitationService;
use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\BusinessCalendarCoverageService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\Emission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function seedKnownB3ListedInferenceSet(): void
{
    Date::setTestNow(CarbonImmutable::parse(B3ListedCalendarSanitationService::EXPECTED_CREATED_AT, 'UTC'));
    $yearIds = [];

    for ($year = 2021; $year <= 2029; $year++) {
        $yearIds[$year] = BusinessCalendarYear::query()->create([
            'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
            'year' => $year,
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            'source' => 'calendar_inference',
            'source_is_official' => false,
            'source_document' => null,
            'source_revision' => null,
            'revision' => 2,
            'checksum' => null,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ])->id;
    }

    $rows = [];

    for ($date = CarbonImmutable::parse('2021-01-01'); $date->lte(CarbonImmutable::parse('2029-12-31')); $date = $date->addDay()) {
        $rows[] = [
            'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
            'business_calendar_year_id' => $yearIds[$date->year],
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
            'data_origin' => 'inferred',
            'source' => 'calendar_inference',
            'source_is_official' => false,
            'source_document' => null,
            'source_revision' => null,
            'revision' => 2,
            'import_run_id' => null,
            'created_at' => B3ListedCalendarSanitationService::EXPECTED_CREATED_AT,
            'updated_at' => B3ListedCalendarSanitationService::EXPECTED_CREATED_AT,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        BusinessCalendarDate::query()->insert($chunk);
    }
}

it('blocks generic weekday backfill for B3 listed and keeps it unavailable', function () {
    expect(fn () => app(BusinessCalendarCoverageService::class)->backfill(
        BusinessCalendarRegistry::B3_LISTED_TRADING,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-31'),
    ))->toThrow(InvalidArgumentException::class, 'não permite backfill genérico');

    $this->artisan('pu:business-calendar:seed', [
        '--calendar' => BusinessCalendarRegistry::B3_LISTED_TRADING,
        '--from' => '2026-01-01',
        '--to' => '2026-01-31',
    ])->expectsOutputToContain('não permite backfill genérico')->assertExitCode(1);

    $calendar = BusinessCalendar::query()->where('code', BusinessCalendarRegistry::B3_LISTED_TRADING)->firstOrFail();

    expect($calendar->materialization_policy)->toBe(BusinessCalendar::MATERIALIZATION_POLICY_EXPLICIT_OFFICIAL_DECISIONS)
        ->and($calendar->is_official)->toBeFalse()
        ->and($calendar->financial_use_allowed)->toBeFalse()
        ->and($calendar->available_for_new_configurations)->toBeFalse()
        ->and(app(BusinessCalendarCatalogService::class)->optionsForNewConfiguration())
        ->not->toHaveKey(BusinessCalendarRegistry::B3_LISTED_TRADING);
});

it('identifies the known inferred set by provenance period timestamp revision and checksum', function () {
    seedKnownB3ListedInferenceSet();

    $audit = app(B3ListedCalendarSanitationService::class)->audit();

    expect($audit)->toMatchArray([
        'calendar_count' => 3287,
        'calendar_from' => '2021-01-01',
        'calendar_to' => '2029-12-31',
        'candidate_count' => 3287,
        'candidate_from' => '2021-01-01',
        'candidate_to' => '2029-12-31',
        'origins' => ['inferred' => 3287],
        'sources' => ['calendar_inference' => 3287],
        'revisions' => ['2' => 3287],
        'batch_uuid' => null,
        'safe_to_execute' => true,
    ])->and($audit['checksum'])->toHaveLength(64)
        ->and($audit['gates'])->each->toBeTrue()
        ->and(array_sum($audit['consumers']))->toBe(0)
        ->and($audit['related'])->toMatchArray([
            'holidays' => 0,
            'overrides' => 0,
            'import_runs' => 0,
            'staging_batches' => 0,
        ]);
});

it('removes only the fingerprinted inferred set and preserves official future data and unrelated consumers', function () {
    seedKnownB3ListedInferenceSet();
    Date::setTestNow(CarbonImmutable::parse('2026-08-25 12:00:00', 'UTC'));
    $responsible = User::factory()->create();
    $officialFuture = BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
        'calendar_date' => '2030-01-02',
        'is_business_day' => true,
        'description' => 'Sessão futura publicada pela B3',
        'data_origin' => 'imported',
        'source' => 'b3_official_schedule',
        'source_is_official' => true,
        'source_document' => 'https://www.b3.com.br/future-official-source',
        'source_revision' => '2030-v1',
        'revision' => 1,
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'calendar_date' => '2026-01-01',
        'is_business_day' => false,
        'description' => 'Controle legado',
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => '2026-01-01',
        'is_business_day' => false,
        'description' => 'Controle ANBIMA',
    ]);
    $unrelatedBefore = [
        'legacy' => BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->get()->toArray(),
        'anbima' => BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)->get()->toArray(),
        'pu_parameters' => DB::table('emission_pu_parameters')->count(),
        'pu_events' => DB::table('emission_pu_events')->count(),
        'pu_curves' => DB::table('emission_pu_daily_curves')->count(),
        'obligations' => DB::table('obligations')->count(),
        'obligation_series' => DB::table('obligation_series')->count(),
    ];
    $sanitation = app(B3ListedCalendarSanitationService::class);
    $audit = $sanitation->audit();

    expect($audit['candidate_count'])->toBe(3287)
        ->and($audit['calendar_count'])->toBe(3288)
        ->and($audit['safe_to_execute'])->toBeTrue();

    $result = $sanitation->sanitize($audit['checksum'], $responsible->id);

    expect($result['removed_dates'])->toBe(3287)
        ->and($result['removed_years'])->toBe(9)
        ->and($result['after'])->toMatchArray([
            'count' => 1,
            'from' => '2030-01-02',
            'to' => '2030-01-02',
        ])
        ->and(BusinessCalendarDate::query()->whereKey($officialFuture->id)->exists())->toBeTrue()
        ->and(BusinessCalendarYear::query()->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count())->toBe(0)
        ->and(BusinessCalendarImportRun::query()->whereKey($result['import_run_id'])->firstOrFail())->toMatchArray([
            'batch_uuid' => $result['batch_uuid'],
            'source' => 'controlled_sanitation',
            'records_found' => 3287,
            'records_changed' => 3287,
            'removals_detected' => 3287,
        ]);

    $activity = Activity::query()->findOrFail($result['activity_id']);

    expect($activity->batch_uuid)->toBe($result['batch_uuid'])
        ->and($activity->properties->get('before')['checksum'])->toBe($audit['checksum'])
        ->and($activity->properties->get('after')['count'])->toBe(1)
        ->and(BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->get()->toArray())->toBe($unrelatedBefore['legacy'])
        ->and(BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)->get()->toArray())->toBe($unrelatedBefore['anbima'])
        ->and(DB::table('emission_pu_parameters')->count())->toBe($unrelatedBefore['pu_parameters'])
        ->and(DB::table('emission_pu_events')->count())->toBe($unrelatedBefore['pu_events'])
        ->and(DB::table('emission_pu_daily_curves')->count())->toBe($unrelatedBefore['pu_curves'])
        ->and(DB::table('obligations')->count())->toBe($unrelatedBefore['obligations'])
        ->and(DB::table('obligation_series')->count())->toBe($unrelatedBefore['obligation_series']);
});

it('reports a financial consumer as a blocking safety gate', function () {
    $emission = Emission::factory()->create();
    $emission->puParameter()->create([
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
        'index_rate_lookup_mode' => 'previous_available_business_day',
        'legacy_projection_enabled' => false,
    ]);

    $audit = app(B3ListedCalendarSanitationService::class)->audit();

    expect($audit['consumers']['emission_pu_parameters'])->toBe(1)
        ->and($audit['gates']['no_financial_consumers'])->toBeFalse()
        ->and($audit['safe_to_execute'])->toBeFalse();
});
