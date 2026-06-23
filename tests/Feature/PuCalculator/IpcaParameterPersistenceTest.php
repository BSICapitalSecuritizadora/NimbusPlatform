<?php

use App\Models\Emission;
use App\Models\EmissionPuParameter;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists and casts the preparatory IPCA parameters', function () {
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-02-02',
        'curve_end_date' => '2026-02-05',
        'initial_unit_value' => '1000.0000000000000000',
        'indexer' => 'IPCA',
        'calculation_method' => 'ipca_corrected',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_lag_months' => 2,
        'base_index_date' => '2021-09-01',
        'correction_frequency' => 'monthly',
        'index_projection_policy' => 'market',
        'legacy_projection_enabled' => false,
    ]);

    $parameter = EmissionPuParameter::query()
        ->where('emission_id', $emission->id)
        ->firstOrFail();

    expect($parameter->index_lag_months)->toBe(2)
        ->and($parameter->base_index_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($parameter->base_index_date->toDateString())->toBe('2021-09-01')
        ->and($parameter->correction_frequency)->toBe('monthly')
        ->and($parameter->index_projection_policy)->toBe('market');
});
