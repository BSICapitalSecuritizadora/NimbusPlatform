<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Services\ConstructionProgressProvider;
use App\Services\MeasurementEngineeringService;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function progressProvider(): ConstructionProgressProvider
{
    return app(ConstructionProgressProvider::class);
}

/**
 * Medição com a Engenharia vigente para a linha, com o trecho do snapshot que
 * a aprovação grava para o plano.
 */
function approvedProgressMeasurement(MeasurementPlanLine $line, string $percent, string $engineeringStatus = 'approved'): Measurement
{
    $measurement = Measurement::factory()->create([
        'operation_id' => $line->operation_id,
        'reference_month' => $line->measurement_date->toDateString(),
        'status' => 'in_review',
        'current_stage' => 2,
        'engineering_snapshot' => [
            'schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
            'plan_sets' => [[
                'plan_set_id' => $line->plan_set_id,
                'plan_line_id' => $line->id,
                'sequence_number' => $line->sequence_number,
                'measurement_date' => $line->measurement_date->toDateString(),
                'realized_monthly_percent' => $percent,
            ]],
        ],
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => $engineeringStatus]);

    return $measurement;
}

it('returns the evolution data for the reference month of an emission', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('33.00', '2026-03-31')->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 1,
        'planned_monthly_percent' => 10,
        'planned_cumulative_percent' => 40,
        'measurement_date' => '2026-04-15',
    ]);
    approvedProgressMeasurement($line, '12.00');

    $progress = progressProvider()->forEmission($emission, Carbon::parse('2026-04-01'));

    expect($progress)->not->toBeNull()
        ->and($progress->plannedCumulativePercent)->toBe(40.0)
        ->and($progress->realizedMonthlyPercent)->toBe(12.0)
        ->and($progress->realizedCumulativePercent)->toBe(45.0)
        ->and($progress->diffPercent)->toBe(5.0)
        ->and($progress->trend)->toBe(MeasurementPlanLine::TREND_AHEAD)
        ->and($progress->measuredInMonth)->toBeTrue()
        ->and($progress->measurementDate?->toDateString())->toBe('2026-04-15');
});

it('falls back to the latest line on or before the reference month', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 1,
        'measurement_date' => '2026-02-10',
    ]);
    approvedProgressMeasurement($line, '30.00');

    $progress = progressProvider()->forEmission($emission, Carbon::parse('2026-05-01'));

    expect($progress)->not->toBeNull()
        ->and($progress->realizedCumulativePercent)->toBe(30.0)
        ->and($progress->realizedMonthlyPercent)->toBe(0.0)
        ->and($progress->measuredInMonth)->toBeFalse()
        ->and($progress->measurementDate)->toBeNull();
});

it('narrows progress to a specific construction when provided', function () {
    $emission = Emission::factory()->create();
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id]);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id]);

    $operationA = Operation::factory()->forEmission($emission)->create(['construction_id' => $constructionA->id]);
    $planA = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operationA->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planA->id,
        'operation_id' => $operationA->id,
        'measurement_date' => '2026-04-10',
    ]);
    approvedProgressMeasurement($line, '70.00');

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

    approvedProgressMeasurement(MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planA->id,
        'operation_id' => $operation->id,
        'measurement_date' => '2026-04-12',
    ]), '35.00');
    approvedProgressMeasurement(MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planB->id,
        'operation_id' => $operation->id,
        'measurement_date' => '2026-04-12',
    ]), '80.00');

    $provider = progressProvider();
    $month = Carbon::parse('2026-04-01');

    expect($provider->forEmission($emission, $month, $constructionA)?->realizedCumulativePercent)->toBe(35.0)
        ->and($provider->forEmission($emission, $month, $constructionB)?->realizedCumulativePercent)->toBe(80.0);
});

it('returns null when there is no progress data', function () {
    $emission = Emission::factory()->create();

    expect(progressProvider()->forEmission($emission, Carbon::parse('2026-04-01')))->toBeNull();
});

