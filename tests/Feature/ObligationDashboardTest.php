<?php

use App\Enums\AccessPermission;
use App\Filament\Pages\ObligationDashboard;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
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

function makeDashboardObligation(string $status, ?string $dueDate, ?Emission $emission = null, array $attributes = []): Obligation
{
    $emission ??= Emission::factory()->create();

    return Obligation::factory()->for($emission)->create(array_merge([
        'status' => $status,
        'due_date' => $dueDate,
        'title' => 'Comprovar destinação de recursos',
    ], $attributes));
}

function makeDashboardEvidence(Obligation $obligation, string $status): ObligationEvidence
{
    $factory = ObligationEvidence::factory()
        ->for($obligation)
        ->for($obligation->emission);

    return match ($status) {
        ObligationEvidence::STATUS_APPROVED => $factory->approved()->create(),
        ObligationEvidence::STATUS_REJECTED => $factory->rejected()->create(),
        default => $factory->create(['status' => ObligationEvidence::STATUS_PENDING]),
    };
}

function dashboardData(): ObligationDashboardData
{
    return app(ObligationDashboardData::class);
}

function makeDashboardUserWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('consolidates the main KPIs across emissions', function () {
    makeDashboardObligation('a_vencer', '2026-06-25');
    makeDashboardObligation('a_vencer', '2026-06-18');
    makeDashboardObligation('vencida', '2026-06-10');
    makeDashboardObligation('concluida', '2026-06-12');
    makeDashboardObligation('a_vencer', null);
    makeDashboardObligation('a_vencer', '2026-07-15');

    $summary = dashboardData()->summary();

    expect($summary)->toMatchArray([
        'total' => 6,
        'a_vencer' => 4,
        'vencida' => 1,
        'concluida' => 1,
        'sem_data' => 1,
        'vence_hoje' => 1,
    ]);

    expect($summary['proximos_7_dias'])->toBe(2)
        ->and($summary['proximos_30_dias'])->toBe(3);
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

it('exposes operational and documentary KPIs in the summary', function () {
    $responsible = User::factory()->create();

    $approved = makeDashboardObligation('a_vencer', '2026-06-20', null, [
        'responsible_user_id' => $responsible->id,
    ]);
    makeDashboardEvidence($approved, ObligationEvidence::STATUS_APPROVED);

    $pendingReview = makeDashboardObligation('em_analise', '2026-06-21', null, [
        'responsible_user_id' => null,
    ]);
    makeDashboardEvidence($pendingReview, ObligationEvidence::STATUS_PENDING);

    $rejected = makeDashboardObligation('vencida', '2026-06-10');
    makeDashboardEvidence($rejected, ObligationEvidence::STATUS_REJECTED);

    makeDashboardObligation('a_vencer', null, null, [
        'responsible_user_id' => null,
    ]);

    makeDashboardObligation('concluida', '2026-06-12');

    $summary = dashboardData()->summary();

    expect($summary)->toMatchArray([
        'em_analise' => 1,
        'sem_responsavel' => 3,
        'sem_data' => 1,
        'com_evidencia_aprovada' => 1,
        'com_evidencia_pendente' => 1,
        'com_evidencia_rejeitada' => 1,
        'sem_evidencia' => 2,
        'concluidas_sem_evidencia_aprovada' => 1,
    ]);
});

it('builds priority, responsible and area breakdowns for open obligations', function () {
    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create(['name' => 'Bruno']);

    makeDashboardObligation('vencida', '2026-06-10', null, [
        'priority' => 'critical',
        'responsible_user_id' => $ana->id,
        'responsible_area' => 'Jurídico',
    ]);
    makeDashboardObligation('a_vencer', '2026-06-25', null, [
        'priority' => 'high',
        'responsible_user_id' => $ana->id,
        'responsible_area' => 'Jurídico',
    ]);
    makeDashboardObligation('em_analise', '2026-06-30', null, [
        'priority' => 'medium',
        'responsible_user_id' => $bruno->id,
        'responsible_area' => 'Compliance',
    ]);
    makeDashboardObligation('concluida', '2026-06-12', null, [
        'priority' => 'critical',
        'responsible_user_id' => $ana->id,
        'responsible_area' => 'Jurídico',
    ]);

    $priorityDistribution = dashboardData()->priorityDistribution();
    $responsibles = dashboardData()->topResponsiblesByPending();
    $areas = dashboardData()->topAreasByPending();

    expect($priorityDistribution)->toMatchArray([
        'critical' => 1,
        'high' => 1,
        'medium' => 1,
        'low' => 0,
    ]);

    expect($responsibles->first()->name)->toBe('Ana')
        ->and((int) $responsibles->first()->pending_obligations_count)->toBe(2)
        ->and((int) $responsibles->first()->overdue_obligations_count)->toBe(1);

    expect($areas->first()->label)->toBe('Jurídico')
        ->and((int) $areas->first()->pending_obligations_count)->toBe(2)
        ->and((int) $areas->first()->overdue_obligations_count)->toBe(1);
});

it('builds overdue aging buckets from open obligations', function () {
    makeDashboardObligation('vencida', '2026-06-17');
    makeDashboardObligation('em_analise', '2026-06-08');
    makeDashboardObligation('vencida', '2026-05-30');
    makeDashboardObligation('vencida', '2026-05-10');
    makeDashboardObligation('concluida', '2026-06-16');

    expect(dashboardData()->overdueAging())->toMatchArray([
        'days_1_7' => 1,
        'days_8_15' => 1,
        'days_16_30' => 1,
        'days_31_plus' => 1,
    ]);
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

it('allows users with the view dashboard permission to access the dashboard', function () {
    expect(ObligationDashboard::canAccess())->toBeFalse();

    $this->actingAs(makeDashboardUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
    ]));

    expect(ObligationDashboard::canAccess())->toBeTrue();
});

it('denies access to users without the view dashboard permission', function () {
    $this->actingAs(makeDashboardUserWithPermissions([
        AccessPermission::ObligationsView->value,
    ]));

    expect(ObligationDashboard::canAccess())->toBeFalse();
});

it('denies access to users without the obligations view permission', function () {
    $this->actingAs(makeDashboardUserWithPermissions([
        AccessPermission::ObligationsViewDashboard->value,
    ]));

    expect(ObligationDashboard::canAccess())->toBeFalse();
});

it('renders the dashboard page for an authorized user', function () {
    makeDashboardObligation('vencida', '2026-06-10');

    $this->actingAs(makeDashboardUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
    ]));

    Livewire::test(ObligationDashboard::class)->assertSuccessful();
});

