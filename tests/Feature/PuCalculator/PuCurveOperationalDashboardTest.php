<?php

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Filament\Pages\PuCurveOperationalDashboard;
use App\Filament\Widgets\PuCalculator\PuCurveOperationalSummaryWidget;
use App\Filament\Widgets\PuCalculator\PuCurveOperationalTableWidget;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
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

/**
 * Cobertura de calendario e CDI suficiente para o periodo das emissoes do painel,
 * de modo que "CDI faltante" nao contamine os cenarios sob teste.
 */
function seedDashboardCdiCoverage(): void
{
    for ($date = CarbonImmutable::parse('2026-01-15'); $date->lte(CarbonImmutable::parse('2026-02-10')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);

        if ($date->isWeekend()) {
            continue;
        }

        IndexRate::query()->create([
            'indexer' => 'CDI',
            'rate_date' => $date->toDateString(),
            'rate_value' => '13.65000000',
            'source' => 'testing',
            'source_reference' => 'dashboard-fixture',
        ]);
    }
}

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

it('renders the dashboard page and summary widget for an authorized user', function () {
    $this->actingAs(makeAdminUser());
    dashboardEmissionWithPu();

    Livewire::test(PuCurveOperationalDashboard::class)->assertSuccessful();
    Livewire::test(PuCurveOperationalSummaryWidget::class)->assertSuccessful();
});

it('reports a clear operational state when no exception is open', function () {
    $this->actingAs(makeAdminUser());
    seedDashboardCdiCoverage();
    $emission = dashboardEmissionWithPu();
    EmissionPuCurveVersion::factory()->homologated()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
    ]);

    Livewire::test(PuCurveOperationalSummaryWidget::class)
        ->assertSuccessful()
        ->assertSee('Operação normal')
        ->assertSee('Sem ocorrências críticas')
        ->assertSee('Nenhuma exceção operacional detectada')
        ->assertSee('Fila ociosa e sem falhas')
        ->assertSee('Esteira operacional');
});

it('escalates the operational state when a curve is in error', function () {
    $this->actingAs(makeAdminUser());
    seedDashboardCdiCoverage();
    $emission = dashboardEmissionWithPu();
    EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Error->value,
    ]);

    Livewire::test(PuCurveOperationalSummaryWidget::class)
        ->assertSuccessful()
        ->assertSee('Ação imediata')
        ->assertSee('1 ocorrência crítica exige ação imediata')
        ->assertSee('Com erro')
        ->assertDontSee('Nenhuma exceção operacional detectada');
});

it('flags emissions with PU configured but no curve as an exception', function () {
    $this->actingAs(makeAdminUser());
    seedDashboardCdiCoverage();
    dashboardEmissionWithPu();

    Livewire::test(PuCurveOperationalSummaryWidget::class)
        ->assertSuccessful()
        ->assertSee('Atenção')
        ->assertSee('1 ocorrência exige atenção')
        ->assertSee('Sem curva');
});

it('dispatches the selected indicator as a table focus and toggles it off', function () {
    $this->actingAs(makeAdminUser());
    dashboardEmissionWithPu();

    Livewire::test(PuCurveOperationalSummaryWidget::class)
        ->assertDontSee('Recorte ativo')
        ->call('focusState', 'divergent')
        ->assertSet('focusedState', 'divergent')
        ->assertDispatched('pu-curves-focus', state: 'divergent')
        ->assertSee('Recorte ativo')
        ->call('focusState', 'divergent')
        ->assertSet('focusedState', null)
        ->assertDispatched('pu-curves-focus', state: null);
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

it('narrows the table to the focused indicator', function () {
    $this->actingAs(makeAdminUser());

    $divergent = dashboardEmissionWithPu();
    $divergent->update(['name' => 'Emissão Divergente']);
    EmissionPuCurveVersion::factory()->create([
        'emission_id' => $divergent->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Divergent->value,
    ]);

    $homologated = dashboardEmissionWithPu();
    $homologated->update(['name' => 'Emissão Homologada']);
    EmissionPuCurveVersion::factory()->homologated()->create([
        'emission_id' => $homologated->id,
        'calculation_version' => 'v1',
    ]);

    Livewire::test(PuCurveOperationalTableWidget::class)
        ->assertCanSeeTableRecords([$divergent, $homologated])
        ->call('focusCurves', 'divergent')
        ->assertSet('focusedState', 'divergent')
        ->assertCanSeeTableRecords([$divergent])
        ->assertCanNotSeeTableRecords([$homologated])
        ->call('focusCurves', null)
        ->assertCanSeeTableRecords([$divergent, $homologated]);
});

it('narrows the table to emissions without a generated curve', function () {
    $this->actingAs(makeAdminUser());

    $withoutCurve = dashboardEmissionWithPu();
    $withoutCurve->update(['name' => 'Emissão Sem Curva']);

    $withCurve = dashboardEmissionWithPu();
    $withCurve->update(['name' => 'Emissão Com Curva']);
    EmissionPuCurveVersion::factory()->homologated()->create([
        'emission_id' => $withCurve->id,
        'calculation_version' => 'v1',
    ]);

    Livewire::test(PuCurveOperationalTableWidget::class)
        ->call('focusCurves', 'sem_curva')
        ->assertCanSeeTableRecords([$withoutCurve])
        ->assertCanNotSeeTableRecords([$withCurve]);
});

it('shows a neutral empty state pointing to the emissions list', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(PuCurveOperationalTableWidget::class)
        ->assertSuccessful()
        ->assertSee('Nenhuma emissão com PU configurado')
        ->assertSee('Ir para emissões');
});
