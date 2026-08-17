<?php

use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Livewire\Forms\CreateProposalFormObject;
use App\Livewire\Proposals\CreateProposalForm;
use App\Models\ProposalSector;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

function puValidationSpreadsheetPath(string $keyword): string
{
    try {
        return app(PuValidationSpreadsheetLocatorService::class)->findByKeyword($keyword);
    } catch (InvalidArgumentException) {
        test()->markTestSkipped(
            "A planilha operacional de validação [{$keyword}] não está disponível neste ambiente.",
        );
    }
}

/**
 * @return array<string, array<int>|bool|string>
 */
function proposalCreateFormState(ProposalSector $sector, int $index = 1): array
{
    return [
        'cnpj' => validTestCnpj($index),
        'companyName' => "Construtora {$index}",
        'stateRegistration' => "12345{$index}",
        'website' => "https://construtora{$index}.example.com",
        'sectorIds' => [(int) $sector->id],
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
        'isWhatsapp' => true,
        'whatsappContactConsent' => true,
        'companyPhone' => '(11) 4000-0000',
        'jobTitle' => 'Diretor',
        'observations' => 'Observações iniciais.',
    ];
}

function validTestCnpj(int $index): string
{
    $base = '12345678'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);

    foreach ([5, 6] as $initialWeight) {
        $sum = 0;
        $weight = $initialWeight;

        foreach (str_split($base) as $digit) {
            $sum += (int) $digit * $weight;
            $weight = $weight === 2 ? 9 : $weight - 1;
        }

        $remainder = $sum % 11;
        $base .= (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }

    return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $base) ?: $base;
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

function makeAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
