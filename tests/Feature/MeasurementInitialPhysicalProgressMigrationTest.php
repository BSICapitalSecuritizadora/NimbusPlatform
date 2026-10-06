<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Services\MeasurementEngineeringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);
pest()->group('parity');

function initialPhysicalProgressMigration(): object
{
    return require database_path('migrations/2026_10_05_170828_add_initial_physical_progress_to_measurement_plan_sets_table.php');
}

/**
 * Plano como estava antes da coluna nova: sem avanço inicial, com o
 * "Realiz. inicial" digitado linha a linha.
 *
 * @param  array<int, int|float>  $initialsBySequence
 * @return array{planSet: MeasurementPlanSet, lines: array<int, MeasurementPlanLine>}
 */
function planWithLegacyLineInitials(array $initialsBySequence): array
{
    $planSet = MeasurementPlanSet::factory()->create();
    $lines = [];

    foreach ($initialsBySequence as $sequence => $initial) {
        $lines[$sequence] = MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id,
            'operation_id' => $planSet->operation_id,
            'sequence_number' => $sequence,
            'measurement_date' => sprintf('2026-%02d-01', 4 + $sequence),
            'initial_realized_cumulative_percent' => $initial,
        ]);
    }

    return compact('planSet', 'lines');
}

/**
 * Aprovação gravada pelo cálculo antigo: o snapshot guarda o inicial da linha,
 * o mensal e o acumulado que o motor calculou.
 */
function legacyApproval(MeasurementPlanLine $line, string $initial, string $monthly, string $cumulative, string $engineering = 'approved'): Measurement
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
                'initial_realized_cumulative_percent' => $initial,
                'realized_monthly_percent' => $monthly,
                'realized_cumulative_percent' => $cumulative,
            ]],
        ],
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => $engineering]);

    return $measurement;
}

/**
 * Aprovação anterior ao snapshot da Engenharia: o avanço aprovado ficou só na
 * linha que a medição gravou.
 */
function legacyApprovalWithoutSnapshot(MeasurementPlanLine $line, string $monthly, string $cumulative): Measurement
{
    $measurement = Measurement::factory()->create([
        'operation_id' => $line->operation_id,
        'reference_month' => $line->measurement_date->toDateString(),
        'status' => 'in_review',
        'current_stage' => 2,
        'engineering_snapshot' => null,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'approved']);
    DB::table('measurement_plan_lines')->where('id', $line->id)->update([
        'measurement_id' => $measurement->id,
        'realized_monthly_percent' => $monthly,
        'realized_cumulative_percent' => $cumulative,
    ]);

    return $measurement;
}

it('copies to the plan the initial progress an approval already used, with its provenance and without inventing a date', function () {
    ['planSet' => $planSet, 'lines' => $lines] = planWithLegacyLineInitials([1 => 35, 2 => 35]);
    $approval = legacyApproval($lines[1], '35.00', '5.00', '40.00');

    initialPhysicalProgressMigration()->up();

    $provenance = DB::table('activity_log')->where('description', 'initial_physical_progress_backfilled')->sole();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('35.00')
        ->and($planSet->fresh()->initial_physical_progress_reference_date)->toBeNull()
        ->and($provenance->log_name)->toBe('measurements')
        ->and($provenance->subject_id)->toBe($planSet->id)
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
    ['planSet' => $planSet, 'lines' => $lines] = planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
    $arrange($lines);

    initialPhysicalProgressMigration()->up();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('35.00')
        ->and(DB::table('activity_log')->where('description', 'initial_physical_progress_backfilled')->where('subject_id', $planSet->id)->exists())->toBeTrue()
        ->and(DB::table('activity_log')->where('description', 'initial_physical_progress_not_backfilled')->exists())->toBeFalse();
})->with([
    'measured in order after the first line' => [function (array $lines): void {
        legacyApproval($lines[1], '35.00', '5.00', '40.00');
        legacyApproval($lines[2], '0.00', '4.00', '44.00');
    }],
    'initial plus measured reaching exactly 100%' => [function (array $lines): void {
        legacyApproval($lines[1], '35.00', '5.00', '40.00');
        legacyApproval($lines[2], '0.00', '60.00', '100.00');
    }],
    'approved before the engineering snapshot existed' => [fn (array $lines): Measurement => legacyApprovalWithoutSnapshot($lines[1], '5.00', '40.00')],
]);

