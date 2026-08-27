<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('retains protected workflow logs beyond disposable window but removes disposable', function () {
    $disposableDays = (int) config('audit.retention_disposable_days', 365);
    $workflowDays = (int) config('audit.retention_workflow_days', 2555);

    $oldDate = now()->subDays($disposableDays + 10);
    $veryOld = now()->subDays($workflowDays + 10);

    // Create disposable old activity
    Activity::create([
        'log_name' => 'default',
        'description' => 'test disposable',
        'causer_type' => null,
        'causer_id' => null,
        'subject_type' => null,
        'subject_id' => null,
        'properties' => json_encode([]),
        'created_at' => $oldDate,
        'updated_at' => $oldDate,
    ]);

    // Create protected old activity within workflow retention (should survive)
    Activity::create([
        'log_name' => 'measurement_workflow',
        'description' => 'test protected',
        'causer_type' => null,
        'causer_id' => null,
        'subject_type' => null,
        'subject_id' => null,
        'properties' => json_encode([]),
        'created_at' => $oldDate,
        'updated_at' => $oldDate,
    ]);

    // Create protected very old beyond workflow retention (should be deleted)
    $veryOldActivity = Activity::create([
        'log_name' => 'measurement_workflow',
        'description' => 'very old protected',
        'causer_type' => null,
        'causer_id' => null,
        'subject_type' => null,
        'subject_id' => null,
        'properties' => json_encode([]),
        'created_at' => $veryOld,
        'updated_at' => $veryOld,
    ]);

    $this->artisan('audit:clean-filtered')->assertExitCode(0);

    expect(Activity::where('log_name', 'default')->where('description', 'test disposable')->exists())->toBeFalse();
    expect(Activity::where('log_name', 'measurement_workflow')->where('description', 'test protected')->exists())->toBeTrue();
    expect(Activity::where('id', $veryOldActivity->id)->exists())->toBeFalse();
});

it('reports dry-run without deleting', function () {
    $oldDate = now()->subDays(400);
    Activity::create([
        'log_name' => 'default',
        'description' => 'dry run test',
        'created_at' => $oldDate,
        'updated_at' => $oldDate,
    ]);

    $this->artisan('audit:clean-filtered --dry-run')->assertExitCode(0);

    expect(Activity::where('description', 'dry run test')->exists())->toBeTrue();
});
