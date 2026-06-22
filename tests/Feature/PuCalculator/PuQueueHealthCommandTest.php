<?php

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports a healthy queue when there is nothing pending or stuck', function () {
    $this->artisan('pu:queue-health')
        ->expectsOutputToContain('Fila da calculadora de PU saudavel')
        ->assertExitCode(0);
});

it('flags versions stuck in processing', function () {
    $version = EmissionPuCurveVersion::factory()->create([
        'status' => PuCurveStatus::Processing->value,
        'calculation_version' => 'v1',
    ]);

    EmissionPuCurveVersion::query()
        ->whereKey($version->id)
        ->update(['updated_at' => now()->subHours(2)]);

    $this->artisan('pu:queue-health')
        ->expectsOutputToContain('Versoes em "processando"')
        ->assertExitCode(1);
});
