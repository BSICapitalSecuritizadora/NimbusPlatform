<?php

use App\Enums\ProposalStatus;
use App\Livewire\Proposals\CreateProposalForm;
use App\Mail\ProposalContinuationLinkMail;
use App\Models\Proposal;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the proposal creation page through the full-page livewire component', function () {
    $this->get(route('proposal.create'))
        ->assertSuccessful()
        ->assertSeeLivewire(CreateProposalForm::class)
        ->assertSee('Envie sua Proposta');
});

it('keeps the legacy public proposal url working', function () {
    $this->get('/proposta')
        ->assertRedirect(route('proposal.create'));
});

it('hydrates company and address fields from cnpj and postal code lookups', function () {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Construtora Horizonte',
            'estabelecimento' => [
                'inscricoes_estaduais' => [
                    ['inscricao_estadual' => '123456789'],
                ],
                'cep' => '04567000',
                'logradouro' => 'Avenida Brigadeiro',
                'numero' => '1500',
                'complemento' => 'Conjunto 12',
                'bairro' => 'Jardins',
                'cidade' => ['nome' => 'São Paulo'],
                'estado' => ['sigla' => 'SP'],
                'site' => 'horizonte.example.com',
            ],
        ]),
        'https://viacep.com.br/ws/*' => Http::response([
            'logradouro' => 'Rua Faria Lima',
            'bairro' => 'Itaim Bibi',
            'localidade' => 'São Paulo',
            'uf' => 'SP',
        ]),
    ]);

    Livewire::test(CreateProposalForm::class)
        ->set('form.cnpj', '12.345.678/0001-90')
        ->assertSet('form.companyName', 'Construtora Horizonte')
        ->assertSet('form.stateRegistration', '123456789')
        ->assertSet('form.website', 'https://horizonte.example.com')
        ->assertSet('form.postalCode', '04567-000')
        ->assertSet('form.street', 'Avenida Brigadeiro')
        ->assertSet('form.addressNumber', '1500')
        ->assertSet('form.addressComplement', 'Conjunto 12')
        ->assertSet('form.neighborhood', 'Jardins')
        ->assertSet('form.city', 'São Paulo')
        ->assertSet('form.state', 'SP')
        ->set('form.postalCode', '04567-000')
        ->assertSet('form.street', 'Rua Faria Lima')
        ->assertSet('form.neighborhood', 'Itaim Bibi')
        ->assertSet('form.city', 'São Paulo')
        ->assertSet('form.state', 'SP');
});

it('stores the initial proposal through the livewire component and sends the continuation link', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $state = proposalCreateFormState($sector);

    fakeProposalCreateLookups($state);

    $component = Livewire::test(CreateProposalForm::class);

    foreach ($state as $property => $value) {
        $component->set("form.{$property}", $value);
    }

    $component
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('proposal.create'));

    $proposal = Proposal::query()
        ->with(['company.sectors', 'contact', 'statusHistories', 'latestContinuationAccess'])
        ->firstOrFail();

    expect($proposal->status)->toBe(ProposalStatus::AwaitingCompletion->value)
        ->and($proposal->company->name)->toBe($state['companyName'])
        ->and($proposal->company->cnpj)->toBe($state['cnpj'])
        ->and($proposal->company->site)->toBe($state['website'])
        ->and($proposal->company->sectors->pluck('id')->all())->toBe([$sector->id])
        ->and($proposal->contact->name)->toBe($state['contactName'])
        ->and($proposal->contact->email)->toBe($state['email'])
        ->and($proposal->contact->whatsapp)->toBeTrue()
        ->and($proposal->latestContinuationAccess)->not->toBeNull()
        ->and($proposal->statusHistories)->toHaveCount(1)
        ->and($proposal->assigned_representative_id)->not->toBeNull();

    Mail::assertSent(ProposalContinuationLinkMail::class);
});

it('validates the required fields before saving the proposal', function () {
    Livewire::test(CreateProposalForm::class)
        ->call('save')
        ->assertHasErrors([
            'form.cnpj',
            'form.companyName',
            'form.sectorId',
            'form.postalCode',
            'form.street',
            'form.addressNumber',
            'form.neighborhood',
            'form.city',
            'form.state',
            'form.contactName',
            'form.email',
            'form.personalPhone',
        ]);
});
