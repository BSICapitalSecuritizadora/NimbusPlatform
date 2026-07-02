<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeCdiEmissionWithParameter(string $curveEnd = '2031-02-10'): Emission
{
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => $curveEnd,
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -5,
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

it('queues realized regeneration for active CDI emissions with a future tail', function () {
    Queue::fake();

    $emission = makeCdiEmissionWithParameter();

    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    Queue::assertPushed(
        GeneratePuDailyCurveJob::class,
        fn (GeneratePuDailyCurveJob $job): bool => $job->emissionId === $emission->id && $job->confirmedReprocess === false,
    );
});

it('preserves homologated curves by not reprocessing them automatically', function () {
    Queue::fake();

    $emission = makeCdiEmissionWithParameter();

    EmissionPuCurveVersion::factory()->homologated()->create([
        'emission_id' => $emission->id,
    ]);

    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);
});

it('skips emissions whose curve already reaches the final date', function () {
    Queue::fake();

    $emission = makeCdiEmissionWithParameter(curveEnd: '2026-03-10');

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_date' => '2026-03-10',
    ]);

    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);
});
