<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ObligationDashboard;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Filament\Widgets\Dashboard\DeadlinesWidget;
use App\Filament\Widgets\Dashboard\ExecutiveIndicatorsWidget;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Filament\Widgets\Dashboard\OperationalAlertsWidget;
use App\Filament\Widgets\Dashboard\RecentActivitiesWidget;
use App\Filament\Widgets\Dashboard\ShortcutsWidget;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\ObligationSeries;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('configures the cockpit as a responsive operational surface', function () {
    $dashboard = app(Dashboard::class);

    expect($dashboard->getSubheading())
        ->toBe('Prioridades, indicadores e prazos reunidos para uma leitura operacional mais rápida.')
        ->and($dashboard->getExtraBodyAttributes())
        ->toMatchArray(['class' => 'bsi-cockpit-page'])
        ->and($dashboard->getColumns())
        ->toBe([
            'default' => 1,
            'xl' => 12,
        ])
        ->and($dashboard->getWidgets())
        ->toBe([
            ShortcutsWidget::class,
            ExecutiveIndicatorsWidget::class,
            OperationalAlertsWidget::class,
            MyPendingsWidget::class,
            RecentActivitiesWidget::class,
            DeadlinesWidget::class,
        ]);
});

it('renders interactive cockpit controls with responsive deadline navigation', function () {
    $user = makeAdminUser();
    $user->assignRole('super-admin');
    $emission = Emission::factory()->active()->create();
    $overdueObligation = Obligation::factory()->for($emission)->create([
        'title' => 'Relatório mensal da operação',
        'responsible_user_id' => $user->id,
        'status' => 'vencida',
        'due_date' => now()->subDay(),
    ]);

    ObligationEvidence::factory()->create([
        'obligation_id' => $overdueObligation->id,
        'emission_id' => $emission->id,
    ]);

    $this->actingAs($user)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Cockpit Operacional')
        ->assertSee('Prioridades, indicadores e prazos reunidos para uma leitura operacional mais rápida.')
        ->assertSee('bsi-cockpit-page', false);

    Livewire::test(OperationalAlertsWidget::class)
        ->assertSee('Obrigação vencida')
        ->assertSee('Crítico')
        ->assertSee('Revisar obrigações')
        ->assertSee('aria-label="Abrir alerta Crítico: 1 Obrigação vencida"', false);

    Livewire::test(DeadlinesWidget::class)
        ->assertSee('data-active-deadline-groups="1"', false)
        ->assertSee('Vencida há 1 dia')
        ->assertSee('title="Relatório mensal da operação"', false)
        ->assertSee('aria-label="Abrir obrigação: Relatório mensal da operação"', false)
        ->assertDontSee('bsi-cockpit-scroll', false)
        ->assertDontSee('Deslize horizontalmente para consultar os demais prazos.')
        ->assertSee('fi-collapsible', false);

    Livewire::test(ExecutiveIndicatorsWidget::class)
        ->assertSee(ObligationDashboard::getUrl(), false)
        ->assertSee('wire:poll.60s', false);

    Livewire::test(RecentActivitiesWidget::class)
        ->assertSee('Obrigação');
});

it('presents recent activities with semantic hierarchy, periods and expandable descriptions', function () {
    $user = makeAdminUser();
    $user->givePermissionTo('audit.activities.view');
    $series = ObligationSeries::factory()->create();

    Activity::query()->delete();

    $longActivity = activity('obligation_series')
        ->causedBy($user)
        ->performedOn($series)
        ->event('series_awaiting_configuration')
        ->log('Série criada e mantida sem geração até a confirmação humana da regra executável');
    $longActivity->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();

    $systemActivity = activity('pu_indexes')
        ->event('index_synced')
        ->log('Índices sincronizados pelo processamento automático');
    $systemActivity->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $accessActivity = activity('login')
        ->causedBy($user)
        ->event('login')
        ->log('login');
    $accessActivity->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    $this->actingAs($user);

    Livewire::test(RecentActivitiesWidget::class)
        ->assertSee('Hoje')
        ->assertSee('Ontem')
        ->assertSee('Criação')
        ->assertSee('Acesso')
        ->assertSee('Sistema')
        ->assertSee('Acessou o sistema')
        ->assertSee('Série criada e mantida sem geração até a confirmação humana da regra executável')
        ->assertSee('bsi-activity-details', false)
        ->assertSee('Mostrar mais')
        ->assertSee('Mostrar menos')
        ->assertSee("aria-label=\"Registro relacionado: Série de Obrigações #{$series->id}\"", false)
        ->assertSee($user->name)
        ->assertSee('title=', false)
        ->assertSee(ActivityResource::getUrl('index', panel: 'admin'), false)
        ->assertSee('Ver todas');
});

