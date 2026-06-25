<?php

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuIpcaHomologationStatusService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\IndexProjectionSeries;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Cenário IPCA mínimo e completo: curva 2024-01-01..2024-01-03, aniversário dia 1, defasagem 2 meses.
 * Meses de referência exigidos: 2023-09, 2023-10, 2023-11.
 */
function makeIpcaOperationalEmission(string $policy): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    for ($date = CarbonImmutable::parse('2024-01-01'); $date->lte(CarbonImmutable::parse('2024-01-03')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    $emission->integralizationHistories()->create([
        'date' => '2024-01-01',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);

    $emission->puParameter()->create([
        'curve_start_date' => '2024-01-01',
        'curve_end_date' => '2024-01-03',
        'initial_unit_value' => '1000.0000000000000000',
        'annual_rate' => '5.00000000',
        'indexer' => PuIndexer::Ipca->value,
        'base_index_date' => '2024-01-01',
        'index_lag_months' => 2,
        'correction_frequency' => 'monthly',
        'index_projection_policy' => $policy,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

function seedPublishedIpca(string $month, string $value): void
{
    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => $month,
        'rate_value' => $value,
        'source' => 'manual_import',
        'is_projected' => false,
    ]);
}

it('blocks IPCA generation when a published index month is missing', function () {
    $emission = makeIpcaOperationalEmission(IpcaProjectionPolicy::PublishedOnly->value);
    seedPublishedIpca('2023-09-01', '6500.00000000');
    seedPublishedIpca('2023-10-01', '6550.00000000');
    // 2023-11 ausente de propósito.

    $result = app(PuCurvePrerequisiteService::class)->handle($emission);

    expect($result->passes())->toBeFalse()
        ->and($result->blockingSummary())->toContain('2023-11');
});

it('blocks IPCA generation when projected month belongs to an unapproved series', function () {
    $emission = makeIpcaOperationalEmission(IpcaProjectionPolicy::Market->value);
    seedPublishedIpca('2023-09-01', '6500.00000000');
    seedPublishedIpca('2023-10-01', '6550.00000000');

    $series = IndexProjectionSeries::factory()->create(['status' => IndexProjectionSeriesStatus::Imported->value]);
    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => '2023-11-01',
        'rate_value' => '6600.00000000',
        'is_projected' => true,
        'projection_source' => 'ANBIMA',
        'projection_policy' => 'market',
        'index_projection_series_id' => $series->id,
    ]);

    $result = app(PuCurvePrerequisiteService::class)->handle($emission);

    expect($result->passes())->toBeFalse()
        ->and($result->blockingSummary())->toContain('APROVADA');
});

it('blocks IPCA projection when the policy is published-only', function () {
    $emission = makeIpcaOperationalEmission(IpcaProjectionPolicy::PublishedOnly->value);
    seedPublishedIpca('2023-09-01', '6500.00000000');
    seedPublishedIpca('2023-10-01', '6550.00000000');

    $series = IndexProjectionSeries::factory()->approved()->create();
    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => '2023-11-01',
        'rate_value' => '6600.00000000',
        'is_projected' => true,
        'index_projection_series_id' => $series->id,
    ]);

    $result = app(PuCurvePrerequisiteService::class)->handle($emission);

    expect($result->passes())->toBeFalse()
        ->and($result->blockingSummary())->toContain('política');
});

it('generates an IPCA curve with an approved projected series and records the index source in memory', function () {
    $user = User::factory()->create();
    $emission = makeIpcaOperationalEmission(IpcaProjectionPolicy::Market->value);
    seedPublishedIpca('2023-09-01', '6500.00000000');
    seedPublishedIpca('2023-10-01', '6550.00000000');

    $series = IndexProjectionSeries::factory()->approved()->create(['projection_source' => 'ANBIMA']);
    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => '2023-11-01',
        'rate_value' => '6600.00000000',
        'is_projected' => true,
        'projection_source' => 'ANBIMA',
        'projection_policy' => 'market',
        'index_projection_series_id' => $series->id,
    ]);

    expect(app(PuCurvePrerequisiteService::class)->handle($emission)->passes())->toBeTrue();

    app()->call([new GeneratePuDailyCurveJob($emission->id, $user->id), 'handle']);

    $curves = $emission->fresh()->puDailyCurves()->orderBy('curve_date')->get();
    expect($curves)->toHaveCount(3);

    $projectedRow = $curves->first(fn ($row) => ($row->calculation_memory['index_is_projected'] ?? false) === true);
    expect($projectedRow)->not->toBeNull()
        ->and($projectedRow->calculation_memory['index_rate_type'])->toBe('projected')
        ->and($projectedRow->calculation_memory['index_projection_source'])->toBe('ANBIMA');

    $publishedRow = $curves->first(fn ($row) => ($row->calculation_memory['index_is_projected'] ?? null) === false);
    expect($publishedRow->calculation_memory['index_rate_type'])->toBe('published');
});

it('keeps the IPCA engine flag false but flips contextual homologation only after homologation with approved series', function () {
    $maker = User::factory()->create();
    $checker = User::factory()->create();
    $emission = makeIpcaOperationalEmission(IpcaProjectionPolicy::Market->value);
    seedPublishedIpca('2023-09-01', '6500.00000000');
    seedPublishedIpca('2023-10-01', '6550.00000000');

    $series = IndexProjectionSeries::factory()->approved()->create();
    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => '2023-11-01',
        'rate_value' => '6600.00000000',
        'is_projected' => true,
        'index_projection_series_id' => $series->id,
    ]);

    $status = app(PuIpcaHomologationStatusService::class);

    expect(PuIndexer::Ipca->isHomologated())->toBeFalse()
        ->and($status->isOperationallyHomologated($emission))->toBeFalse();

    app()->call([new GeneratePuDailyCurveJob($emission->id, $maker->id), 'handle']);

    expect($status->isOperationallyHomologated($emission->fresh()))->toBeFalse();

    app(\App\Actions\Emissions\HomologatePuCurve::class)->handle($emission->fresh(), null, $checker->id);

    expect(PuIndexer::Ipca->isHomologated())->toBeFalse()
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->homologated()->exists())->toBeTrue()
        ->and($status->isOperationallyHomologated($emission->fresh()))->toBeTrue();
});
