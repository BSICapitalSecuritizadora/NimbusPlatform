<?php

use App\Domain\PuCalculator\Services\BusinessCalendarDiffService;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\NationalLegalHolidayMaterializationService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarLegalRule;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('materializes the 2026 national legal holidays from versioned federal rules without confirmation', function () {
    $responsible = User::factory()->create();
    $result = app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, $responsible->id);
    $calendar = BusinessCalendar::query()->where('code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)->firstOrFail();
    $holidayDates = BusinessHoliday::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->whereYear('holiday_date', 2026)
        ->orderBy('holiday_date')
        ->pluck('holiday_date')
        ->map(fn ($date): string => CarbonImmutable::parse((string) $date)->format('m-d'))
        ->all();

    expect($calendar)->toMatchArray([
        'name' => 'Feriados Nacionais — Brasil',
        'calendar_type' => 'federal_legal_holidays',
        'materialization_policy' => BusinessCalendar::MATERIALIZATION_POLICY_WEEKDAY_WITH_OFFICIAL_EXCEPTIONS,
        'financial_use_allowed' => false,
        'available_for_new_configurations' => false,
    ])->and($calendar->purpose)->toContain('Não inclui automaticamente feriados bancários, Carnaval, Corpus Christi')
        ->and($result['rules'])->toBe(13)
        ->and($result['years'][2026])->toMatchArray([
            'holidays' => 9,
            'inserted' => 9,
            'coverage_status' => 'complete',
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
        ])
        ->and($holidayDates)->toBe([
            '01-01',
            '04-21',
            '05-01',
            '09-07',
            '10-12',
            '11-02',
            '11-15',
            '11-20',
            '12-25',
        ]);

    $coverage = app(BusinessCalendarYearService::class)->coverage(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS, 2026);
    $calendarYear = BusinessCalendarYear::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->where('year', 2026)
        ->firstOrFail();

    expect($coverage)->toMatchArray([
        'coverage_status' => 'complete',
        'governance_status' => BusinessCalendarYear::STATUS_PROVISIONAL,
        'state' => 'provisional',
        'confirmed' => false,
        'source_is_official' => true,
    ])->and($calendarYear->confirmed_at)->toBeNull()
        ->and($calendarYear->confirmed_by)->toBeNull()
        ->and($calendarYear->checksum)->toHaveLength(64)
        ->and($calendarYear->source_document)->toContain('planalto.gov.br');
});

it('distinguishes federal holidays from weekends and market or local observances in 2026', function () {
    $responsible = User::factory()->create();
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, $responsible->id);
    $calendar = app(BusinessCalendarService::class);
    $code = BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS;

    foreach (['2026-01-01', '2026-04-21', '2026-05-01', '2026-09-07', '2026-10-12', '2026-11-02', '2026-11-15', '2026-11-20', '2026-12-25'] as $holiday) {
        expect($calendar->isBusinessDay(CarbonImmutable::parse($holiday), $code))->toBeFalse();
    }

    expect(BusinessCalendarDate::query()
        ->where('calendar_code', $code)
        ->whereDate('calendar_date', '2026-01-03')
        ->exists())->toBeFalse()
        ->and($calendar->isBusinessDay(CarbonImmutable::parse('2026-01-03'), $code))->toBeFalse()
        ->and($calendar->explain(CarbonImmutable::parse('2026-01-03'), $code)->source)->toBe('calendar_base_rule');

    foreach (['2026-02-16', '2026-02-17', '2026-04-03', '2026-06-04', '2026-12-24', '2026-12-31'] as $nonNationalObservance) {
        expect($calendar->isBusinessDay(CarbonImmutable::parse($nonNationalObservance), $code))->toBeTrue()
            ->and(BusinessCalendarDate::query()
                ->where('calendar_code', $code)
                ->whereDate('calendar_date', $nonNationalObservance)
                ->exists())->toBeFalse();
    }
});