it('keeps quick actions and empty alert feedback accessible', function () {
    $user = makeAdminUser();

    $this->actingAs($user);

    Livewire::test(ShortcutsWidget::class)
        ->assertSee('aria-label="Nova emissão — cadastrar uma nova operação"', false)
        ->assertSee('aria-label="Ver propostas — acompanhar o fluxo comercial"', false)
        ->assertSee('aria-label="Ver fundos — consultar cadastros financeiros"', false)
        ->assertSee('bsi-cockpit-action-arrow', false);

    Livewire::test(OperationalAlertsWidget::class)
        ->assertSee('Nenhum alerta operacional no momento.')
        ->assertSee('Todas as situações monitoradas estão dentro do esperado.')
        ->assertSee('cockpit-operational-alerts', false);
});

it('uses the full alert area for a single informational exception', function () {
    $user = makeAdminUser();
    Emission::factory()->count(2)->draft()->create();

    $this->actingAs($user);

    Livewire::test(OperationalAlertsWidget::class)
        ->assertSee('data-alert-count="1"', false)
        ->assertSee('2')
        ->assertSee('Emissões em rascunho')
        ->assertSee('Informativo')
        ->assertSee('Aguardando preenchimento para ativação.')
        ->assertSee('Ver emissões')
        ->assertSee('md:grid-cols-[auto_minmax(0,1fr)_auto]', false)
        ->assertSee('aria-label="Abrir alerta Informativo: 2 Emissões em rascunho"', false);
});

it('orders multiple operational exceptions by severity in a responsive grid', function () {
    $user = makeAdminUser();
    $user->assignRole('super-admin');
    $emission = Emission::factory()->active()->create();
    $obligation = Obligation::factory()->for($emission)->create([
        'status' => 'vencida',
        'due_date' => now()->subDay(),
    ]);

    ObligationEvidence::factory()->rejected($user)->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $emission->id,
    ]);

    $company = ProposalCompany::query()->create([
        'name' => 'Empresa sem responsável',
        'cnpj' => '12.345.678/0001-90',
    ]);
    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Contato sem responsável',
        'email' => 'contato-sem-responsavel@example.com',
    ]);
    Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => null,
        'status' => 'em_analise',
    ]);
    Emission::factory()->draft()->create();

    $this->actingAs($user);

    Livewire::test(OperationalAlertsWidget::class)
        ->assertSee('data-alert-count="4"', false)
        ->assertSee('xl:grid-cols-4', false)
        ->assertSeeInOrder(['Crítico', 'Importante', 'Atenção', 'Informativo'])
        ->assertSee('Revisar obrigações')
        ->assertSee('Revisar evidências')
        ->assertSee('Atribuir propostas')
        ->assertSee('Ver emissões');
});

it('consolidates the empty personal workload without redundant zero counters', function () {
    $user = makeAdminUser();

    Obligation::factory()->create([
        'responsible_user_id' => $user->id,
        'status' => 'nao_aplicavel',
    ]);

    $this->actingAs($user);

    Livewire::test(MyPendingsWidget::class)
        ->assertSee('data-pending-state="empty"', false)
        ->assertSee('Tudo em dia')
        ->assertSee('Seu fluxo pessoal está sob controle.')
        ->assertSee('Nenhuma obrigação pendente')
        ->assertSee('Nenhuma proposta aguardando sua ação')
        ->assertDontSee('text-3xl', false)
        ->assertDontSee('Ver obrigações')
        ->assertDontSee('Ver propostas');
});

