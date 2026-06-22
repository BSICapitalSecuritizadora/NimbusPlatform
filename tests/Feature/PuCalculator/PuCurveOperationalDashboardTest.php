<?php

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Filament\Pages\PuCurveOperationalDashboard;
use App\Filament\Widgets\PuCalculator\PuCurveOperationalTableWidget;
use App\Filament\Widgets\PuCalculator\PuCurveOverviewStatsWidget;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::flush();
});

function dashboardEmissionWithPu(): Emission
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active', 'name' => 'Emissão Painel']);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-02-02',
        'curve_end_date' => '2026-02-05',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => 'previous_available_business_day',
        'legacy_projection_enabled' => false,
    ]);

    return $emission;
}

it('allows users with pu.dashboard.view to access the dashboard', function () {
    expect(PuCurveOperationalDashboard::canAccess())->toBeFalse();

    $this->actingAs(makeAdminUser());

    expect(PuCurveOperationalDashboard::canAccess())->toBeTrue();
});

it('denies the dashboard to users without the permission', function () {
    $this->actingAs(User::factory()->create());

    expect(PuCurveOperationalDashboard::canAccess())->toBeFalse();
});

it('renders the dashboard page and stats widget for an authorized user', function () {
    $this->actingAs(makeAdminUser());
    dashboardEmissionWithPu();

    Livewire::test(PuCurveOperationalDashboard::class)->assertSuccessful();
    Livewire::test(PuCurveOverviewStatsWidget::class)->assertSuccessful();
});

it('lists emissions with PU in the operational table widget', function () {
    $this->actingAs(makeAdminUser());
    $emission = dashboardEmissionWithPu();
    EmissionPuCurveVersion::factory()->homologated()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
    ]);

    Livewire::test(PuCurveOperationalTableWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$emission])
        ->assertSee('Emissão Painel');
});

it('renders an em dash fallback for versions without snapshots', function () {
    $this->actingAs(makeAdminUser());
    $emission = dashboardEmissionWithPu();
    EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Generated->value,
        'parameters_snapshot' => null,
        'validation_summary' => null,
    ]);

    Livewire::test(PuCurveOperationalTableWidget::class)
        ->assertSuccessful()
        ->assertSee('—');
});
