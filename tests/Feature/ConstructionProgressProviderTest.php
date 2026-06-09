<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Services\ConstructionProgressProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function progressProvider(): ConstructionProgressProvider
{
    return app(ConstructionProgressProvider::class);
}

it('returns the evolution data for the reference month of an emission', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);

    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 1,
        'planned_monthly_percent' => 10,
        'planned_cumulative_percent' => 40,
        'realized_monthly_percent' => 12,
        'realized_cumulative_percent' => 45,
        'measurement_date' => '2026-04-15',
    ]);

    $progress = progressProvider()->forEmission($emission, Carbon::parse('2026-04-01'));

    expect($progress)->not->toBeNull()
        ->and($progress->plannedCumulativePercent)->toBe(40.0)
        ->and($progress->realizedCumulativePercent)->toBe(45.0)
        ->and($progress->diffPercent)->toBe(5.0)
        ->and($progress->trend)->toBe(MeasurementPlanLine::TREND_AHEAD);
});

it('falls back to the latest line on or before the reference month', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);

    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 1,
        'realized_cumulative_percent' => 30,
        'measurement_date' => '2026-02-10',
    ]);

    $progress = progressProvider()->forEmission($emission, Carbon::parse('2026-05-01'));

    expect($progress)->not->toBeNull()
        ->and($progress->realizedCumulativePercent)->toBe(30.0);
});

it('narrows progress to a specific construction when provided', function () {
    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id]);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id]);

    $operationA = Operation::factory()->forEmission($emission)->create(['construction_id' => $constructionA->id]);
    $planA = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operationA->id]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planA->id,
        'operation_id' => $operationA->id,
        'realized_cumulative_percent' => 70,
        'measurement_date' => '2026-04-10',
    ]);

    $progress = progressProvider()->forEmission($emission, Carbon::parse('2026-04-01'), $constructionB);

    expect($progress)->toBeNull();
});

it('resolves progress per development when one operation covers multiple empreendimentos', function () {
    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id]);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id]);

    $operation = Operation::factory()->forEmission($emission)->create();

    $planA = MeasurementPlanSet::factory()->default()->create([
        'operation_id' => $operation->id,
        'construction_id' => $constructionA->id,
    ]);
    $planB = MeasurementPlanSet::factory()->create([
        'operation_id' => $operation->id,
        'construction_id' => $constructionB->id,
        'is_default' => true,
        'name' => 'Plano B',
    ]);

    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planA->id,
        'operation_id' => $operation->id,
        'realized_cumulative_percent' => 35,
        'measurement_date' => '2026-04-12',
    ]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planB->id,
        'operation_id' => $operation->id,
        'realized_cumulative_percent' => 80,
        'measurement_date' => '2026-04-12',
    ]);

    $provider = progressProvider();
    $month = Carbon::parse('2026-04-01');

    expect($provider->forEmission($emission, $month, $constructionA)?->realizedCumulativePercent)->toBe(35.0)
        ->and($provider->forEmission($emission, $month, $constructionB)?->realizedCumulativePercent)->toBe(80.0);
});

it('returns null when there is no progress data', function () {
    $emission = Emission::factory()->create();

    expect(progressProvider()->forEmission($emission, Carbon::parse('2026-04-01')))->toBeNull();
});
