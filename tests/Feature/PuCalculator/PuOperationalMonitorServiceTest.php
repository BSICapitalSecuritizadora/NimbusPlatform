<?php

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function emissionWithPuParameter(string $start = '2026-02-02', string $end = '2026-02-05'): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    $emission->puParameter()->create([
        'curve_start_date' => $start,
        'curve_end_date' => $end,
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => 'previous_available_business_day',
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

it('counts emissions by the status of their latest curve version', function () {
    $homologated = emissionWithPuParameter();
    EmissionPuCurveVersion::factory()->homologated()->create(['emission_id' => $homologated->id, 'calculation_version' => 'v1']);

    $errored = emissionWithPuParameter();
    EmissionPuCurveVersion::factory()->create(['emission_id' => $errored->id, 'calculation_version' => 'v1', 'status' => PuCurveStatus::Error->value]);

    emissionWithPuParameter(); // sem versão

    $counts = app(PuOperationalMonitorService::class)->statusCounts();

    expect($counts['total'])->toBe(3)
        ->and($counts['homologated'])->toBe(1)
        ->and($counts['error'])->toBe(1)
        ->and($counts['sem_curva'])->toBe(1);
});

it('uses the latest version even when it is obsolete', function () {
    $emission = emissionWithPuParameter();
    EmissionPuCurveVersion::factory()->obsolete()->create(['emission_id' => $emission->id, 'calculation_version' => 'v1']);
    EmissionPuCurveVersion::factory()->homologated()->create(['emission_id' => $emission->id, 'calculation_version' => 'v2']);

    expect($emission->fresh()->latestPuCurveVersion->calculation_version)->toBe('v2');
});

it('flags emissions with missing mandatory CDI', function () {
    emissionWithPuParameter(); // sem calendário/CDI => cobertura bloqueada

    $monitor = app(PuOperationalMonitorService::class);

    expect($monitor->missingCdiCount())->toBeGreaterThan(0)
        ->and($monitor->criticalSummary())->not()->toBeEmpty();
});

it('reports queue metrics using the database driver', function () {
    $emission = emissionWithPuParameter();
    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'status' => PuCurveStatus::Processing->value,
    ]);
    EmissionPuCurveVersion::query()->whereKey($version->id)->update(['updated_at' => now()->subHours(2)]);

    $metrics = app(PuOperationalMonitorService::class)->queueMetrics();

    expect($metrics)->toHaveKeys(['pending_jobs', 'failed_pu_jobs', 'failed_jobs_total', 'stuck_versions'])
        ->and($metrics['stuck_versions'])->toBe(1);
});
