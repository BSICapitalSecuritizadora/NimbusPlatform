<?php

use App\Enums\ProposalStatus;
use App\Filament\Pages\ProposalDashboard;
use App\Filament\Widgets\Proposals\ProposalAttentionTableWidget;
use App\Filament\Widgets\Proposals\ProposalOverviewStatsWidget;
use App\Filament\Widgets\Proposals\ProposalRecentTableWidget;
use App\Filament\Widgets\Proposals\ProposalRepresentativeLoadChartWidget;
use App\Filament\Widgets\Proposals\ProposalShortcutsWidget;
use App\Filament\Widgets\Proposals\ProposalStatusDistributionChartWidget;
use App\Filament\Widgets\Proposals\ProposalVolumeChartWidget;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\User;
use App\Support\Proposals\ProposalDashboardData;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('builds proposal dashboard metrics according to the authenticated user scope', function () {
    $representativeUser = User::factory()->create([
        'email' => 'dashboard-representante@example.com',
    ]);
    $representativeUser->assignRole('commercial-representative');

    $otherRepresentativeUser = User::factory()->create([
        'email' => 'dashboard-outro@example.com',
    ]);
    $otherRepresentativeUser->assignRole('commercial-representative');

    $adminUser = User::factory()->withTwoFactor()->create([
        'email' => 'dashboard-admin@example.com',
    ]);
    $adminUser->assignRole('admin');

    $representative = ProposalRepresentative::factory()->create([
        'user_id' => $representativeUser->id,
        'email' => $representativeUser->email,
        'queue_position' => 1,
    ]);
    $otherRepresentative = ProposalRepresentative::factory()->create([
        'user_id' => $otherRepresentativeUser->id,
        'email' => $otherRepresentativeUser->email,
        'queue_position' => 2,
    ]);

    $awaitingCompletion = createDashboardProposal($representative, ProposalStatus::AwaitingCompletion->value, updatedAt: now()->subDay());
    $staleReview = createDashboardProposal($representative, ProposalStatus::InReview->value, updatedAt: now()->subDays(5));
    $awaitingInformation = createDashboardProposal($representative, ProposalStatus::AwaitingInformation->value, updatedAt: now()->subDays(2));
    createDashboardProposal($representative, ProposalStatus::Approved->value, updatedAt: now()->subHours(10));

    createDashboardProposal($otherRepresentative, ProposalStatus::Completed->value, completedAt: now(), updatedAt: now()->subHours(4));
    createDashboardProposal($otherRepresentative, ProposalStatus::Rejected->value, updatedAt: now()->subHours(6));
    createDashboardProposal($otherRepresentative, ProposalStatus::InReview->value, updatedAt: now()->subHour());

    $dashboardData = app(ProposalDashboardData::class);

    $representativeSummary = $dashboardData->summary($representativeUser);
    $adminSummary = $dashboardData->summary($adminUser);

    expect($representativeSummary)->toMatchArray([
        'total' => 4,
        'awaiting_completion' => 1,
        'in_review' => 1,
        'awaiting_information' => 1,
        'approved' => 1,
        'rejected' => 0,
        'completed' => 0,
        'attention' => 3,
        'received_last_30_days' => 4,
        'active_pipeline' => 3,
        'conversion_rate' => 100.0,
    ])
        ->and($adminSummary)->toMatchArray([
            'total' => 7,
            'awaiting_completion' => 1,
            'in_review' => 2,
            'awaiting_information' => 1,
            'approved' => 1,
            'rejected' => 1,
            'completed' => 1,
            'attention' => 3,
            'received_last_30_days' => 7,
            'active_pipeline' => 4,
            'conversion_rate' => 66.7,
        ])
        ->and($dashboardData->attentionQuery($representativeUser)->pluck('id')->all())
        ->toBe([$staleReview->id, $awaitingInformation->id, $awaitingCompletion->id])
        ->and($dashboardData->attentionSeverity($staleReview))->toBe('critical')
        ->and($dashboardData->attentionSeverityLabel($staleReview))->toBe('SLA Crítico')
        ->and($dashboardData->attentionSeverityColor($staleReview))->toBe('danger')
        ->and($dashboardData->attentionSeverity($awaitingInformation))->toBe('attention')
        ->and($dashboardData->attentionSeverityLabel($awaitingInformation))->toBe('Atenção')
        ->and($dashboardData->attentionSeverityColor($awaitingInformation))->toBe('warning')
        ->and($dashboardData->attentionDiagnosis($staleReview))->toContain('Parado em análise')
        ->and($dashboardData->attentionDiagnosis($awaitingInformation))->toContain('Aguardando cliente')
        ->and(array_sum($dashboardData->monthlyVolume(6, $adminUser)['received']))
        ->toBe(7)
        ->and(array_sum($dashboardData->monthlyVolume(6, $adminUser)['completed']))
        ->toBe(1)
        ->and($dashboardData->monthlyVolumeMetrics(6, $adminUser))
        ->toMatchArray([
            'total_received' => 7,
            'total_completed' => 1,
            'conversion_rate' => 14.3,
            'has_activity' => true,
        ])
        ->and($dashboardData->statusDistributionDetails($adminUser))
        ->toMatchArray([
            'total' => 7,
            'inactive_items_count' => 0,
        ])
        ->and($dashboardData->representativeLoadDetails())
        ->toMatchArray([
            'total_representatives' => 2,
            'total_active_proposals' => 5,
            'average_load' => 2.5,
            'has_activity' => true,
        ])
        ->and($dashboardData->representativeLoad()->pluck('active_proposals_count', 'name')->all())
        ->toBe([
            $representative->name => 4,
            $otherRepresentative->name => 1,
        ]);
});

