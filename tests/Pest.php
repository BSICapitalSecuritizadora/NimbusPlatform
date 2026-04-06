<?php

use App\Livewire\Forms\CreateProposalFormObject;
use App\Livewire\Proposals\CreateProposalForm;
use App\Models\ProposalSector;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * @return array<string, array<int>|bool|string>
 */
function proposalCreateFormState(ProposalSector $sector, int $index = 1): array
{
    return [
        'cnpj' => sprintf('12.345.67%d/0001-%02d', $index, $index),
        'companyName' => "Construtora {$index}",
        'stateRegistration' => "12345{$index}",
        'website' => "https://construtora{$index}.example.com",
        'sectorId' => (string) $sector->id,
        'postalCode' => '04567-000',
        'street' => 'Rua das Torres',
        'addressNumber' => (string) (100 + $index),
        'addressComplement' => 'Sala 10',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'contactName' => "Contato {$index}",
        'email' => "contato{$index}@example.com",
        'personalPhone' => '(11) 99999-0000',
        'hasWhatsapp' => true,
        'companyPhone' => '(11) 4000-0000',
        'jobTitle' => 'Diretor',
        'observations' => 'Observações iniciais.',
    ];
}

/**
 * @param  array<string, array<int>|bool|string>  $state
 */
function fakeProposalCreateLookups(array $state): void
{
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => $state['companyName'],
            'estabelecimento' => [
                'inscricoes_estaduais' => [
                    ['inscricao_estadual' => $state['stateRegistration']],
                ],
                'cep' => preg_replace('/\D/', '', (string) $state['postalCode']),
                'logradouro' => $state['street'],
                'numero' => $state['addressNumber'],
                'complemento' => $state['addressComplement'],
                'bairro' => $state['neighborhood'],
                'cidade' => ['nome' => $state['city']],
                'estado' => ['sigla' => $state['state']],
                'site' => preg_replace('/^https?:\/\//', '', (string) $state['website']),
            ],
        ]),
        'https://viacep.com.br/ws/*' => Http::response([
            'logradouro' => $state['street'],
            'bairro' => $state['neighborhood'],
            'localidade' => $state['city'],
            'uf' => $state['state'],
        ]),
    ]);
}

/**
 * @param  array<string, array<int>|bool|string>  $state
 */
function submitProposalCreateForm(array $state): void
{
    fakeProposalCreateLookups($state);

    $component = Livewire::test(CreateProposalForm::class);

    foreach ($state as $property => $value) {
        if (! property_exists(CreateProposalFormObject::class, $property)) {
            continue;
        }

        $component->set("form.{$property}", $value);
    }

    $component
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('proposal.create'));
}

function submitInitialProposalThroughComponent(ProposalSector $sector, int $index = 1): void
{
    submitProposalCreateForm(proposalCreateFormState($sector, $index));
}
