<?php

use App\Actions\Proposals\CreateProposalContinuationAccess;
use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\RelationManagers\ProposalContinuationAccessRelationManager;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalRepresentative;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeProposalForAccessControl(): Proposal
{
    $company = ProposalCompany::query()->create([
        'name' => 'Empresa Controle de Acesso',
        'cnpj' => validTestCnpj(950),
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Thiago Rodrigo Bryan Fogaça',
        'email' => 'thiago-fogaca99@example.com',
    ]);

    $representative = ProposalRepresentative::factory()->create();

    return Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => ProposalStatus::InReview->value,
    ]);
}

function makeContinuationAccessFor(Proposal $proposal): ProposalContinuationAccess
{
    ['access' => $access] = app(CreateProposalContinuationAccess::class)->handle($proposal);

    return $access;
}

function accessControlTable(Proposal $proposal): Testable
{
    return Livewire::test(ProposalContinuationAccessRelationManager::class, [
        'ownerRecord' => $proposal,
        'pageClass' => ViewProposal::class,
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('apresenta o link de forma compacta, sem expor a URL assinada como texto', function () {
    $proposal = makeProposalForAccessControl();
    makeContinuationAccessFor($proposal);

    accessControlTable($proposal)
        ->assertSuccessful()
        ->assertSee('Link seguro gerado')
        ->assertDontSee('Copiar link completo');
});

it('agrupa destinatário e e-mail em uma única coluna', function () {
    $proposal = makeProposalForAccessControl();
    makeContinuationAccessFor($proposal);

    accessControlTable($proposal)
        ->assertSee('Thiago Rodrigo Bryan Fogaça')
        ->assertSee('thiago-fogaca99@example.com')
        ->assertSee('Destinatário')
        ->assertDontSee('Usuário Solicitante')
        ->assertDontSee('E-mail de Destino');
});

it('exibe validade relativa e situação estruturadas do domínio', function () {
    $proposal = makeProposalForAccessControl();
    makeContinuationAccessFor($proposal);

    accessControlTable($proposal)
        ->assertSee('Expira em')
        ->assertSee('Enviado');
});

it('exibe o código gerado de forma acessível', function () {
    $proposal = makeProposalForAccessControl();
    $access = makeContinuationAccessFor($proposal);

    accessControlTable($proposal)
        ->assertSee($access->decrypted_code);
});

it('disponibiliza a URL completa apenas na ação de detalhes', function () {
    $proposal = makeProposalForAccessControl();
    $access = makeContinuationAccessFor($proposal);

    accessControlTable($proposal)
        ->assertActionVisible(TestAction::make('view_link')->table($access));

    $html = view('filament.proposals.continuation-access-link', [
        'url' => $access->generated_url,
    ])->render();

    expect($html)->toContain(e($access->generated_url))
        ->toContain('Copiar link completo');
});

it('mantém a ação de abrir o link em nova aba', function () {
    $proposal = makeProposalForAccessControl();
    $access = makeContinuationAccessFor($proposal);

    accessControlTable($proposal)
        ->assertActionVisible(TestAction::make('open_link')->table($access))
        ->assertActionHasUrl(
            TestAction::make('open_link')->table($access),
            $access->generated_url,
        );
});

it('exibe estado vazio orientado à ação quando não há acessos', function () {
    $proposal = makeProposalForAccessControl();

    accessControlTable($proposal)
        ->assertSee('Nenhum link de acesso gerado')
        ->assertSee('Gere ou envie o primeiro acesso para disponibilizar o preenchimento ao destinatário.');
});
