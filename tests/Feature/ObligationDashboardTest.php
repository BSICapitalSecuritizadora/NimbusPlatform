<?php

use App\Enums\AccessPermission;
use App\Filament\Pages\ObligationDashboard;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Widgets\Obligations\ObligationEvidenceOverviewStatsWidget;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Filament\Widgets\Obligations\ObligationOverviewStatsWidget;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\User;
use App\Services\Obligations\ObligationDashboardData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
    $user = User::factory()->create([
        'approved_at' => now(),
        'is_active' => true,
    ]);
    $user->givePermissionTo($permissions);

    return $user;
}

function dashboardRelationManager(Emission $emission): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ]);
}

function obligationDashboardTableQuery(object $component): Builder
{
    if (function_exists('invade')) {
        /** @var Table $table */
        $table = invade($component)->getTable();

        return $table->getQuery();
    }

    $method = new ReflectionMethod($component, 'getTable');
    $method->setAccessible(true);

    /** @var Table $table */
    $table = $method->invoke($component);

    return $table->getQuery();
}

it('consolidates the main operational KPIs across emissions', function () {
    makeDashboardObligation('a_vencer', '2026-06-25', null, ['priority' => 'high']);
    makeDashboardObligation('a_vencer', '2026-06-18');
    makeDashboardObligation('vencida', '2026-06-10', null, ['priority' => 'critical']);
    makeDashboardObligation('concluida', '2026-06-12');
    makeDashboardObligation('a_vencer', null);
    makeDashboardObligation('a_vencer', '2026-07-15', null, ['priority' => 'critical']);

    $summary = dashboardData()->summary();

    expect($summary)->toMatchArray([
        'total' => 6,
        'a_vencer' => 4,
        'vencida' => 1,
        'concluida' => 1,
        'sem_data' => 1,
        'vence_hoje' => 1,
        'proximos_7_dias' => 1,
        'proximos_30_dias' => 2,
        'vencidas_criticas' => 1,
        'alta_prioridade_proximos_7_dias' => 1,
    ]);
});

