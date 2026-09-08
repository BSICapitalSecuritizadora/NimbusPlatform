<?php

use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Services\OperationNextMeasurementResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function makeNextMeasurementLine(
    MeasurementPlanSet $plan,
    int $sequence,
    ?string $date,
    array $attributes = [],
): MeasurementPlanLine {
    return MeasurementPlanLine::factory()->create([
        'operation_id' => $plan->operation_id,
        'plan_set_id' => $plan->id,
        'sequence_number' => $sequence,
        'measurement_date' => $date,
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
        ...$attributes,
    ]);
}

function resolveNextMeasurementMonth(Operation $operation): ?string
{
    return app(OperationNextMeasurementResolver::class)
        ->addNextMeasurementDate(Operation::query())
        ->findOrFail($operation->id)
        ->next_pending_measurement_at?->format('m/Y');
}

it('suggests May after finalized April even when the pending competence is overdue', function () {
    $this->travelTo(now()->setDate(2026, 9, 8));
    $operation = Operation::factory()->create(['next_measurement_at' => '2027-01-15']);
    $plan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-04-01',
        'status' => 'finalized',
        'filename' => null,
        'storage_path' => null,
    ]);
    makeNextMeasurementLine($plan, 1, '2026-04-01', [
        'planned_monthly_percent' => 3.01,
        'planned_cumulative_percent' => 8.04,
        'realized_monthly_percent' => 3.01,
        'realized_cumulative_percent' => 3.01,
        'measurement_id' => $measurement->id,
    ]);
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    makeNextMeasurementLine($plan, 3, '2026-06-01');

    expect(resolveNextMeasurementMonth($operation))->toBe('05/2026')
        ->and($operation->fresh()->next_measurement_at->toDateString())->toBe('2027-01-15');
});

it('returns no next competence when all lines have realized progress', function (float $monthly, float $cumulative) {
    $plan = MeasurementPlanSet::factory()->default()->create();

    foreach ([1 => '2026-04-01', 2 => '2026-05-01', 3 => '2026-06-01'] as $sequence => $date) {
        makeNextMeasurementLine($plan, $sequence, $date, [
            'realized_monthly_percent' => $monthly,
            'realized_cumulative_percent' => $cumulative,
        ]);
    }

    expect(resolveNextMeasurementMonth($plan->operation))->toBeNull();
})->with([
    'monthly progress' => [3.01, 3.01],
    'cumulative progress only' => [0.0, 3.01],
]);

it('suggests the first pending line in schedule sequence', function () {
    $plan = MeasurementPlanSet::factory()->default()->create();
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    makeNextMeasurementLine($plan, 1, '2026-04-01');

    expect(resolveNextMeasurementMonth($plan->operation))->toBe('04/2026');
});

it('respects competence occupation by measurement assets before engineering consumes the line', function (string $status, string $expectedMonth) {
    $plan = MeasurementPlanSet::factory()->default()->create();
    $line = makeNextMeasurementLine($plan, 1, '2026-04-01');
    makeNextMeasurementLine($plan, 2, '2026-05-01');
    $measurement = Measurement::factory()->create([
        'operation_id' => $plan->operation_id,
        'reference_month' => '2026-04-01',
        'status' => $status,
        'filename' => null,
        'storage_path' => null,
    ]);
    $path = "nimbus_docs/measurements/assets/next-{$measurement->id}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.7\nnext competence\n%%EOF");
    $measurement->assets()->create([
        'plan_set_id' => $plan->id,
        'plan_line_id' => $line->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);

    expect($line->fresh()->measurement_id)->toBeNull()
        ->and(resolveNextMeasurementMonth($plan->operation))->toBe($expectedMonth);
})->with([
    ...array_map(fn (string $status): array => [$status, '05/2026'], Measurement::OPEN_STATUSES),
    'finalized asset' => ['finalized', '05/2026'],
    'rejected without realization can be resubmitted' => ['rejected', '04/2026'],
]);

it('does not suggest a line already consumed by engineering even after rejection', function () {
    $plan = MeasurementPlanSet::factory()->default()->create();
    $measurement = Measurement::factory()->create([
        'operation_id' => $plan->operation_id,
        'reference_month' => '2026-04-01',
        'status' => 'rejected',
        'filename' => null,
        'storage_path' => null,
    ]);
    makeNextMeasurementLine($plan, 1, '2026-04-01', ['measurement_id' => $measurement->id]);
    makeNextMeasurementLine($plan, 2, '2026-05-01');

    expect(resolveNextMeasurementMonth($plan->operation))->toBe('05/2026');
});

it('uses the default plan without mixing competences from another plan or operation', function () {
    $operation = Operation::factory()->create();
    $secondary = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $default = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $other = MeasurementPlanSet::factory()->default()->create();
    makeNextMeasurementLine($secondary, 1, '2026-03-01');
    makeNextMeasurementLine($default, 1, '2026-05-01');
    makeNextMeasurementLine($other, 1, '2026-02-01');

    expect(resolveNextMeasurementMonth($operation))->toBe('05/2026')
        ->and(resolveNextMeasurementMonth($other->operation))->toBe('02/2026');
});

it('does not switch to a secondary plan when the default has no pending competence', function () {
    $operation = Operation::factory()->create();
    $default = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $secondary = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($default, 1, '2026-04-01', ['realized_cumulative_percent' => 100]);
    makeNextMeasurementLine($secondary, 1, '2026-05-01');

    expect(resolveNextMeasurementMonth($operation))->toBeNull();
});

it('uses the first registered plan when none is explicitly default', function () {
    $operation = Operation::factory()->create();
    $first = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $second = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($first, 1, '2026-05-01');
    makeNextMeasurementLine($second, 1, '2026-04-01');

    expect($operation->defaultPlanSet()->id)->toBe($first->id)
        ->and(resolveNextMeasurementMonth($operation))->toBe('05/2026');
});

it('returns no competence without a plan or dated schedule and ignores the legacy date', function () {
    $operation = Operation::factory()->create(['next_measurement_at' => '2026-05-01']);

    expect(resolveNextMeasurementMonth($operation))->toBeNull();

    $plan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    makeNextMeasurementLine($plan, 1, null);

    expect(resolveNextMeasurementMonth($operation))->toBeNull();
});

it('renders the same derived month in the operations list and detail and sorts by it', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
    $may = Operation::factory()->create(['next_measurement_at' => '2026-09-15']);
    $june = Operation::factory()->create(['next_measurement_at' => '2026-04-15']);
    $mayPlan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $may->id]);
    $junePlan = MeasurementPlanSet::factory()->default()->create(['operation_id' => $june->id]);
    makeNextMeasurementLine($mayPlan, 1, '2026-05-01');
    makeNextMeasurementLine($junePlan, 1, '2026-06-01');

    Livewire::test(ListOperations::class)
        ->assertSuccessful()
        ->assertTableColumnFormattedStateSet('next_pending_measurement_at', '05/2026', $may)
        ->assertTableColumnFormattedStateSet('next_pending_measurement_at', '06/2026', $june)
        ->sortTable('next_pending_measurement_at')
        ->assertCanSeeTableRecords([$may, $june], inOrder: true);

    Livewire::test(ViewOperation::class, ['record' => $may->id])
        ->assertSuccessful()
        ->assertSee('05/2026')
        ->assertDontSee('15/09/2026');
});
