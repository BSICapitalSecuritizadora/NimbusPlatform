<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MeasurementLegacyInitialProgressFixture as Fixture;

uses(RefreshDatabase::class);
pest()->group('parity');

it('copies to the plan the initial progress an approval already used, with its provenance and without inventing a date', function () {
    ['planSet' => $planSet, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 35]);
    $approval = Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');

    Fixture::migration()->up();

    $provenance = Fixture::trailsOf($planSet->id)->sole();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('35.00')
        ->and($planSet->fresh()->initial_physical_progress_reference_date)->toBeNull()
        ->and($provenance->description)->toBe('initial_physical_progress_backfilled')
        ->and($provenance->log_name)->toBe('measurements')
        ->and($provenance->causer_id)->toBeNull()
        ->and(json_decode($provenance->properties, true))->toMatchArray([
            'plan_line_id' => $lines[1]->id,
            'proven_by_measurement_id' => $approval->id,
            'reason' => null,
        ])
        // toEqual, e não toBe: o MySQL reordena as chaves de um objeto JSON.
        ->and(json_decode($provenance->attribute_changes, true))->toEqual([
            'attributes' => ['initial_physical_progress_percent' => '35.00'],
            'old' => ['initial_physical_progress_percent' => '0.00'],
        ]);
});

it('copies the initial progress the current approvals carry', function (Closure $arrange) {
    ['planSet' => $planSet, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
    $arrange($lines);

    Fixture::migration()->up();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('35.00')
        ->and(Fixture::trailsOf($planSet->id)->pluck('description')->all())->toBe(['initial_physical_progress_backfilled']);
})->with([
    'measured in order after the first line' => [function (array $lines): void {
        Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
        Fixture::legacyApproval($lines[2], '0.00', '4.00', '44.00');
    }],
    'initial plus measured reaching exactly 100%' => [function (array $lines): void {
        Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
        Fixture::legacyApproval($lines[2], '0.00', '60.00', '100.00');
    }],
    'approved before the engineering snapshot existed' => [fn (array $lines): Measurement => Fixture::legacyApprovalWithoutSnapshot($lines[1], '5.00', '40.00')],
]);

it('keeps 0% and records why when no current approval proves the declared initial progress', function (Closure $arrange, string $reason) {
    ['planSet' => $planSet, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
    $arrange($lines);

    Fixture::migration()->up();

    $decision = Fixture::trailsOf($planSet->id)->sole();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('0.00')
        ->and($decision->description)->toBe('initial_physical_progress_not_backfilled')
        ->and($decision->log_name)->toBe('measurements')
        ->and($decision->attribute_changes)->toBeNull()
        ->and(json_decode($decision->properties, true))->toMatchArray([
            'plan_line_id' => $lines[1]->id,
            'line_initial_percent' => '35.00',
            'proven_by_measurement_id' => null,
            'reason' => $reason,
        ]);
})->with([
    'not measured yet' => [fn (array $lines): null => null, 'no_current_approval'],
    'first approval returned to Engineering' => [fn (array $lines): Measurement => Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00', engineering: 'pending'), 'no_current_approval'],
    'a later line approved from a zero base' => [fn (array $lines): Measurement => Fixture::legacyApproval($lines[2], '0.00', '10.00', '10.00'), 'approval_started_below_initial'],
    'copy would take the plan above 100%' => [function (array $lines): void {
        Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
        Fixture::legacyApproval($lines[2], '0.00', '61.00', '101.00');
    }, 'initial_plus_measured_above_100'],
    'an approval before the snapshot takes it above 100%' => [function (array $lines): void {
        Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
        Fixture::legacyApprovalWithoutSnapshot($lines[2], '61.00', '101.00');
    }, 'initial_plus_measured_above_100'],
]);

it('keeps the initial progress informed at creation and decides each plan only once', function () {
    $informed = MeasurementPlanSet::factory()->withInitialPhysicalProgress('10.00', '2026-04-30')->create();
    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $informed->id,
        'operation_id' => $informed->operation_id,
        'sequence_number' => 1,
        'measurement_date' => '2026-05-01',
        'initial_realized_cumulative_percent' => 35,
    ]);
    ['planSet' => $copied, 'lines' => $copiedLines] = Fixture::planWithLegacyLineInitials([1 => 12.5]);
    Fixture::legacyApproval($copiedLines[1], '12.50', '2.00', '14.50');
    ['planSet' => $refused, 'lines' => $refusedLines] = Fixture::planWithLegacyLineInitials([1 => 20, 2 => 0]);
    Fixture::legacyApproval($refusedLines[2], '0.00', '5.00', '5.00');

    Fixture::migration()->up();
    Fixture::migration()->up();

    expect($informed->fresh()->initial_physical_progress_percent)->toBe('10.00')
        ->and($informed->fresh()->initial_physical_progress_reference_date->toDateString())->toBe('2026-04-30')
        ->and($copied->fresh()->initial_physical_progress_percent)->toBe('12.50')
        ->and($refused->fresh()->initial_physical_progress_percent)->toBe('0.00')
        ->and(Fixture::trailsOf($informed->id))->toBeEmpty()
        ->and(Fixture::trailsOf($copied->id)->pluck('description')->all())->toBe(['initial_physical_progress_backfilled'])
        ->and(Fixture::trailsOf($refused->id)->pluck('description')->all())->toBe(['initial_physical_progress_not_backfilled']);
});

it('refuses to roll back while a plan holds an initial progress', function (Closure $arrange) {
    $arrange();

    expect(fn () => Fixture::migration()->down())
        ->toThrow(RuntimeException::class, 'a reversão o apagaria e não é admitida');
})->with([
    'informed at creation' => [fn (): MeasurementPlanSet => MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.00', '2026-04-30')->create()],
    'copied from the schedule line' => [function (): void {
        ['lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35]);
        Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
        Fixture::migration()->up();
    }],
]);

it('rolls the columns back while no plan holds an initial progress', function () {
    Fixture::planWithLegacyLineInitials([1 => 35]);
    Fixture::migration()->up();

    Fixture::migration()->down();

    expect(Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_percent'))->toBeFalse()
        ->and(Schema::hasColumn('measurement_plan_sets', 'initial_physical_progress_reference_date'))->toBeFalse();
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'No MySQL o DDL faz commit implícito; o DROP CHECK seguido das colunas é coberto pelo grupo mysql.');

it('moves the plan and schedule trail left in the default log to the protected category and is idempotent', function () {
    $planSet = MeasurementPlanSet::factory()->create();
    $legacyRow = fn (string $subjectType, int $subjectId): int => DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'created',
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'properties' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $planRow = $legacyRow('App\\Models\\MeasurementPlanSet', $planSet->id);
    $lineRow = $legacyRow('App\\Models\\MeasurementPlanLine', 1);
    $otherRow = $legacyRow('App\\Models\\Fund', 1);
    $logNameOf = fn (int $id): ?string => DB::table('activity_log')->where('id', $id)->value('log_name');
    $migration = require database_path('migrations/2026_10_05_171746_move_measurement_plan_audit_trail_to_protected_logs.php');

    $migration->up();

    expect($logNameOf($planRow))->toBe('measurements')
        ->and($logNameOf($lineRow))->toBe('measurements')
        ->and($logNameOf($otherRow))->toBe('default');

    $before = DB::table('activity_log')->orderBy('id')->get(['id', 'log_name', 'updated_at'])->toArray();

    $migration->up();

    expect(DB::table('activity_log')->orderBy('id')->get(['id', 'log_name', 'updated_at'])->toArray())->toEqual($before);
});
