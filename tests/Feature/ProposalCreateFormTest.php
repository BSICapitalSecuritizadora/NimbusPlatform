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

it('grants the unsafe-eval csp source the livewire form needs to evaluate wire directives', function () {
    $response = $this->get(route('proposal.create'));

    $response->assertSuccessful();

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("'unsafe-eval'");
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

    expect($component->effects)->not->toHaveKey('redirectUsingNavigate');

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

it('blocks the eleventh proposal from the same IP when identities are varied', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $stateForAttempt = function (int $attempt) use ($sector): array {
        $state = proposalCreateFormState($sector);
        $state['cnpj'] = sprintf('12.345.%03d/0001-%02d', $attempt, $attempt);
        $state['companyName'] = "Construtora Rate Limit {$attempt}";
        $state['email'] = "rate-limit-{$attempt}@example.com";

        return $state;
    };

    foreach (range(1, 10) as $attempt) {
        submitProposalCreateForm($stateForAttempt($attempt));
    }

    $blockedState = $stateForAttempt(11);
    fakeProposalCreateLookups($blockedState);

    $component = Livewire::test(CreateProposalForm::class);

    foreach ($blockedState as $property => $value) {
        $component->set("form.{$property}", $value);
    }

    $component
        ->call('save')
        ->assertHasErrors(['submission'])
        ->assertNoRedirect();

    expect(Proposal::query()->count())->toBe(10);

    Mail::assertSent(ProposalContinuationLinkMail::class, 10);
});

it('offers the active sectors provisioned by the migrations, ordered by name', function () {
    ProposalSector::query()->create(['name' => 'Infraestrutura']);
    ProposalSector::query()->create(['name' => 'Setor Descontinuado', 'is_active' => false]);

    $response = $this->get(route('proposal.create'));

    $response->assertSuccessful()
        ->assertSee('Setor de Atuação')
        ->assertSeeInOrder(['Agronegócio', 'Imobiliário', 'Infraestrutura', 'Outros'])
        ->assertDontSee('Setor Descontinuado')
        ->assertDontSee(CreateProposalForm::NO_SECTORS_MESSAGE);

    expect(Livewire::test(CreateProposalForm::class)->viewData('sectors')->pluck('name')->all())
        ->toBe(['Agronegócio', 'Imobiliário', 'Infraestrutura', 'Outros']);
});

it('rejects a sector that is no longer active', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Setor Descontinuado', 'is_active' => false]);

    Livewire::test(CreateProposalForm::class)
        ->set('form.sectorId', (string) $sector->id)
        ->call('save')
        ->assertHasErrors(['form.sectorId' => ['exists']])
        ->assertSee('Selecione um setor de atuação válido.')
        ->assertNoRedirect();

    expect(Proposal::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('explains the empty state and blocks submission when no sector is available', function () {
    ProposalSector::query()->delete();

    $this->get(route('proposal.create'))
        ->assertSuccessful()
        ->assertSee('Setor de Atuação')
        ->assertSee(CreateProposalForm::NO_SECTORS_MESSAGE);

    Livewire::test(CreateProposalForm::class)
        ->call('save')
        ->assertHasErrors(['submission'])
        ->assertNoRedirect();

    expect(Proposal::query()->count())->toBe(0);
});

it('validates the required fields before saving the proposal', function () {
    Mail::fake();

    Livewire::test(CreateProposalForm::class)
        ->call('save')
        ->assertHasErrors([
            'form.cnpj' => ['required'],
            'form.companyName' => ['required'],
            'form.sectorId' => ['required'],
            'form.postalCode' => ['required'],
            'form.street' => ['required'],
            'form.addressNumber' => ['required'],
            'form.neighborhood' => ['required'],
            'form.city' => ['required'],
            'form.state' => ['required'],
            'form.contactName' => ['required'],
            'form.email' => ['required'],
            'form.personalPhone' => ['required'],
        ])
        ->assertSee('Revise os campos destacados antes de continuar.')
        ->assertSee('O CNPJ da empresa é obrigatório.')
        ->assertSee('O e-mail de contato é obrigatório.')
        ->assertNoRedirect();

    expect(Proposal::query()->count())->toBe(0);

    Mail::assertNothingSent();
});
