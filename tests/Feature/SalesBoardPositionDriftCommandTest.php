<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports the competences whose panel number changes under the consolidated reading', function () {
    $emission = Emission::factory()->create();
    $updated = Construction::factory()->create(['emission_id' => $emission->id]);
    $stale = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $stale)->create([
        'reference_month' => '2026-05-01',
        'stock_units' => 100,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $updated)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Competências com número diferente: 1')
        ->expectsOutputToContain('Diferença total de unidades: +100')
        ->assertSuccessful();
});

it('does not create or change any sales board while diagnosing', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-05-01',
    ]);

    $before = SalesBoard::count();
    $updatedAt = $salesBoard->updated_at;

    $this->artisan('sales-boards:position-drift', ['--all' => true])->assertSuccessful();

    expect(SalesBoard::count())->toBe($before)
        ->and($salesBoard->fresh()->updated_at->toDateTimeString())->toBe($updatedAt->toDateTimeString());
});

it('reports no divergence for a single-construction emission', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-05-01',
    ]);

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Competências com número diferente: 0')
        ->assertSuccessful();
});
