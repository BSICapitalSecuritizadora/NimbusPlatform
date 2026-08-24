<?php

use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Filament\Resources\Emissions\Schemas\EmissionConstructionsStep;
use App\Livewire\Forms\CreateProposalFormObject;
use App\Livewire\Proposals\CreateProposalForm;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use App\Models\ProposalSector;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Caminho físico, dentro do disco isolado da suíte, para um arquivo que o
 * componente sob teste precisa abrir do filesystem -- planilhas que o parser lê
 * do disco, CSVs de importação, gabaritos.
 *
 * Substitui `tempnam(sys_get_temp_dir(), $prefixo).$extensao`, que tinha três
 * defeitos: deixava dois arquivos por chamada (o do `tempnam`, vazio, e o do
 * sufixo), não tinha limpeza, e apontava para um diretório compartilhado por
 * todos os processos da máquina. Aqui o arquivo nasce dentro da raiz falsa do
 * disco `local`, que o TestCase apaga ao fim de cada teste e que já é
 * separada por processo sob `--parallel`.
 *
 * O nome segue uma sequência, não um valor aleatório: o conteúdo do arquivo é
 * que precisa ser determinístico para checksum, e um contador mantém o nome
 * reproduzível de uma execução para a outra.
 */
function temporaryTestFilePath(string $prefix, string $extension = 'xlsx'): string
{
    static $sequence = 0;

    $sequence++;

    $disk = Storage::disk('local');
    $disk->makeDirectory('tests-tmp');

    return $disk->path("tests-tmp/{$prefix}-{$sequence}.{$extension}");
}

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

/**
 * Engineering provider accepted as a construction's measurement company.
 */
function makeMeasurementCompany(): ExpenseServiceProvider
{
    $engineeringType = ExpenseServiceProviderType::factory()->create([
        'name' => Construction::MEASUREMENT_COMPANY_TYPE_NAME,
    ]);

    return ExpenseServiceProvider::factory()->create([
        'name' => 'Engenharia Medições',
        'expense_service_provider_type_id' => $engineeringType->id,
    ]);
}

/**
 * Mandatory construction payload of the emission creation wizard, with its
 * initial sales board.
 *
 * @return array<string, mixed>
 */
function emissionConstructionState(int $measurementCompanyId): array
{
    return [
        'development_name' => 'Residencial Aurora',
        'development_trade_name' => 'Aurora',
        'development_cnpj' => '12.345.678/0001-90',
        'city' => 'Fortaleza',
        'state' => 'CE',
        'measurement_company_id' => $measurementCompanyId,
        EmissionConstructionsStep::SALES_BOARD_STATE_PATH => [
            'reference_month' => '05/2026',
            'stock_units' => 10,
            'financed_units' => 4,
            'paid_units' => 3,
            'exchanged_units' => 1,
            'stock_value' => '1.000,00',
            'financed_value' => '2.000,50',
            'paid_value' => '3.000,00',
            'exchanged_value' => '4.000,00',
        ],
    ];
}

/**
 * Emission form state that satisfies the mandatory constructions step.
 *
 * @return array<string, mixed>
 */
function emissionCreateFormState(int $measurementCompanyId): array
{
    return [
        EmissionConstructionsStep::STATE_PATH => [
            emissionConstructionState($measurementCompanyId),
        ],
    ];
}

/**
 * Emission with one construction, the pair every unit test needs.
 *
 * @return array{0: Emission, 1: Construction}
 */
function unitEmissionAndConstruction(string $emissionName = 'CRI Conviva', string $developmentName = 'Conviva Camboinhas'): array
{
    $emission = Emission::factory()->create(['name' => $emissionName]);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => $developmentName,
    ]);

    return [$emission, $construction];
}

function makeSalesBoardAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function makeAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}
