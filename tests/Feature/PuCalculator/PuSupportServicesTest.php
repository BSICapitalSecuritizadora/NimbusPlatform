<?php

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\RoundingService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('calculates the daily DI factor with base 252', function () {
    $factor = app(DailyFactorCalculator::class)->factorDiForDay('14.90000000', true, 252);

    expect($factor)->toBe('1.0005513106415403');
});

it('calculates the spread factor for accumulated business days', function () {
    $factor = app(DailyFactorCalculator::class)->factorSpreadForBusinessDays('6.50000000', 5, 252);

    expect($factor)->toBe('1.0012502809336970');
});

it('rounds decimal values with half up precision', function () {
    $rounder = app(RoundingService::class);

    expect($rounder->round('1.23455', 4))->toBe('1.2346')
        ->and($rounder->round('-1.23455', 4))->toBe('-1.2346');
});

it('truncates decimal values without rounding', function () {
    $rounder = app(RoundingService::class);

    expect($rounder->truncate('1.23459', 4))->toBe('1.2345')
        ->and($rounder->truncate('-1.23459', 4))->toBe('-1.2345');
});

it('uses the configured business calendar and resolves the next business day', function () {
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'B3',
        'calendar_date' => '2026-01-02',
        'is_business_day' => true,
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'B3',
        'calendar_date' => '2026-01-03',
        'is_business_day' => false,
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'B3',
        'calendar_date' => '2026-01-04',
        'is_business_day' => false,
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'B3',
        'calendar_date' => '2026-01-05',
        'is_business_day' => true,
    ]);

    $calendar = app(BusinessDayCalendarService::class);

    expect($calendar->isBusinessDay(CarbonImmutable::parse('2026-01-03'), 'B3'))->toBeFalse()
        ->and($calendar->nextBusinessDay(CarbonImmutable::parse('2026-01-03'), 'B3')->toDateString())->toBe('2026-01-05')
        ->and($calendar->shiftBusinessDays(CarbonImmutable::parse('2026-01-05'), -1, 'B3')->toDateString())->toBe('2026-01-02');
});

it('supports exact and latest-available CDI lookups', function () {
    IndexRate::query()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => '2026-01-02',
        'rate_value' => '13.65000000',
        'source' => 'testing',
    ]);
    IndexRate::query()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => '2026-01-05',
        'rate_value' => '13.75000000',
        'source' => 'testing',
    ]);

    $indexRateService = app(IndexRateService::class);
    $weekendSnapshot = $indexRateService->rateForDate(PuIndexer::Cdi, CarbonImmutable::parse('2026-01-04'));
    $nextDaySnapshot = $indexRateService->rateForDate(PuIndexer::Cdi, CarbonImmutable::parse('2026-01-06'));
    $exactMissingSnapshot = $indexRateService->exactRateForDate(PuIndexer::Cdi, CarbonImmutable::parse('2026-01-04'));
    $exactBusinessSnapshot = $indexRateService->exactRateForDate(PuIndexer::Cdi, CarbonImmutable::parse('2026-01-05'));

    expect($weekendSnapshot?->date->toDateString())->toBe('2026-01-02')
        ->and($weekendSnapshot?->value)->toBe('13.65000000')
        ->and($nextDaySnapshot?->date->toDateString())->toBe('2026-01-05')
        ->and($nextDaySnapshot?->value)->toBe('13.75000000')
        ->and($exactMissingSnapshot)->toBeNull()
        ->and($exactBusinessSnapshot?->date->toDateString())->toBe('2026-01-05');
});

it('flags projected forward CDI rows so they can be hidden in validation-facing output', function () {
    $rate = IndexRate::query()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => '2026-06-15',
        'rate_value' => '14.40000000',
        'source' => 'reference_workbook',
        'source_reference' => 'forward_projection',
    ]);

    $snapshot = app(IndexRateService::class)->exactRateForDate(PuIndexer::Cdi, CarbonImmutable::parse('2026-06-15'));

    expect($rate->source_reference)->toBe('forward_projection')
        ->and($snapshot?->isProjected)->toBeTrue()
        ->and($snapshot?->reportedDate())->toBeNull()
        ->and($snapshot?->reportedValue())->toBeNull();
});

it('stores lookup mode defaults on PU parameters', function () {
    $parameter = Emission::factory()->create()
        ->puParameter()
        ->create([
            'curve_start_date' => '2026-01-01',
            'curve_end_date' => '2026-01-31',
            'initial_unit_value' => '1000.0000000000000000',
            'spread_rate' => '6.50000000',
            'indexer' => PuIndexer::Cdi->value,
            'business_day_basis' => 252,
            'calendar_code' => 'B3',
            'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
            'index_rate_lag_business_days' => -5,
            'legacy_projection_enabled' => false,
        ]);

    expect($parameter->index_rate_lookup_mode_enum)->toBe(PuIndexRateLookupMode::BusinessDayLagExact)
        ->and($parameter->index_rate_lag_business_days)->toBe(-5);
});

it('validates long prerequisite ranges without per-day database queries', function () {
    $calendar = app(BusinessDayCalendarService::class);
    $calendar->flushCache();
    app(IndexRateService::class)->flushCache();

    $emission = Emission::factory()->create([
        'issued_quantity' => 1000,
    ]);

    for ($date = CarbonImmutable::parse('2026-01-01'); $date->lte(CarbonImmutable::parse('2026-12-31')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
        ]);

        if (! $date->isWeekend()) {
            IndexRate::query()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->toDateString(),
                'rate_value' => '13.65000000',
                'source' => 'testing',
            ]);
        }
    }

    $emission->integralizationHistories()->create([
        'date' => '2026-01-01',
        'quantity' => '1000.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '1000000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    $selectQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_starts_with(strtolower((string) $query['query']), 'select'))
        ->count();

    DB::disableQueryLog();

    expect($result->passes())->toBeTrue()
        ->and($selectQueries)->toBeLessThan(20);
});
