<?php

use App\Filament\NimbusWidgets\NimbusRecentSubmissions;
use App\Filament\Resources\Nimbus\Submissions\Pages\ListSubmissions;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\RelationManagers\ProjectRelationManager;
use App\Filament\Resources\Proposals\RelationManagers\ProposalAssignmentRelationManager;
use App\Filament\Resources\Proposals\RelationManagers\ProposalContinuationAccessRelationManager;
use App\Filament\Resources\Proposals\RelationManagers\ProposalStatusHistoryRelationManager;
use App\Filament\Widgets\Proposals\ProposalAttentionTableWidget;
use App\Filament\Widgets\Proposals\ProposalRecentTableWidget;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('configures eager loading on the proposals list table', function () {
    $this->actingAs(makeAdminUser());

    $query = filamentTableQuery(
        Livewire::test(ListProposals::class)->instance(),
    );

    expect(array_keys($query->getEagerLoads()))->toContain(
        'company',
        'contact',
        'representative',
        'latestContinuationAccess',
        'latestStatusHistory.changedByUser',
    );
});

it('configures eager loading on proposal relation managers that render nested relations', function () {
    $this->actingAs(makeAdminUser());

    $proposal = makeProposalForFilament();

    $assignmentQuery = filamentTableQuery(
        Livewire::test(ProposalAssignmentRelationManager::class, [
            'ownerRecord' => $proposal,
            'pageClass' => ViewProposal::class,
        ])->instance(),
    );

    $continuationAccessQuery = filamentTableQuery(
        Livewire::test(ProposalContinuationAccessRelationManager::class, [
            'ownerRecord' => $proposal,
            'pageClass' => ViewProposal::class,
        ])->instance(),
    );

    $statusHistoryQuery = filamentTableQuery(
        Livewire::test(ProposalStatusHistoryRelationManager::class, [
            'ownerRecord' => $proposal,
            'pageClass' => ViewProposal::class,
        ])->instance(),
    );

    $projectQuery = filamentTableQuery(
        Livewire::test(ProjectRelationManager::class, [
            'ownerRecord' => $proposal,
            'pageClass' => ViewProposal::class,
        ])->instance(),
    );

    expect(array_keys($assignmentQuery->getEagerLoads()))->toContain('representative')
        ->and(array_keys($continuationAccessQuery->getEagerLoads()))->toContain('proposal.contact')
        ->and(array_keys($statusHistoryQuery->getEagerLoads()))->toContain('changedByUser')
        ->and(array_keys($projectQuery->getEagerLoads()))->toContain(
            'characteristics.unitTypes',
            'indicators',
        );
});

it('configures eager loading on proposal dashboard tables', function () {
    $this->actingAs(makeAdminUser());

    $recentQuery = filamentTableQuery(
        Livewire::test(ProposalRecentTableWidget::class)->instance(),
    );

    $attentionQuery = filamentTableQuery(
        Livewire::test(ProposalAttentionTableWidget::class)->instance(),
    );

    expect(array_keys($recentQuery->getEagerLoads()))->toContain(
        'company',
        'representative',
        'latestStatusHistory.changedByUser',
    )->and(array_keys($attentionQuery->getEagerLoads()))->toContain(
        'company',
        'representative',
        'latestStatusHistory.changedByUser',
    );
});

it('configures eager loading on the submissions list table and recent widget', function () {
    $this->actingAs(makeAdminUser());

    $listQuery = filamentTableQuery(
        Livewire::test(ListSubmissions::class)->instance(),
    );

    $widgetQuery = filamentTableQuery(
        Livewire::test(NimbusRecentSubmissions::class)->instance(),
    );

    expect(array_keys($listQuery->getEagerLoads()))->toContain('portalUser')
        ->and(array_keys($widgetQuery->getEagerLoads()))->toContain('portalUser');
});

function makeAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function makeProposalForFilament(): Proposal
{
    $company = ProposalCompany::query()->create([
        'name' => 'Empresa Filament',
        'cnpj' => '12.345.678/0001-90',
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Contato Filament',
        'email' => 'contato.filament@example.com',
    ]);

    $representative = ProposalRepresentative::factory()->create([
        'email' => 'representante.filament@example.com',
    ]);

    return Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => Proposal::STATUS_IN_REVIEW,
        'distribution_sequence' => 1,
        'distributed_at' => now(),
    ]);
}

function filamentTableQuery(object $component): Builder
{
    if (function_exists('invade')) {
        /** @var Table $table */
        $table = invade($component)->getTable();

        return $table->getQuery();
    }

    $method = new \ReflectionMethod($component, 'getTable');
    $method->setAccessible(true);

    /** @var Table $table */
    $table = $method->invoke($component);

    return $table->getQuery();
}
