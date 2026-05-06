<?php

use App\Enums\ProposalStatus;
use App\Filament\Pages\ProposalDashboard;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\User;
use App\Support\Proposals\ProposalDashboardData;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ])
        ->and($dashboardData->attentionQuery($representativeUser)->pluck('id')->all())
        ->toBe([$awaitingInformation->id, $awaitingCompletion->id, $staleReview->id])
        ->and(array_sum($dashboardData->monthlyVolume(6, $adminUser)['received']))
        ->toBe(7)
        ->and(array_sum($dashboardData->monthlyVolume(6, $adminUser)['completed']))
        ->toBe(1)
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
        ->assertSee('Painel de propostas');

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