it('excludes finalized obligations from the pending date windows', function () {
    makeDashboardObligation('concluida', '2026-06-18');
    makeDashboardObligation('nao_aplicavel', '2026-06-19');

    $summary = dashboardData()->summary();

    expect($summary['vence_hoje'])->toBe(0)
        ->and($summary['proximos_7_dias'])->toBe(0)
        ->and($summary['proximos_30_dias'])->toBe(0);
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

it('ranks emissions by pending obligations and documentary backlog', function () {
    $busy = Emission::factory()->create(['name' => 'Emissão Movimentada']);
    makeDashboardObligation('vencida', '2026-06-10', $busy);
    $busyPendingEvidence = makeDashboardObligation('a_vencer', '2026-06-30', $busy);
    makeDashboardEvidence($busyPendingEvidence, ObligationEvidence::STATUS_PENDING);

    $quiet = Emission::factory()->create(['name' => 'Emissão Tranquila']);
    makeDashboardObligation('a_vencer', '2026-06-30', $quiet);

    $calm = Emission::factory()->create(['name' => 'Sem Pendência']);
    makeDashboardObligation('concluida', '2026-06-30', $calm);

    $top = dashboardData()->topEmissionsByPending();

    expect($top)->toHaveCount(2)
        ->and($top->first()->name)->toBe('Emissão Movimentada')
        ->and($top->first()->pending_obligations_count)->toBe(2)
        ->and($top->first()->overdue_obligations_count)->toBe(1)
        ->and($top->first()->pending_evidence_obligations_count)->toBe(1);
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

    $rejected = makeDashboardObligation('vencida', '2026-06-10', null, [
        'responsible_user_id' => $responsible->id,
    ]);
    makeDashboardEvidence($rejected, ObligationEvidence::STATUS_REJECTED);

    makeDashboardObligation('a_vencer', null, null, [
        'responsible_user_id' => null,
    ]);

    $concludedApproved = makeDashboardObligation('concluida', '2026-06-12', null, [
        'responsible_user_id' => $responsible->id,
    ]);
    makeDashboardEvidence($concludedApproved, ObligationEvidence::STATUS_APPROVED);

    makeDashboardObligation('concluida', '2026-06-14', null, [
        'responsible_user_id' => $responsible->id,
    ]);

    $summary = dashboardData()->summary();

    expect($summary)->toMatchArray([
        'em_analise' => 1,
        'sem_responsavel' => 2,
        'sem_data' => 1,
        'com_evidencia_aprovada' => 2,
        'com_evidencia_pendente' => 1,
        'com_evidencia_rejeitada' => 1,
        'sem_evidencia' => 2,
        'concluidas_com_evidencia_aprovada' => 1,
        'em_analise_com_evidencia_pendente' => 1,
        'concluidas_sem_evidencia_aprovada' => 1,
    ]);
});

it('applies shared dashboard filters to the summary and rankings', function () {
    $alpha = Emission::factory()->create(['name' => 'Emissão Alpha']);
    $beta = Emission::factory()->create(['name' => 'Emissão Beta']);

    makeDashboardObligation('vencida', '2026-06-10', $alpha, [
        'priority' => 'critical',
        'responsible_area' => 'Compliance',
    ]);
    makeDashboardObligation('a_vencer', '2026-06-25', $beta, [
        'priority' => 'high',
        'responsible_area' => 'Gestão',
    ]);

    $summary = dashboardData()->summary([
        'emission_id' => $alpha->id,
        'operational_focus' => 'critical_overdue',
    ]);

    $areas = dashboardData()->topAreasByPending(filters: [
        'operational_focus' => 'critical_overdue',
    ]);

    expect($summary)->toMatchArray([
        'total' => 1,
        'vencida' => 1,
        'vencidas_criticas' => 1,
    ])->and($areas->first()->label)->toBe('Compliance');
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

it('keeps finalized obligations out of the default operational query', function () {
    makeDashboardObligation('vencida', '2026-06-10');
    makeDashboardObligation('concluida', '2026-06-10');
    makeDashboardObligation('nao_aplicavel', '2026-06-10');

    expect(dashboardData()->operationalQuery()->count())->toBe(1);
});

it('configures eager loading on the obligation operational table widget', function () {
    $this->actingAs(makeAdminUser());

    $query = obligationDashboardTableQuery(
        Livewire::test(ObligationOperationalTableWidget::class)->instance(),
    );

    expect(array_keys($query->getEagerLoads()))->toContain(
        'emission',
        'responsibleUser',
    );
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

it('redirects the dashboard route for users without the dashboard permission', function () {
    $user = makeDashboardUserWithPermissions([
        AccessPermission::ObligationsView->value,
    ]);

    $this->actingAs($user);

    expect(ObligationDashboard::canAccess())->toBeFalse();

    $this->get(ObligationDashboard::getUrl(panel: 'admin'))
        ->assertRedirect();
});

it('keeps dashboard access available for super admins', function () {
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $this->actingAs($user);

    expect(ObligationDashboard::canAccess())->toBeTrue();
});

it('hides documentary widgets and concluded-without-approved rows from users without evidence permission', function () {
    $open = makeDashboardObligation('vencida', '2026-06-10');
    $concludedWithoutApproved = makeDashboardObligation('concluida', '2026-06-11');

    $user = makeDashboardUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ObligationDashboard::class)
        ->assertSuccessful()
        ->assertDontSee('Situação Documental')
        ->assertDontSee('Evidência Aprovada');

    Livewire::test(ObligationOperationalTableWidget::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$concludedWithoutApproved])
        ->assertDontSee('Situação documental')
        ->assertDontSee('Evidências anexadas');
});

it('ignores evidence-only operational focus filters forced by users without evidence permission', function () {
    $rejected = makeDashboardObligation('vencida', '2026-06-10', null, [
        'title' => 'Com rejeição',
    ]);
    makeDashboardEvidence($rejected, ObligationEvidence::STATUS_REJECTED);

    $withoutEvidence = makeDashboardObligation('a_vencer', '2026-06-25', null, [
        'title' => 'Sem evidência',
    ]);

    $user = makeDashboardUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('operational_focus', 'rejected_evidence')
        ->assertCanSeeTableRecords([$rejected, $withoutEvidence]);
});

it('renders the expanded operational and documentary KPIs on the dashboard widgets', function () {
    $concluded = makeDashboardObligation('concluida', '2026-06-12');
    makeDashboardEvidence($concluded, ObligationEvidence::STATUS_APPROVED);

    $pendingReview = makeDashboardObligation('em_analise', '2026-06-25');
    makeDashboardEvidence($pendingReview, ObligationEvidence::STATUS_PENDING);

    makeDashboardObligation('nao_aplicavel', '2026-06-26');
    makeDashboardObligation('a_vencer', '2026-07-10');

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOverviewStatsWidget::class)
        ->assertSee('Próximos 30 Dias')
        ->assertSee('Não Aplicáveis');

    Livewire::test(ObligationEvidenceOverviewStatsWidget::class)
        ->assertSee('Sem evidência aprovada')
        ->assertSee('Em análise com evidência pendente')
        ->assertSee('Concluídas com evidência aprovada');
});

it('lists operational obligations and filters them by emission', function () {
    $alpha = Emission::factory()->create(['name' => 'Emissão Alpha']);
    $beta = Emission::factory()->create(['name' => 'Emissão Beta']);

    $alphaObligation = makeDashboardObligation('vencida', '2026-06-10', $alpha);
    $betaObligation = makeDashboardObligation('a_vencer', '2026-06-30', $beta);
    $done = makeDashboardObligation('concluida', '2026-06-10', $alpha);
    makeDashboardEvidence($done, ObligationEvidence::STATUS_APPROVED);

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->assertCanSeeTableRecords([$alphaObligation, $betaObligation])
        ->assertCanNotSeeTableRecords([$done])
        ->filterTable('emission_id', $alpha->id)
        ->assertCanSeeTableRecords([$alphaObligation])
        ->assertCanNotSeeTableRecords([$betaObligation]);
});

it('filters the operational widget by status, responsible, area and priority', function () {
    $responsible = User::factory()->create(['name' => 'Fernanda']);
    $match = makeDashboardObligation('em_analise', '2026-06-25', null, [
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
        ->filterTable('status', 'em_analise')
        ->filterTable('responsible_user_id', $responsible->id)
        ->filterTable('responsible_area', 'Compliance')
        ->filterTable('priority', 'critical')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters the operational widget by documentary state and due window', function () {
    $approved = makeDashboardObligation('a_vencer', '2026-06-30');
    makeDashboardEvidence($approved, ObligationEvidence::STATUS_APPROVED);

    $pending = makeDashboardObligation('em_analise', '2026-06-25');
    makeDashboardEvidence($pending, ObligationEvidence::STATUS_PENDING);

    $rejected = makeDashboardObligation('a_vencer', '2026-06-26');
    makeDashboardEvidence($rejected, ObligationEvidence::STATUS_REJECTED);

    $withoutEvidence = makeDashboardObligation('vencida', '2026-06-10');

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('evidence_state', ObligationEvidence::STATUS_APPROVED)
        ->assertCanSeeTableRecords([$approved])
        ->assertCanNotSeeTableRecords([$pending, $rejected, $withoutEvidence]);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('evidence_state', ObligationEvidence::STATUS_PENDING)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$approved, $rejected, $withoutEvidence]);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('evidence_state', ObligationEvidence::STATUS_REJECTED)
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$approved, $pending, $withoutEvidence]);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('evidence_state', 'without_evidence')
        ->assertCanSeeTableRecords([$withoutEvidence])
        ->assertCanNotSeeTableRecords([$approved, $pending, $rejected]);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('due_window', 'overdue')
        ->assertCanSeeTableRecords([$withoutEvidence])
        ->assertCanNotSeeTableRecords([$approved, $pending, $rejected]);
});

it('filters the operational widget by source and quick operational focus', function () {
    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->create();

    $termGenerated = makeDashboardObligation('a_vencer', '2026-06-25', $emission, [
        'extracted_obligation_id' => $suggestion->id,
        'title' => 'Gerada pelo termo',
    ]);
    $criticalOverdue = makeDashboardObligation('vencida', '2026-06-10', null, [
        'priority' => 'critical',
        'title' => 'Critica vencida',
    ]);

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('source', 'term')
        ->assertCanSeeTableRecords([$termGenerated])
        ->assertCanNotSeeTableRecords([$criticalOverdue]);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->filterTable('operational_focus', 'critical_overdue')
        ->assertCanSeeTableRecords([$criticalOverdue])
        ->assertCanNotSeeTableRecords([$termGenerated]);
});

it('renders emission and obligation links in the operational widget', function () {
    $emission = Emission::factory()->create(['name' => 'Emissão Navegável']);
    $obligation = makeDashboardObligation('vencida', '2026-06-10', $emission, [
        'title' => 'Obrigação com link',
    ]);

    $this->actingAs(makeAdminUser());

    $component = Livewire::test(ObligationOperationalTableWidget::class);

    $component
        ->assertSee(EmissionResource::getUrl('edit', ['record' => $emission]))
        ->assertSee(EmissionResource::getUrl('edit', [
            'record' => $emission,
            'relation' => ObligationsRelationManager::class,
        ]))
        ->assertCanSeeTableRecords([$obligation]);
});

it('surfaces concluded obligations without approved evidence for evidence viewers', function () {
    $concludedWithoutApproved = makeDashboardObligation('concluida', '2026-06-14', null, [
        'title' => 'Concluída sem aprovada',
    ]);
    $concludedWithApproved = makeDashboardObligation('concluida', '2026-06-15', null, [
        'title' => 'Concluída com aprovada',
    ]);
    makeDashboardEvidence($concludedWithApproved, ObligationEvidence::STATUS_APPROVED);
    $open = makeDashboardObligation('a_vencer', '2026-06-25', null, [
        'title' => 'Aberta',
    ]);

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationOperationalTableWidget::class)
        ->assertCanSeeTableRecords([$open, $concludedWithoutApproved])
        ->assertCanNotSeeTableRecords([$concludedWithApproved])
        ->filterTable('operational_focus', 'completed_without_approved_evidence')
        ->assertCanSeeTableRecords([$concludedWithoutApproved])
        ->assertCanNotSeeTableRecords([$open, $concludedWithApproved]);
});

