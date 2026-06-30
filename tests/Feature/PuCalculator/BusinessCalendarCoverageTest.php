<?php

use App\Domain\PuCalculator\Services\BusinessCalendarCoverageService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuIndexCoverageService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverageService(): BusinessCalendarCoverageService
{
    return app(BusinessCalendarCoverageService::class);
}

function makeCdiEmissionForCoverage(string $start, string $end, string $calendarCode = 'B3'): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    $emission->integralizationHistories()->create([
        'date' => $start,
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => $start,
        'curve_end_date' => $end,
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => $calendarCode,
        'index_rate_lookup_mode' => 'previous_available_business_day',
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

function seedCdiRatesForCoverage(string $start, string $end, string $rateValue = '13.65000000'): void
{
    for ($date = CarbonImmutable::parse($start); $date->lte(CarbonImmutable::parse($end)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::query()->create([
            'indexer' => 'CDI',
            'rate_date' => $date->toDateString(),
            'rate_value' => $rateValue,
            'source' => 'testing',
            'source_reference' => 'fixed-rate',
        ]);
    }
}

it('backfills the calendar marking weekends as non business days', function () {
    $summary = coverageService()->backfill('B3', CarbonImmutable::parse('2021-09-23'), CarbonImmutable::parse('2021-09-30'));

    expect($summary['created'])->toBe(8)
        ->and($summary['business_days'])->toBe(6)
        ->and($summary['non_business_days'])->toBe(2)
        ->and(BusinessCalendarDate::query()->count())->toBe(8);

    $saturday = BusinessCalendarDate::query()->whereDate('calendar_date', '2021-09-25')->firstOrFail();
    $monday = BusinessCalendarDate::query()->whereDate('calendar_date', '2021-09-27')->firstOrFail();

    expect($saturday->is_business_day)->toBeFalse()
        ->and($monday->is_business_day)->toBeTrue();
});

it('is idempotent and never overwrites manually registered holidays', function () {
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'B3',
        'calendar_date' => '2021-09-07',
        'is_business_day' => false,
        'description' => 'Independência (feriado B3)',
    ]);

    $first = coverageService()->backfill('B3', CarbonImmutable::parse('2021-09-01'), CarbonImmutable::parse('2021-09-30'));
    $second = coverageService()->backfill('B3', CarbonImmutable::parse('2021-09-01'), CarbonImmutable::parse('2021-09-30'));

    expect($first['created'])->toBe(29)
        ->and($second['created'])->toBe(0)
        ->and(BusinessCalendarDate::query()->count())->toBe(30);

    $holiday = BusinessCalendarDate::query()->whereDate('calendar_date', '2021-09-07')->firstOrFail();

    expect($holiday->is_business_day)->toBeFalse()
        ->and($holiday->description)->toBe('Independência (feriado B3)');
});

it('does not persist anything on a dry run', function () {
    $summary = coverageService()->backfill('B3', CarbonImmutable::parse('2021-09-23'), CarbonImmutable::parse('2021-09-30'), dryRun: true);

    expect($summary['created'])->toBe(0)
        ->and($summary['would_create'])->toBe(8)
        ->and(BusinessCalendarDate::query()->count())->toBe(0);
});

it('reports missing dates and honours the auto completable config', function () {
    expect(coverageService()->isAutoCompletable('B3'))->toBeTrue()
        ->and(coverageService()->isAutoCompletable('NYSE'))->toBeFalse()
        ->and(coverageService()->missingDates('B3', CarbonImmutable::parse('2021-09-23'), CarbonImmutable::parse('2021-09-25')))
        ->toBe(['2021-09-23', '2021-09-24', '2021-09-25']);

    config()->set('pu_calculator.business_calendar.auto_complete', false);

    expect(coverageService()->willAutoComplete('B3'))->toBeFalse();
});

it('seeds the calendar through the artisan command', function () {
    $this->artisan('pu:business-calendar:seed', ['--calendar' => 'B3', '--from' => '2021-09-23', '--to' => '2025-08-23'])
        ->expectsOutputToContain('Criadas')
        ->assertExitCode(0);

    expect(BusinessCalendarDate::query()->where('calendar_code', 'B3')->count())->toBe(1431);
});

it('fails the command when from or to is missing', function () {
    $this->artisan('pu:business-calendar:seed', ['--calendar' => 'B3', '--from' => '2021-09-23'])
        ->assertExitCode(1);
});

it('does not block generation for an empty B3 calendar and auto completes it', function () {
    $emission = makeCdiEmissionForCoverage('2021-09-23', '2021-09-30');
    seedCdiRatesForCoverage('2021-09-22', '2021-09-30');

    expect(BusinessCalendarDate::query()->count())->toBe(0);

    $result = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    $blockingKeys = array_map(fn ($issue): string => $issue->key, $result->blockingIssues());

    expect($blockingKeys)->not->toContain('business_calendar_dates')
        ->and(BusinessCalendarDate::query()->where('calendar_code', 'B3')->count())->toBe(8);
});

it('still blocks generation for a calendar that cannot be auto completed', function () {
    $emission = makeCdiEmissionForCoverage('2021-09-23', '2021-09-30', calendarCode: 'NYSE');

    $result = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    $blockingKeys = array_map(fn ($issue): string => $issue->key, $result->blockingIssues());

    expect($blockingKeys)->toContain('business_calendar_dates')
        ->and(BusinessCalendarDate::query()->count())->toBe(0);
});

it('treats an auto completable calendar as covered in the index coverage report', function () {
    $emission = makeCdiEmissionForCoverage('2021-09-23', '2021-09-30');

    $report = app(PuIndexCoverageService::class)->report($emission->fresh());

    expect($report->missingCalendarDates)->toBe([]);
});