it('prioritizes obligation deadlines and reports the full count beyond the preview', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->active()->create();

    Obligation::factory()->for($emission)->create([
        'title' => 'Obrigação vencida prioritária',
        'responsible_user_id' => $user->id,
        'status' => 'vencida',
        'due_date' => today()->subDay(),
    ]);
    Obligation::factory()->count(2)->for($emission)->create([
        'responsible_user_id' => $user->id,
        'status' => 'a_vencer',
        'due_date' => today(),
    ]);
    Obligation::factory()->count(2)->for($emission)->create([
        'responsible_user_id' => $user->id,
        'status' => 'a_vencer',
        'due_date' => today()->addWeek(),
    ]);

    $this->actingAs($user);

    Livewire::test(MyPendingsWidget::class)
        ->assertSee('data-pending-state="mixed"', false)
        ->assertSee('5 itens exigem acompanhamento.')
        ->assertSeeInOrder(['5', 'pendentes'])
        ->assertSee('1 vencida')
        ->assertSee('2 vencem hoje')
        ->assertSee('+ 2 obrigações adicionais')
        ->assertSee('Ver obrigações')
        ->assertSee(ObligationDashboard::getUrl(['filters' => ['responsible_user_id' => $user->id]]), false)
        ->assertSee('Nenhuma proposta aguarda sua ação.')
        ->assertDontSee('Tudo em dia');
});

it('compacts zero deadline windows and limits the neutral no-deadline inventory', function () {
    $user = makeAdminUser();
    $user->assignRole('super-admin');
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Alto Bellevue',
    ]);

    Obligation::factory()
        ->count(53)
        ->for($emission)
        ->state(new Sequence(
            fn (Sequence $sequence): array => [
                'title' => 'Sem prazo '.str_pad((string) ($sequence->index + 1), 2, '0', STR_PAD_LEFT),
                'status' => 'em_dia',
                'due_date' => null,
                'priority' => 'medium',
            ],
        ))
        ->create();

    $this->actingAs($user);

    Livewire::test(DeadlinesWidget::class)
        ->assertSee('data-deadline-health="clear"', false)
        ->assertSee('Nenhum vencimento crítico nos próximos 7 dias')
        ->assertSeeInOrder(['Vencidos', 'Vencem hoje', 'Próx. 3 dias', 'Próx. 7 dias'])
        ->assertSee('data-without-deadline-count="53"', false)
        ->assertSee('Itens que precisam de definição, sem criticidade de vencimento.')
        ->assertSee('title="Sem prazo 01"', false)
        ->assertSee('Sem prazo 05')
        ->assertDontSee('Sem prazo 06')
        ->assertSee('CRI Alto Bellevue')
        ->assertSee('Sem prazo definido')
        ->assertSee('Média')
        ->assertSee('Ver todos os 53')
        ->assertSee(ObligationDashboard::getUrl(['filters' => ['due_window' => 'without_due_date']]), false)
        ->assertDontSee('overflow-x-auto', false)
        ->assertDontSee('Deslize horizontalmente');
});

