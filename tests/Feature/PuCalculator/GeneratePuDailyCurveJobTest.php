<?php

use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\IndexRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('generates the PU curve in the queued job and stores the completion payload in cache', function () {
    $user = User::factory()->create();
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    seedBusinessCalendarForPuJob('2026-02-02', '2026-02-05');
    seedFixedCdiRatesForPuJob('2026-02-02', '2026-02-05', '14.90000000');

    $emission->integralizationHistories()->create([
        'date' => '2026-02-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-02-02',
        'curve_end_date' => '2026-02-05',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => true,
    ]);

    $job = new GeneratePuDailyCurveJob($emission->id, $user->id);

    app()->call([$job, 'handle']);

    expect($emission->fresh()->puDailyCurves()->count())->toBe(4)
        ->and(Cache::get("pu_curve_generation_{$emission->id}_status"))->toMatchArray([
            'status' => 'completed',
            'calculation_version' => 'v1',
            'rows_count' => 4,
        ])
        ->and(Activity::query()
            ->where('log_name', 'pu-calculation')
            ->where('description', 'pu_curve_generated')
            ->where('subject_id', $emission->id)
            ->exists())->toBeTrue();
});

it('stores the failure payload in cache when the queued job fails', function () {
    $emission = Emission::factory()->create();
    $job = new GeneratePuDailyCurveJob($emission->id);

    $job->failed(new RuntimeException('missing parameters'));

    expect(Cache::get("pu_curve_generation_{$emission->id}_status"))->toMatchArray([
        'status' => 'failed',
        'error' => 'missing parameters',
    ]);
});

function seedBusinessCalendarForPuJob(string $startDate, string $endDate): void
{
    for ($date = \Carbon\CarbonImmutable::parse($startDate); $date->lte(\Carbon\CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }
}

function seedFixedCdiRatesForPuJob(string $startDate, string $endDate, string $rateValue): void
{
    for ($date = \Carbon\CarbonImmutable::parse($startDate); $date->lte(\Carbon\CarbonImmutable::parse($endDate)); $date = $date->addDay()) {
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
