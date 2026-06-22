<?php

use App\Domain\PuCalculator\Calculators\CdiSpreadCurveCalculator;
use App\Domain\PuCalculator\Calculators\FixedRateCurveCalculator;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\IndexerNotSupportedException;
use App\Domain\PuCalculator\Factories\PuCalculatorFactory;
use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function emissionWithIndexer(string $indexer): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-02-02',
        'curve_end_date' => '2026-02-05',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => $indexer === 'CDI' ? '6.50000000' : null,
        'annual_rate' => $indexer === 'PREFIXED' ? '10.00000000' : null,
        'indexer' => $indexer,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    return $emission->load('puParameter');
}

it('resolves the CDI calculator for CDI emissions', function () {
    $calculator = app(PuCalculatorFactory::class)->for(emissionWithIndexer('CDI'));

    expect($calculator)->toBeInstanceOf(CdiSpreadCurveCalculator::class);
});

it('resolves the fixed-rate calculator for prefixed emissions', function () {
    $calculator = app(PuCalculatorFactory::class)->for(emissionWithIndexer('PREFIXED'));

    expect($calculator)->toBeInstanceOf(FixedRateCurveCalculator::class);
});

it('blocks IPCA generation as experimental', function () {
    $emission = emissionWithIndexer('IPCA');
    $calculator = app(PuCalculatorFactory::class)->forIndexer(PuIndexer::Ipca);

    expect(fn () => $calculator->calculate($emission))
        ->toThrow(IndexerNotSupportedException::class);
});
