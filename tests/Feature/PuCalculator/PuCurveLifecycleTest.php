<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('homologates a generated version and logs the activity', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Generated->value,
    ]);

    app(HomologatePuCurve::class)->handle($emission, 'v1', $user->id);

    expect($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and(Activity::query()->where('description', 'pu_curve_homologated')->where('subject_id', $emission->id)->exists())->toBeTrue();
});

it('invalidates a version and logs the activity', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Generated->value,
    ]);

    app(InvalidatePuCurve::class)->handle($emission, 'v1', $user->id);

    expect($version->fresh()->status)->toBe(PuCurveStatus::Obsolete)
        ->and(Activity::query()->where('description', 'pu_curve_invalidated')->where('subject_id', $emission->id)->exists())->toBeTrue();
});

it('logs an audit entry with the diff when the PU parameters are updated', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    app(PuAuditLogService::class)->logParametersUpdated(
        $emission,
        ['spread_rate' => '5.00000000', 'calendar_code' => 'B3'],
        ['spread_rate' => '6.50000000', 'calendar_code' => 'B3'],
        $user->id,
    );

    $activity = Activity::query()
        ->where('description', 'pu_parameters_updated')
        ->where('subject_id', $emission->id)
        ->latest('id')
        ->first();

    expect($activity)->not()->toBeNull()
        ->and($activity->properties['changed_keys'])->toBe(['spread_rate'])
        ->and($activity->causer_id)->toBe($user->id);
});

it('logs an audit entry when the curve is exported', function () {
    $this->actingAs(makeAdminUser());
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_date' => '2026-01-02',
        'calculation_version' => 'v1',
    ]);

    $this->get(route('admin.emissions.pu-curves.export', [
        'emission' => $emission,
        'calculation_version' => 'v1',
    ]))->assertSuccessful();

    expect(Activity::query()->where('description', 'pu_curve_exported')->where('subject_id', $emission->id)->exists())->toBeTrue();
});