it('applies 20 November only after the national law entered into force', function () {
    $responsible = User::factory()->create();
    app(NationalLegalHolidayMaterializationService::class)->materialize(2023, 2024, $responsible->id);
    $calendar = app(BusinessCalendarService::class);
    $code = BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS;

    expect($calendar->isBusinessDay(CarbonImmutable::parse('2023-11-20'), $code))->toBeTrue()
        ->and(BusinessHoliday::query()->where('calendar_code', $code)->whereDate('holiday_date', '2023-11-20')->exists())->toBeFalse()
        ->and($calendar->isBusinessDay(CarbonImmutable::parse('2024-11-20'), $code))->toBeFalse()
        ->and(BusinessHoliday::query()->where('calendar_code', $code)->whereDate('holiday_date', '2024-11-20')->exists())->toBeTrue();

    $rule = BusinessCalendarLegalRule::query()->where('rule_key', 'black_consciousness_2023')->firstOrFail();

    expect($rule->effective_from?->toDateString())->toBe('2023-12-22')
        ->and($rule->norm_identification)->toBe('Lei nº 14.759/2023')
        ->and($rule->article_reference)->toBe('Art. 1º')
        ->and($rule->source_url)->toBe('https://www.planalto.gov.br/ccivil_03/_ato2023-2026/2023/lei/l14759.htm')
        ->and($rule->source_fingerprint)->toHaveLength(64);
});

it('derives the 2026 decision diff against modeled official ANBIMA exceptions', function () {
    $responsible = User::factory()->create();
    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2026, $responsible->id);
    $nationalDates = BusinessHoliday::query()
        ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->whereYear('holiday_date', 2026)
        ->pluck('holiday_date')
        ->map(fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString())
        ->all();
    $anbimaOnlyDates = ['2026-02-16', '2026-02-17', '2026-04-03', '2026-06-04'];

    foreach ([...$nationalDates, ...$anbimaOnlyDates] as $date) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'calendar_date' => $date,
            'is_business_day' => false,
            'description' => in_array($date, $anbimaOnlyDates, true) ? 'Exceção bancária ANBIMA' : 'Feriado também observado pela ANBIMA',
            'data_origin' => 'imported',
            'source' => 'anbima_official_fixture',
            'source_is_official' => true,
            'source_document' => 'https://www.anbima.com.br/feriados/',
        ]);
    }

    $diff = app(BusinessCalendarDiffService::class)->compare(
        BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
        BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );
    $decisionDifferences = collect($diff['divergences'])
        ->filter(fn (array $row): bool => in_array('business_day', $row['differences'], true))
        ->pluck('date')
        ->values()
        ->all();

    expect($decisionDifferences)->toBe($anbimaOnlyDates);
});

it('preserves legacy B3 ANBIMA and every financial consumer while materializing national rules', function () {
    $responsible = User::factory()->create();
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'calendar_date' => '2026-07-09',
        'is_business_day' => false,
        'description' => 'Controle legado',
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => '2026-07-09',
        'is_business_day' => false,
        'description' => 'Controle ANBIMA',
    ]);
    $before = [
        'legacy' => BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->get()->toArray(),
        'anbima' => BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)->get()->toArray(),
        'pu_parameters' => DB::table('emission_pu_parameters')->count(),
        'pu_events' => DB::table('emission_pu_events')->count(),
        'pu_curves' => DB::table('emission_pu_daily_curves')->count(),
        'obligations' => DB::table('obligations')->count(),
        'obligation_series' => DB::table('obligation_series')->count(),
    ];

    app(NationalLegalHolidayMaterializationService::class)->materialize(2026, 2031, $responsible->id);

    expect(BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::LEGACY_B3)->get()->toArray())->toBe($before['legacy'])
        ->and(BusinessCalendarDate::query()->where('calendar_code', BusinessCalendarRegistry::BR_BANKING_ANBIMA)->get()->toArray())->toBe($before['anbima'])
        ->and(DB::table('emission_pu_parameters')->count())->toBe($before['pu_parameters'])
        ->and(DB::table('emission_pu_events')->count())->toBe($before['pu_events'])
        ->and(DB::table('emission_pu_daily_curves')->count())->toBe($before['pu_curves'])
        ->and(DB::table('obligations')->count())->toBe($before['obligations'])
        ->and(DB::table('obligation_series')->count())->toBe($before['obligation_series'])
        ->and(BusinessCalendarYear::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
            ->where('status', BusinessCalendarYear::STATUS_CONFIRMED)
            ->count())->toBe(0);
});
