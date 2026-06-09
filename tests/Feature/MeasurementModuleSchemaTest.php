<?php

use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an operation linked to an emission and auto-generates a code', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();

    expect($operation->emission_id)->toBe($emission->id)
        ->and($operation->code)->toStartWith('OP-')
        ->and($operation->code)->toContain((string) $operation->id);
});

it('exposes the full measurement plan structure as relationships', function () {
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->default()->create([
        'operation_id' => $operation->id,
    ]);
    MeasurementPlanLine::factory()->count(3)->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
    ]);

    expect($operation->planSets)->toHaveCount(1)
        ->and($operation->defaultPlanSet()?->is($planSet))->toBeTrue()
        ->and($planSet->lines)->toHaveCount(3)
        ->and($operation->planLines)->toHaveCount(3);
});

it('computes the evolution diff and trend when saving a plan line', function () {
    $planSet = MeasurementPlanSet::factory()->create();

    $ahead = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $planSet->operation_id,
        'planned_cumulative_percent' => 40,
        'realized_cumulative_percent' => 55,
    ]);
    $behind = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $planSet->operation_id,
        'planned_cumulative_percent' => 60,
        'realized_cumulative_percent' => 50,
    ]);
    $onTrack = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $planSet->operation_id,
        'planned_cumulative_percent' => 30,
        'realized_cumulative_percent' => 30,
    ]);

    expect($ahead->evolution_diff_percent)->toBe('15.00')
        ->and($ahead->evolution_trend)->toBe(MeasurementPlanLine::TREND_AHEAD)
        ->and($behind->evolution_diff_percent)->toBe('-10.00')
        ->and($behind->evolution_trend)->toBe(MeasurementPlanLine::TREND_BEHIND)
        ->and($onTrack->evolution_trend)->toBe(MeasurementPlanLine::TREND_ON_TRACK);
});

it('links a measurement through its reviews, payments and assets', function () {
    $operation = Operation::factory()->create();
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
    ]);
    $reviewer = User::factory()->create();
    MeasurementReview::factory()->approved()->create([
        'measurement_id' => $measurement->id,
        'stage' => 1,
        'reviewer_user_id' => $reviewer->id,
    ]);
    MeasurementPayment::factory()->create([
        'operation_id' => $operation->id,
        'measurement_id' => $measurement->id,
    ]);

    expect($measurement->operation->is($operation))->toBeTrue()
        ->and($measurement->reviewForStage(1)?->status)->toBe('approved')
        ->and($measurement->payments)->toHaveCount(1)
        ->and($operation->measurements)->toHaveCount(1);
});

it('cascades deletes from an operation to its measurements and plan', function () {
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
    ]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id]);

    $operation->delete();

    expect(MeasurementPlanSet::query()->count())->toBe(0)
        ->and(MeasurementPlanLine::query()->count())->toBe(0)
        ->and(Measurement::query()->count())->toBe(0);
});
