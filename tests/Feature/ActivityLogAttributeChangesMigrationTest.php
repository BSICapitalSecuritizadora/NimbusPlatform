<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * Part of the `parity` group: the backfill selects rows by a JSON path and
 * rewrites JSON columns, which MySQL and SQLite do not evaluate the same way.
 */
pest()->group('parity');

uses(RefreshDatabase::class);

/**
 * On MySQL the DDL these tests run commits the transaction `RefreshDatabase`
 * opened, so neither the rows nor a dropped column would be undone: both would
 * reach the next file of the parity run.
 */
afterEach(function () {
    DB::table('activity_log')->delete();

    if (! Schema::hasColumn('activity_log', 'attribute_changes')) {
        attributeChangesMigration()->up();
    }
});

function attributeChangesMigration(): Migration
{
    return require database_path('migrations/2026_09_30_104121_add_attribute_changes_to_activity_log_table.php');
}

/**
 * MySQL stores a JSON object with its keys reordered, SQLite keeps the text as
 * written. Sorting the keys is what lets one expectation hold on both, without
 * giving up the strict comparison of the values.
 *
 * @param  array<string, mixed>  $json
 * @return array<string, mixed>
 */
function keySorted(array $json): array
{
    return Arr::sortRecursive($json);
}

/**
 * A row exactly as `spatie/laravel-activitylog` v4 wrote it: no
 * `attribute_changes` column involved, everything inside `properties`.
 *
 * @param  array<string, mixed>  $properties
 */
function legacyActivityRow(array $properties, ?string $batchUuid = null): int
{
    return DB::table('activity_log')->insertGetId([
        'log_name' => 'contracts',
        'description' => 'updated',
        'event' => 'updated',
        'properties' => json_encode($properties),
        'batch_uuid' => $batchUuid,
        'created_at' => '2026-09-29 10:00:00',
        'updated_at' => '2026-09-29 10:00:00',
    ]);
}

it('moves the tracked changes of a legacy activity to their own column', function () {
    $migration = attributeChangesMigration();
    $migration->down();

    $id = legacyActivityRow([
        'attributes' => ['status' => 'distratado', 'sale_value' => '900000.00'],
        'old' => ['status' => 'ativo', 'sale_value' => '850000.00'],
        'document_hash' => 'a1b2c3',
    ], batchUuid: '4f0c2f7e-7d2b-4f57-9c53-2d1d4b7a9e10');

    $migration->up();

    $activity = Activity::query()->findOrFail($id);

    expect(keySorted($activity->attribute_changes->all()))->toBe([
        'attributes' => ['sale_value' => '900000.00', 'status' => 'distratado'],
        'old' => ['sale_value' => '850000.00', 'status' => 'ativo'],
    ])
        ->and($activity->properties->all())->toBe(['document_hash' => 'a1b2c3'])
        ->and($activity->batch_uuid)->toBe('4f0c2f7e-7d2b-4f57-9c53-2d1d4b7a9e10')
        ->and($activity->updated_at->toDateTimeString())->toBe('2026-09-29 10:00:00');
});

it('leaves an empty properties collection when the changes were all a legacy activity held', function () {
    $migration = attributeChangesMigration();
    $migration->down();

    $id = legacyActivityRow(['old' => ['status' => 'ativo']]);

    $migration->up();

    $activity = Activity::query()->findOrFail($id);

    expect($activity->attribute_changes->all())->toBe(['old' => ['status' => 'ativo']])
        ->and($activity->properties)->toBeEmpty()
        ->and(DB::table('activity_log')->where('id', $id)->value('properties'))->toBe('[]');
});

it('does not touch an activity that never tracked changes', function () {
    $migration = attributeChangesMigration();
    $migration->down();

    $id = legacyActivityRow(['stage' => 2, 'delegation_scope' => ['type' => 'stage']]);

    $migration->up();

    $activity = Activity::query()->findOrFail($id);

    expect($activity->attribute_changes)->toBeNull()
        ->and(keySorted($activity->properties->all()))->toBe(['delegation_scope' => ['type' => 'stage'], 'stage' => 2]);
});

it('moves an activity written in the old layout after the column already existed', function () {
    $migration = attributeChangesMigration();

    $id = legacyActivityRow(['attributes' => ['status' => 'ativo']]);

    $migration->up();

    $activity = Activity::query()->findOrFail($id);

    expect($activity->attribute_changes->all())->toBe(['attributes' => ['status' => 'ativo']])
        ->and($activity->properties)->toBeEmpty();
});

it('puts the tracked changes back into properties on rollback', function () {
    $migration = attributeChangesMigration();

    $tracked = DB::table('activity_log')->insertGetId([
        'log_name' => 'contracts',
        'description' => 'updated',
        'event' => 'updated',
        'attribute_changes' => json_encode([
            'attributes' => ['status' => 'distratado'],
            'old' => ['status' => 'ativo'],
        ]),
        'properties' => json_encode(['document_hash' => 'a1b2c3']),
    ]);

    $untracked = DB::table('activity_log')->insertGetId([
        'log_name' => 'measurement_workflow',
        'description' => 'measurement_stage_approved',
        'attribute_changes' => '[]',
        'properties' => json_encode(['stage' => 2]),
    ]);

    $migration->down();

    $properties = fn (int $id): array => keySorted(json_decode(
        DB::table('activity_log')->where('id', $id)->value('properties'),
        true,
    ));

    expect(Schema::hasColumn('activity_log', 'attribute_changes'))->toBeFalse()
        ->and($properties($tracked))->toBe([
            'attributes' => ['status' => 'distratado'],
            'document_hash' => 'a1b2c3',
            'old' => ['status' => 'ativo'],
        ])
        ->and($properties($untracked))->toBe(['stage' => 2]);
});
