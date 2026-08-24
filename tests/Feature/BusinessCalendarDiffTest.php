<?php

use App\Domain\PuCalculator\Services\BusinessCalendarDiffService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function compareCalendarDates(string $from = '2026-01-05', string $to = '2026-01-05'): array
{
    return app(BusinessCalendarDiffService::class)->compare(
        BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        BusinessCalendarRegistry::B3_LISTED_TRADING,
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
    );
}

it('reports identical calendars without listing equal dates', function () {
    $diff = compareCalendarDates('2026-01-05', '2026-01-09');

    expect($diff['identical_calendars'])->toBeTrue()
        ->and($diff['identical_dates'])->toBe(5)
        ->and($diff['divergences'])->toBe([]);
});

it('reports a holiday present only in A as non business only in A', function () {
    BusinessCalendarDate::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => '2026-01-05',
        'is_business_day' => false,
        'description' => 'Feriado somente A',
        'source' => 'official_a',
    ]);

    $diff = compareCalendarDates();

    expect($diff['business_day_only_b'])->toBe(1)
        ->and($diff['non_business_day_only_a'])->toBe(1)
        ->and($diff['divergences'][0]['a']['description'])->toBe('Feriado somente A');
});

it('reports a holiday present only in B as non business only in B', function () {
    BusinessCalendarDate::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
        'calendar_date' => '2026-01-05',
        'is_business_day' => false,
        'description' => 'Sem sessão somente B',
        'source' => 'official_b3',
    ]);

    $diff = compareCalendarDates();

    expect($diff['business_day_only_a'])->toBe(1)
        ->and($diff['non_business_day_only_b'])->toBe(1)
        ->and($diff['divergences'][0]['b']['description'])->toBe('Sem sessão somente B');
});

it('surfaces different revisions even when both calendars agree that the date is useful', function () {
    BusinessCalendarDate::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => '2026-01-05',
        'is_business_day' => true,
        'description' => 'Sessão normal',
        'source' => 'official',
        'revision' => 1,
    ]);
    BusinessCalendarDate::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
        'calendar_date' => '2026-01-05',
        'is_business_day' => true,
        'description' => 'Sessão normal',
        'source' => 'official',
        'revision' => 2,
    ]);

    $diff = compareCalendarDates();

    expect($diff['identical_dates'])->toBe(1)
        ->and($diff['metadata_differences'])->toBe(1)
        ->and($diff['divergences'][0]['category'])->toBe('metadata_difference')
        ->and($diff['divergences'][0]['differences'])->toContain('revision');
});

it('includes partial annual coverage for both sides', function () {
    BusinessCalendarDate::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => '2026-01-05',
        'is_business_day' => true,
    ]);

    $diff = compareCalendarDates();

    expect($diff['coverage']['a'][2026]['state'])->toBe('partial')
        ->and($diff['coverage']['b'][2026]['state'])->toBe('missing');
});
