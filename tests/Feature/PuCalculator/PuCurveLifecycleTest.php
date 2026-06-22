<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

it('logs an audit entry when the PU parameters are updated through the screen', function () {
    $user = makeAdminUser();
    $this->actingAs($user);
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->callAction('configurePuCalculation', data: [
            'curve_start_date' => '2026-02-02',
            'curve_end_date' => '2026-02-05',
            'initial_unit_value' => '1000.0000000000000000',
            'spread_rate' => '6.50000000',
            'indexer' => 'CDI',
            'business_day_basis' => 252,
            'calendar_code' => 'B3',
            'index_rate_lookup_mode' => 'previous_available_business_day',
            'index_rate_lag_business_days' => 1,
            'legacy_projection_enabled' => true,
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::query()
        ->where('description', 'pu_parameters_updated')
        ->where('subject_id', $emission->id)
        ->latest('id')
        ->first();

    expect($emission->fresh()->puParameter)->not()->toBeNull()
        ->and($activity)->not()->toBeNull()
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