it('carries the last known cumulative into a planned month without measurement', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $may = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 1,
        'measurement_date' => '2026-05-01', 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 10,
    ]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 2,
        'measurement_date' => '2026-06-01', 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 20,
        'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
    ]);
    approvedProgressMeasurement($may, '10.00');

    $june = progressProvider()->forEmission($emission, Carbon::parse('2026-06-01'));

    expect($june->plannedCumulativePercent)->toBe(20.0)
        ->and($june->realizedCumulativePercent)->toBe(10.0)
        ->and($june->realizedMonthlyPercent)->toBe(0.0)
        ->and($june->measuredInMonth)->toBeFalse()
        ->and($june->diffPercent)->toBe(-10.0)
        ->and($june->trend)->toBe(MeasurementPlanLine::TREND_BEHIND)
        ->and($june->measurementDate)->toBeNull();
});

it('counts a month approved at 0% as measured', function (array $approvals, float $realizedCumulative, float $diff) {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $lines = [];

    foreach (['2026-04-01', '2026-05-01'] as $sequence => $month) {
        $lines[$month] = MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => $sequence + 1,
            'measurement_date' => $month, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 10 * ($sequence + 1),
            'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
        ]);
    }

    foreach ($approvals as $month => $percent) {
        approvedProgressMeasurement($lines[$month], $percent);
    }

    $may = progressProvider()->forEmission($emission, Carbon::parse('2026-05-01'));

    expect($may)->not->toBeNull()
        ->and($may->measuredInMonth)->toBeTrue()
        ->and($may->realizedMonthlyPercent)->toBe(0.0)
        ->and($may->realizedCumulativePercent)->toBe($realizedCumulative)
        ->and($may->diffPercent)->toBe($diff)
        ->and($may->trend)->toBe(MeasurementPlanLine::TREND_BEHIND)
        ->and($may->measurementDate?->toDateString())->toBe('2026-05-01');
})->with([
    'after earlier progress' => [['2026-04-01' => '10.00', '2026-05-01' => '0.00'], 10.0, -10.0],
    'as the first measurement of the construction' => [['2026-05-01' => '0.00'], 0.0, -20.0],
]);

it('ignores a measurement whose Engineering approval no longer stands', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $april = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 1,
        'measurement_date' => '2026-04-01', 'planned_cumulative_percent' => 5,
    ]);
    $may = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 2,
        'measurement_date' => '2026-05-01', 'planned_cumulative_percent' => 10,
    ]);
    approvedProgressMeasurement($april, '5.00');
    $returned = approvedProgressMeasurement($may, '10.00', engineeringStatus: 'pending');
    DB::table('measurement_plan_lines')->where('id', $may->id)->update([
        'measurement_id' => $returned->id,
        'realized_monthly_percent' => 10,
        'realized_cumulative_percent' => 15,
    ]);

    $progress = progressProvider()->forEmission($emission, Carbon::parse('2026-05-01'));

    expect($progress->realizedCumulativePercent)->toBe(5.0)
        ->and($progress->measuredInMonth)->toBeFalse();
});

it('publishes no realized progress before anything is known about the construction', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 1,
        'measurement_date' => '2026-05-01', 'planned_cumulative_percent' => 10,
        'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
    ]);

    expect(progressProvider()->forEmission($emission, Carbon::parse('2026-05-01')))->toBeNull();
});

it('counts the initial physical progress only from its reference date', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('25.00', '2026-06-15')->create(['operation_id' => $operation->id]);

    foreach (['2026-05-01', '2026-06-01'] as $sequence => $month) {
        MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => $sequence + 1,
            'measurement_date' => $month, 'planned_cumulative_percent' => 10 * ($sequence + 1),
        ]);
    }

    expect(progressProvider()->forEmission($emission, Carbon::parse('2026-05-01')))->toBeNull()
        ->and(progressProvider()->forEmission($emission, Carbon::parse('2026-06-01'))->realizedCumulativePercent)->toBe(25.0);
});