it('keeps 0% and records why when no current approval proves the declared initial progress', function (Closure $arrange, string $reason) {
    ['planSet' => $planSet, 'lines' => $lines] = planWithLegacyLineInitials([1 => 35, 2 => 0, 3 => 0]);
    $arrange($lines);

    initialPhysicalProgressMigration()->up();

    $decision = DB::table('activity_log')->where('description', 'initial_physical_progress_not_backfilled')->sole();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('0.00')
        ->and($decision->subject_id)->toBe($planSet->id)
        ->and($decision->log_name)->toBe('measurements')
        ->and($decision->attribute_changes)->toBeNull()
        ->and(json_decode($decision->properties, true))->toMatchArray([
            'plan_line_id' => $lines[1]->id,
            'line_initial_percent' => '35.00',
            'proven_by_measurement_id' => null,
            'reason' => $reason,
        ])
        ->and(DB::table('activity_log')->where('description', 'initial_physical_progress_backfilled')->exists())->toBeFalse();
})->with([
    'not measured yet' => [fn (array $lines): null => null, 'no_current_approval'],
    'first approval returned to Engineering' => [fn (array $lines): Measurement => legacyApproval($lines[1], '35.00', '5.00', '40.00', engineering: 'pending'), 'no_current_approval'],
    'a later line approved from a zero base' => [fn (array $lines): Measurement => legacyApproval($lines[2], '0.00', '10.00', '10.00'), 'approval_started_below_initial'],
    'copy would take the plan above 100%' => [function (array $lines): void {
        legacyApproval($lines[1], '35.00', '5.00', '40.00');
        legacyApproval($lines[2], '0.00', '61.00', '101.00');
    }, 'initial_plus_measured_above_100'],
    'an approval before the snapshot takes it above 100%' => [function (array $lines): void {
        legacyApproval($lines[1], '35.00', '5.00', '40.00');
        legacyApprovalWithoutSnapshot($lines[2], '61.00', '101.00');
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
    ['planSet' => $copied, 'lines' => $copiedLines] = planWithLegacyLineInitials([1 => 12.5]);
    legacyApproval($copiedLines[1], '12.50', '2.00', '14.50');
    ['planSet' => $refused, 'lines' => $refusedLines] = planWithLegacyLineInitials([1 => 20, 2 => 0]);
    legacyApproval($refusedLines[2], '0.00', '5.00', '5.00');

    initialPhysicalProgressMigration()->up();
    initialPhysicalProgressMigration()->up();

    expect($informed->fresh()->initial_physical_progress_percent)->toBe('10.00')
        ->and($informed->fresh()->initial_physical_progress_reference_date->toDateString())->toBe('2026-04-30')
        ->and($copied->fresh()->initial_physical_progress_percent)->toBe('12.50')
        ->and($refused->fresh()->initial_physical_progress_percent)->toBe('0.00')
        ->and(DB::table('activity_log')->where('description', 'initial_physical_progress_backfilled')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('description', 'initial_physical_progress_not_backfilled')->count())->toBe(1);
});

it('refuses to roll back while a plan holds an initial progress', function (Closure $arrange) {
    $arrange();

    expect(fn () => initialPhysicalProgressMigration()->down())
        ->toThrow(RuntimeException::class, 'a reversão o apagaria e não é admitida');
})->with([
    'informed at creation' => [fn (): MeasurementPlanSet => MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.00', '2026-04-30')->create()],
    'copied from the schedule line' => [function (): void {
        ['lines' => $lines] = planWithLegacyLineInitials([1 => 35]);
        legacyApproval($lines[1], '35.00', '5.00', '40.00');
        initialPhysicalProgressMigration()->up();
    }],
]);

it('rolls the columns back while no plan holds an initial progress', function () {
    planWithLegacyLineInitials([1 => 35]);
    initialPhysicalProgressMigration()->up();

    initialPhysicalProgressMigration()->down();

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
