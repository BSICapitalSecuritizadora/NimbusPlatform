<?php

use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function headerTestProposal(string $status = 'em_analise'): Proposal
{
    $company = ProposalCompany::query()->create([
        'name' => 'Headinvest Asset Management Ltda',
        'cnpj' => fake()->unique()->numerify('##.###.###/####-##'),
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
    ]);

    $representative = ProposalRepresentative::factory()->create([
        'name' => 'Thales Elias Juan Freitas',
    ]);

    return Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => $status,
        'distribution_sequence' => fake()->numberBetween(1, 999),
        'distributed_at' => now(),
    ]);
}

it('renders the proposal header with status badge and structured metadata', function () {
    $admin = makeAdminUser();
    $proposal = headerTestProposal();

    $html = Livewire::actingAs($admin)
        ->test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Headinvest Asset Management Ltda')
        ->assertSee('Aprovar Proposta')
        ->assertSee('Solicitar Complemento')
        ->assertSee('Mais ações')
        ->assertSee('Recusar Proposta')
        ->html(true);

    expect($html)
        ->toContain('bsi-proposal-status')
        ->toContain('Em Análise Técnica')
        ->toContain('Proposta #'.$proposal->id)
        ->toContain('Responsável:')
        ->toContain('Thales Elias Juan Freitas')
        ->toContain('Atualizado:')
        ->toContain('agora')
        ->not->toContain('Em Análise Técnica · Responsável');
});

it('gives approve visual primacy and nests reject inside the actions menu', function () {
    $admin = makeAdminUser();
    $proposal = headerTestProposal();

    $html = Livewire::actingAs($admin)
        ->test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertSuccessful()
        ->html(true);

    $actionsPos = strpos($html, 'fi-header-actions');
    $panelPos = strpos($html, 'fi-dropdown-panel');
    $rejectPos = strpos($html, 'Recusar Proposta');

    expect($html)->toContain('fi-color-warning')
        ->and($actionsPos)->not->toBeFalse()
        ->and($panelPos)->not->toBeFalse()
        ->and($rejectPos)->not->toBeFalse()
        ->and($rejectPos)->toBeGreaterThan($panelPos)
        ->and(substr($html, $actionsPos, $rejectPos - $actionsPos))->not->toContain('Recusar Proposta');
});

it('keeps the nested reject flow with justification intact', function () {
    $admin = makeAdminUser();
    $proposal = headerTestProposal();

    Livewire::actingAs($admin)
        ->test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertSuccessful()
        ->callAction('reject', data: ['note' => 'Fora do perfil de crédito.'])
        ->assertSuccessful();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Rejected->value);
});