it('renders the proposal dashboard only for users with proposal access', function () {
    $representativeUser = User::factory()->withTwoFactor()->create([
        'email' => 'painel-propostas@example.com',
    ]);
    $representativeUser->assignRole('commercial-representative');

    $representative = ProposalRepresentative::factory()->create([
        'user_id' => $representativeUser->id,
        'email' => $representativeUser->email,
    ]);

    createDashboardProposal($representative, ProposalStatus::InReview->value, updatedAt: now()->subDays(4));

    $this->actingAs($representativeUser);

    expect($representativeUser->hasRole('commercial-representative'))->toBeTrue()
        ->and(ProposalDashboard::canAccess())->toBeTrue();

    $this
        ->get(ProposalDashboard::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Painel de Propostas')
        ->assertSee('bsi-cockpit-page', false);

    Livewire::test(ProposalShortcutsWidget::class)
        ->assertSee('Carteira de Propostas')
        ->assertSee('Pendências e SLA')
        ->assertSee('Taxa de Deferimento');

    Livewire::test(ProposalOverviewStatsWidget::class)
        ->assertSee('Total de Propostas')
        ->assertSee('Fila Ativa')
        ->assertSee('Taxa de Conversão')
        ->assertSee('Atenção & SLA Crítico');

    Livewire::test(ProposalVolumeChartWidget::class)
        ->assertSee('Evolução e Formalização de Propostas')
        ->assertSee('Total Captado')
        ->assertSee('Formalizações')
        ->assertSee('Conversão')
        ->assertSee('Mês Destaque');

    Livewire::test(ProposalStatusDistributionChartWidget::class)
        ->assertSee('Composição da Carteira')
        ->assertSee('Em Carteira')
        ->assertSee('Em Análise Técnica');

    Livewire::test(ProposalAttentionTableWidget::class)
        ->assertSee('Propostas com Atenção / SLA Crítico');

    Livewire::test(ProposalRecentTableWidget::class)
        ->assertSee('Entradas e Movimentações Recentes');

    $adminUser = User::factory()->withTwoFactor()->create([
        'email' => 'admin-carga-fila@example.com',
    ]);
    $adminUser->assignRole('admin');

    $this->actingAs($adminUser);

    Livewire::test(ProposalRepresentativeLoadChartWidget::class)
        ->assertSee('Carga Operacional da Fila Comercial')
        ->assertSee('Gerenciar Fila')
        ->assertSee($representative->name);

    $userWithoutPermission = User::factory()->create([
        'email' => 'sem-acesso-propostas@example.com',
    ]);

    $this->actingAs($userWithoutPermission)
        ->get(ProposalDashboard::getUrl(panel: 'admin'))
        ->assertForbidden();
});

function createDashboardProposal(
    ProposalRepresentative $representative,
    string $status,
    ?CarbonInterface $createdAt = null,
    ?CarbonInterface $completedAt = null,
    ?CarbonInterface $updatedAt = null,
): Proposal {
    $timestamp = $createdAt ?? now();

    $company = ProposalCompany::query()->create([
        'name' => "Empresa Dashboard {$representative->id} {$status} {$timestamp->format('Hisv')}",
        'cnpj' => fake()->unique()->numerify('##.###.###/####-##'),
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
    ]);

    $proposal = Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => $status,
        'distribution_sequence' => fake()->numberBetween(1, 999),
        'distributed_at' => $timestamp,
        'completed_at' => $completedAt,
        'created_at' => $timestamp,
        'updated_at' => $updatedAt ?? $timestamp,
    ]);

    if ($updatedAt || $createdAt || $completedAt) {
        $proposal->timestamps = false;
        $proposal->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $updatedAt ?? $timestamp,
            'completed_at' => $completedAt,
        ])->save();
        $proposal->timestamps = true;
    }

    return $proposal->fresh();
}