it('extends the emission obligations relation manager with operational filters and documentary columns', function () {
    $responsible = User::factory()->create(['name' => 'Fernanda']);
    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->create();

    $matching = Obligation::factory()->for($emission)->create([
        'title' => 'Obrigação filtrada',
        'status' => 'a_vencer',
        'due_date' => '2026-06-25',
        'priority' => 'high',
        'responsible_user_id' => $responsible->id,
        'responsible_area' => 'Compliance',
        'extracted_obligation_id' => $suggestion->id,
    ]);
    makeDashboardEvidence($matching, ObligationEvidence::STATUS_APPROVED);

    $other = Obligation::factory()->for($emission)->create([
        'title' => 'Outra obrigação',
        'status' => 'vencida',
        'due_date' => '2026-06-10',
        'priority' => 'critical',
        'responsible_area' => 'Gestão',
    ]);

    $this->actingAs(makeAdminUser());

    dashboardRelationManager($emission)
        ->assertSee('Situação documental')
        ->assertSee('Evidências anexadas')
        ->filterTable('responsible_user_id', $responsible->id)
        ->filterTable('responsible_area', 'Compliance')
        ->filterTable('priority', 'high')
        ->filterTable('due_window', 'next_7_days')
        ->filterTable('source', 'term')
        ->filterTable('evidence_state', ObligationEvidence::STATUS_APPROVED)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('keeps documentary fields hidden on the emission obligations relation manager without evidence permission', function () {
    $emission = Emission::factory()->create();
    $withoutResponsible = Obligation::factory()->for($emission)->create([
        'title' => 'Sem responsável',
        'status' => 'a_vencer',
        'responsible_user_id' => null,
    ]);
    $withResponsible = Obligation::factory()->for($emission)->create([
        'title' => 'Com responsável',
        'status' => 'a_vencer',
        'responsible_user_id' => User::factory()->create()->id,
    ]);

    $user = makeDashboardUserWithPermissions([
        AccessPermission::ObligationsView->value,
    ]);

    $this->actingAs($user);

    dashboardRelationManager($emission)
        ->assertSee('Modo consulta')
        ->assertDontSee('Situação documental')
        ->assertDontSee('Evidências anexadas')
        ->filterTable('has_responsible', false)
        ->assertCanSeeTableRecords([$withoutResponsible])
        ->assertCanNotSeeTableRecords([$withResponsible]);
});

it('prioritizes the main operational queues in the table ordering', function () {
    $responsible = User::factory()->create();

    $criticalOverdue = makeDashboardObligation('vencida', '2026-06-10', null, [
        'priority' => 'critical',
        'title' => 'Critica vencida',
        'responsible_user_id' => $responsible->id,
    ]);
    $regularOverdue = makeDashboardObligation('vencida', '2026-06-11', null, [
        'priority' => 'medium',
        'title' => 'Media vencida',
        'responsible_user_id' => $responsible->id,
    ]);
    makeDashboardEvidence($regularOverdue, ObligationEvidence::STATUS_APPROVED);
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
    $concludedWithoutApproved = makeDashboardObligation('concluida', '2026-07-11', null, [
        'title' => 'Concluida sem aprovada',
    ]);
    $withoutEvidence = makeDashboardObligation('a_vencer', '2026-07-13', null, [
        'title' => 'Sem evidencia',
    ]);
    $withoutResponsible = makeDashboardObligation('a_vencer', '2026-07-14', null, [
        'title' => 'Sem responsavel',
        'responsible_user_id' => null,
    ]);
    makeDashboardEvidence($withoutResponsible, ObligationEvidence::STATUS_APPROVED);

    $orderedIds = dashboardData()
        ->operationalQuery(includeConcludedWithoutApprovedEvidence: true)
        ->pluck('id')
        ->all();

    expect(array_slice($orderedIds, 0, 9))->toBe([
        $criticalOverdue->id,
        $dueToday->id,
        $nextWeek->id,
        $review->id,
        $rejected->id,
        $concludedWithoutApproved->id,
        $withoutEvidence->id,
        $withoutResponsible->id,
        $regularOverdue->id,
    ]);
});
