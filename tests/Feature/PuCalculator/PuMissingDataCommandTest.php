<?php

use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedEmissionWithPuParameter(): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    $emission->puParameter()->create([
        'curve_start_date' => '2026-02-02',
        'curve_end_date' => '2026-02-05',
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

it('fails when the CDI index is missing for the curve period', function () {
    $emission = seedEmissionWithPuParameter();
    seedBusinessCalendarForPuJob('2026-02-02', '2026-02-05');

    $this->artisan('pu:check-missing-data', ['emission' => $emission->id])
        ->expectsOutputToContain('Datas sem CDI obrigatorio')
        ->assertExitCode(1);
});

it('succeeds when calendar and CDI cover the whole period', function () {
    $emission = seedEmissionWithPuParameter();
    seedBusinessCalendarForPuJob('2026-02-02', '2026-02-05');
    seedFixedCdiRatesForPuJob('2026-02-02', '2026-02-05', '14.90000000');

    $this->artisan('pu:check-missing-data', ['emission' => $emission->id])
        ->assertExitCode(0);
});
