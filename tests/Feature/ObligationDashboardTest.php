<?php

use App\Filament\Pages\ObligationDashboard;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\User;
use App\Services\Obligations\ObligationDashboardData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-06-18 09:00:00'));
});

afterEach(function () {
    $this->travelBack();
});

function makeDashboardObligation(string $status, ?string $dueDate, ?Emission $emission = null): Obligation
{
    $emission ??= Emission::factory()->create();

    return Obligation::factory()->for($emission)->create([
        'status' => $status,
        'due_date' => $dueDate,
        'title' => 'Comprovar destinação de recursos',
    ]);
}

function dashboardData(): ObligationDashboardData
{
    return app(ObligationDashboardData::class);
}

it('consolidates the main KPIs across emissions', function () {
    makeDashboardObligation('a_vencer', '2026-06-25'); // próximos 7? +7 → yes (within 30 too)
    makeDashboardObligation('a_vencer', '2026-06-18'); // vence hoje
    makeDashboardObligation('vencida', '2026-06-10');
    makeDashboardObligation('concluida', '2026-06-12');
    makeDashboardObligation('a_vencer', null); // sem data
    makeDashboardObligation('a_vencer', '2026-07-15'); // próximos 30

    $summary = dashboardData()->summary();

    expect($summary)->toMatchArray([
        'total' => 6,
        'a_vencer' => 4,
        'vencida' => 1,
        'concluida' => 1,
        'sem_data' => 1,
        'vence_hoje' => 1,
    ]);

    expect($summary['proximos_7_dias'])->toBe(2) // hoje + 25/06
        ->and($summary['proximos_30_dias'])->toBe(3); // hoje + 25/06 + 15/07
});

it('excludes finalized obligations from the pending date windows', function () {
    makeDashboardObligation('concluida', '2026-06-18');
    makeDashboardObligation('nao_aplicavel', '2026-06-19');

    $summary = dashboardData()->summary();

    expect($summary['vence_hoje'])->toBe(0)
        ->and($summary['proximos_7_dias'])->toBe(0);
});

it('builds the status distribution with every status present', function () {
    makeDashboardObligation('vencida', '2026-06-10');
    makeDashboardObligation('vencida', '2026-06-11');
    makeDashboardObligation('em_dia', '2026-09-01');

    $distribution = dashboardData()->statusDistribution();

    expect($distribution)->toHaveKeys(array_keys(Obligation::STATUS_OPTIONS))
        ->and($distribution['vencida'])->toBe(2)
        ->and($distribution['em_dia'])->toBe(1)
        ->and($distribution['concluida'])->toBe(0);
});

it('ranks emissions by pending obligations', function () {
    $busy = Emission::factory()->create(['name' => 'Emissão Movimentada']);
    makeDashboardObligation('vencida', '2026-06-10', $busy);
    makeDashboardObligation('a_vencer', '2026-06-30', $busy);

    $quiet = Emission::factory()->create(['name' => 'Emissão Tranquila']);
    makeDashboardObligation('a_vencer', '2026-06-30', $quiet);

    $calm = Emission::factory()->create(['name' => 'Sem Pendência']);
    makeDashboardObligation('concluida', '2026-06-30', $calm);

    $top = dashboardData()->topEmissionsByPending();

    expect($top)->toHaveCount(2)
        ->and($top->first()->name)->toBe('Emissão Movimentada')
        ->and($top->first()->pending_obligations_count)->toBe(2)
        ->and($top->first()->overdue_obligations_count)->toBe(1);
});

it('classifies urgency from the due date', function () {
    $data = dashboardData();

    expect($data->urgencyFor(makeDashboardObligation('vencida', '2026-06-10')))->toBe('critical')
        ->and($data->urgencyFor(makeDashboardObligation('a_vencer', '2026-06-20')))->toBe('high')
        ->and($data->urgencyFor(makeDashboardObligation('a_vencer', '2026-06-24')))->toBe('medium')
        ->and($data->urgencyFor(makeDashboardObligation('a_vencer', '2026-07-30')))->toBe('low')
        ->and($data->urgencyFor(makeDashboardObligation('a_vencer', null)))->toBe('undefined');
});

it('keeps finalized obligations out of the operational query', function () {
    makeDashboardObligation('vencida', '2026-06-10');
    makeDashboardObligation('concluida', '2026-06-10');
    makeDashboardObligation('nao_aplicavel', '2026-06-10');

    expect(dashboardData()->operationalQuery()->count())->toBe(1);
});

it('allows users with the obligations permission to access the dashboard', function () {
    expect(ObligationDashboard::canAccess())->toBeFalse();

    $this->actingAs(makeAdminUser());

    expect(ObligationDashboard::canAccess())->toBeTrue();
});

it('denies access to users without the obligations permission', function () {
    $this->actingAs(User::factory()->create());

    expect(ObligationDashboard::canAccess())->toBeFalse();
});

it('renders the dashboard page for an authorized user', function () {
    makeDashboardObligation('vencida', '2026-06-10');

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationDashboard::class)->assertSuccessful();
});

it('lists pending obligations and filters them by emission in the operational widget', function () {
    $alpha = Emission::factory()->create(['name' => 'Emissão Alpha']);
    $beta = Emission::factory()->create(['name' => 'Emissão Beta']);

    $alphaObligation = makeDashboardObligation('vencida', '2026-06-10', $alpha);
    $betaObligation = makeDashboardObligation('a_vencer', '2026-06-30', $beta);
    $done = makeDashboardObligation('concluida', '2026-06-10', $alpha);

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->assertCanSeeTableRecords([$alphaObligation, $betaObligation])
        ->assertCanNotSeeTableRecords([$done])
        ->filterTable('emission_id', $alpha->id)
        ->assertCanSeeTableRecords([$alphaObligation])
        ->assertCanNotSeeTableRecords([$betaObligation]);
});

it('filters the operational widget by urgency', function () {
    $overdue = makeDashboardObligation('vencida', '2026-06-10');
    $soon = makeDashboardObligation('a_vencer', '2026-07-15');

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('urgency', 'critical')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$soon]);
});