it('distributes active deadline windows by urgency and excludes finalized obligations', function () {
    $user = makeAdminUser();
    $user->assignRole('super-admin');
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Nimbus Corporate',
    ]);

    Obligation::factory()->for($emission)->create([
        'title' => 'Vencida mais antiga',
        'status' => 'vencida',
        'priority' => 'critical',
        'due_date' => today()->subDays(4),
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Vencida mais recente',
        'status' => 'vencida',
        'priority' => 'high',
        'due_date' => today()->subDay(),
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Entrega do dia',
        'status' => 'a_vencer',
        'due_date' => today(),
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Entrega imediata',
        'status' => 'a_vencer',
        'due_date' => today()->addDays(2),
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Entrega preventiva',
        'status' => 'a_vencer',
        'due_date' => today()->addDays(5),
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Definir calendário contratual',
        'status' => 'em_dia',
        'due_date' => null,
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Obrigação concluída invisível',
        'status' => 'concluida',
        'due_date' => today()->subDays(10),
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Obrigação não aplicável invisível',
        'status' => 'nao_aplicavel',
        'due_date' => null,
    ]);

    $this->actingAs($user);

    Livewire::test(DeadlinesWidget::class)
        ->assertSee('data-active-deadline-groups="4"', false)
        ->assertSeeInOrder(['Vencidos', 'Vencem hoje', 'Próx. 3 dias', 'Próx. 7 dias'])
        ->assertSeeInOrder(['Vencida mais antiga', 'Vencida mais recente'])
        ->assertSee('Vencida há 4 dias · '.today()->subDays(4)->format('d/m/Y'))
        ->assertSee('Vence hoje · '.today()->format('d/m/Y'))
        ->assertSee('Vence em 2 dias · '.today()->addDays(2)->format('d/m/Y'))
        ->assertSee('Vence em 5 dias · '.today()->addDays(5)->format('d/m/Y'))
        ->assertSee('data-without-deadline-count="1"', false)
        ->assertSee('Definir calendário contratual')
        ->assertSee('CRI Nimbus Corporate')
        ->assertDontSee('Obrigação concluída invisível')
        ->assertDontSee('Obrigação não aplicável invisível')
        ->assertDontSee('overflow-x-auto', false);
});

it('keeps proposals actionable when the obligation category is clear', function () {
    $user = makeAdminUser();
    $representative = ProposalRepresentative::factory()->for($user)->create();

    createCockpitProposal($representative, 'em_analise', 'Nimbus Agro');
    createCockpitProposal($representative, 'aguardando_informacoes', 'Nimbus Infra');
    createCockpitProposal($representative, 'aprovado', 'Nimbus Real Estate');

    $this->actingAs($user);

    Livewire::test(MyPendingsWidget::class)
        ->assertSee('data-pending-state="mixed"', false)
        ->assertSee('3 itens exigem acompanhamento.')
        ->assertSeeInOrder(['3', 'em andamento'])
        ->assertSee('Nenhuma pendência')
        ->assertSee('Não há obrigações que exijam sua ação.')
        ->assertSee('Nimbus Agro')
        ->assertSee('Em Análise Técnica')
        ->assertSee('Ver propostas')
        ->assertSee(ProposalResource::getUrl('index'), false)
        ->assertDontSee('Tudo em dia');
});

it('balances both active pending categories in the personal work panel', function () {
    $user = makeAdminUser();
    $representative = ProposalRepresentative::factory()->for($user)->create();
    $emission = Emission::factory()->active()->create();

    Obligation::factory()->for($emission)->create([
        'title' => 'Atualizar relatório financeiro',
        'responsible_user_id' => $user->id,
        'status' => 'a_vencer',
        'due_date' => today()->addDay(),
    ]);
    createCockpitProposal($representative, 'em_analise', 'Nimbus Energia');

    $this->actingAs($user);

    Livewire::test(MyPendingsWidget::class)
        ->assertSee('data-pending-state="active"', false)
        ->assertSee('2 itens exigem acompanhamento.')
        ->assertSee('Atualizar relatório financeiro')
        ->assertSee('Nimbus Energia')
        ->assertSee('Ver obrigações')
        ->assertSee('Ver propostas')
        ->assertDontSee('Nenhuma pendência')
        ->assertDontSee('Tudo em dia');
});

function createCockpitProposal(
    ProposalRepresentative $representative,
    string $status,
    string $companyName,
): Proposal {
    $company = ProposalCompany::query()->create([
        'name' => $companyName,
        'cnpj' => fake()->unique()->numerify('##.###.###/####-##'),
    ]);
    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
    ]);

    return Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => $status,
        'distributed_at' => now(),
    ]);
}