it('keeps dashboard access available for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $this->actingAs($user);

    expect(ObligationDashboard::canAccess())->toBeTrue();
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

it('filters the operational widget by responsible, area and priority', function () {
    $responsible = User::factory()->create(['name' => 'Fernanda']);
    $match = makeDashboardObligation('a_vencer', '2026-06-25', null, [
        'responsible_user_id' => $responsible->id,
        'responsible_area' => 'Compliance',
        'priority' => 'critical',
    ]);
    $other = makeDashboardObligation('a_vencer', '2026-06-25', null, [
        'responsible_area' => 'Gestão',
        'priority' => 'medium',
    ]);

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('responsible_user_id', $responsible->id)
        ->filterTable('responsible_area', 'Compliance')
        ->filterTable('priority', 'critical')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters the operational widget by documentary state and due window', function () {
    $rejected = makeDashboardObligation('a_vencer', '2026-06-25');
    makeDashboardEvidence($rejected, ObligationEvidence::STATUS_REJECTED);

    $withoutEvidence = makeDashboardObligation('vencida', '2026-06-10');
    $approved = makeDashboardObligation('a_vencer', '2026-06-30');
    makeDashboardEvidence($approved, ObligationEvidence::STATUS_APPROVED);

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('evidence_state', ObligationEvidence::STATUS_REJECTED)
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$withoutEvidence, $approved]);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('due_window', 'overdue')
        ->assertCanSeeTableRecords([$withoutEvidence])
        ->assertCanNotSeeTableRecords([$rejected, $approved]);
});

it('prioritizes critical overdue items in the operational query ordering', function () {
    $criticalOverdue = makeDashboardObligation('vencida', '2026-06-10', null, [
        'priority' => 'critical',
        'title' => 'Critica vencida',
    ]);
    $regularOverdue = makeDashboardObligation('vencida', '2026-06-11', null, [
        'priority' => 'medium',
        'title' => 'Media vencida',
    ]);
    $dueToday = makeDashboardObligation('a_vencer', '2026-06-18', null, [
        'priority' => 'high',
        'title' => 'Vence hoje',
    ]);
    $nextWeek = makeDashboardObligation('a_vencer', '2026-06-20', null, [
        'priority' => 'high',
        'title' => 'Proxima semana',
    ]);
    $review = makeDashboardObligation('em_analise', '2026-07-10', null, [
        'title' => 'Em analise',
    ]);
    $rejected = makeDashboardObligation('a_vencer', '2026-07-12', null, [
        'title' => 'Rejeitada',
    ]);
    makeDashboardEvidence($rejected, ObligationEvidence::STATUS_REJECTED);
    $withoutEvidence = makeDashboardObligation('a_vencer', '2026-07-13', null, [
        'title' => 'Sem evidencia',
    ]);
    $withoutResponsible = makeDashboardObligation('a_vencer', '2026-07-14', null, [
        'title' => 'Sem responsavel',
        'responsible_user_id' => null,
    ]);
    makeDashboardEvidence($withoutResponsible, ObligationEvidence::STATUS_APPROVED);

    $orderedIds = dashboardData()->operationalQuery()->pluck('id')->all();

    expect(array_slice($orderedIds, 0, 8))->toBe([
        $criticalOverdue->id,
        $regularOverdue->id,
        $dueToday->id,
        $nextWeek->id,
        $review->id,
        $rejected->id,
        $withoutEvidence->id,
        $withoutResponsible->id,
    ]);
});