it('publishes the initial physical progress copied without a reference date in a month without measurement', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 1,
        'measurement_date' => '2026-05-01', 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 10,
        'initial_realized_cumulative_percent' => 35, 'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
    ]);
    // O plano como a migration de backfill o deixa: o "Realiz. inicial" da
    // primeira linha copiado como percentual, sem data de referência -- estado
    // que a criação do plano recusa.
    DB::table('measurement_plan_sets')->where('id', $planSet->id)->update([
        'initial_physical_progress_percent' => '35.00',
        'initial_physical_progress_reference_date' => null,
    ]);

    $may = progressProvider()->forEmission($emission, Carbon::parse('2026-05-01'));

    expect($may)->not->toBeNull()
        ->and($may->realizedCumulativePercent)->toBe(35.0)
        ->and($may->realizedMonthlyPercent)->toBe(0.0)
        ->and($may->measuredInMonth)->toBeFalse()
        ->and($may->measurementDate)->toBeNull()
        ->and($may->diffPercent)->toBe(25.0)
        ->and($may->trend)->toBe(MeasurementPlanLine::TREND_AHEAD);
});

it('finds the schedule line dated on the last day of the month', function () {
    $emission = Emission::factory()->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 1,
        'measurement_date' => '2026-08-01', 'planned_cumulative_percent' => 40,
    ]);
    $lastDay = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => 2,
        'measurement_date' => '2026-09-30', 'planned_cumulative_percent' => 50,
    ]);
    approvedProgressMeasurement($lastDay, '45.00');

    $september = progressProvider()->forEmission($emission, Carbon::parse('2026-09-01'));

    expect($september->plannedCumulativePercent)->toBe(50.0)
        ->and($september->realizedMonthlyPercent)->toBe(45.0)
        ->and($september->measuredInMonth)->toBeTrue();
});

it('publishes a month without measurement as not measured, without a false drop in the history', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Residencial Aurora']);
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id, 'construction_id' => $construction->id]);
    $lines = [];

    foreach (['2026-04-01', '2026-05-01', '2026-06-01'] as $sequence => $month) {
        $lines[$month] = MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => $sequence + 1,
            'measurement_date' => $month, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 10 * ($sequence + 1),
            'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
        ]);
    }

    approvedProgressMeasurement($lines['2026-04-01'], '10.00');
    approvedProgressMeasurement($lines['2026-05-01'], '12.00');

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-06-01'));
    $row = $data['construction']['progress'][0];

    expect($row['realized_cumulative'])->toBe('22,00%')
        ->and($row['realized_monthly'])->toBe('—')
        ->and($row['planned_cumulative'])->toBe('30,00%')
        ->and($row['diff'])->toBe('—')
        ->and($row['trend'])->toBe('—')
        ->and(collect($data['construction_history']['series'][0]['points'])->pluck('competencia')->all())->toBe(['04/2026', '05/2026'])
        ->and(collect($data['construction_history']['series'][0]['points'])->pluck('realized_cumulative')->all())->toBe(['10,00%', '22,00%']);
});

it('publishes a month approved at 0% as measured, with a flat cumulative in the history', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->for($emission)->create();
    $operation = Operation::factory()->forEmission($emission)->create();
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id, 'construction_id' => $construction->id]);
    $lines = [];

    foreach (['2026-04-01', '2026-05-01'] as $sequence => $month) {
        $lines[$month] = MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id, 'operation_id' => $operation->id, 'sequence_number' => $sequence + 1,
            'measurement_date' => $month, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 10 * ($sequence + 1),
            'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
        ]);
    }

    approvedProgressMeasurement($lines['2026-04-01'], '10.00');
    approvedProgressMeasurement($lines['2026-05-01'], '0.00');

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-05-01'));
    $row = $data['construction']['progress'][0];

    expect($row['realized_monthly'])->toBe('0,00%')
        ->and($row['realized_cumulative'])->toBe('10,00%')
        ->and($row['planned_cumulative'])->toBe('20,00%')
        ->and($row['diff'])->toBe('-10,00%')
        ->and($row['trend'])->toBe(MeasurementPlanLine::TREND_BEHIND)
        ->and($row['measurement_date'])->toBe('01/05/2026')
        ->and(collect($data['construction_history']['series'][0]['points'])->pluck('competencia')->all())->toBe(['04/2026', '05/2026'])
        ->and(collect($data['construction_history']['series'][0]['points'])->pluck('realized_cumulative')->all())->toBe(['10,00%', '10,00%']);
});
