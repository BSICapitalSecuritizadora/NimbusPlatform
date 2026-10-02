<?php

use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
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
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;
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

/**
 * Remove, ao fim do teste, o arquivo temporário que um gerador de modelo criou.
 *
 * Em produção quem remove é o `deleteFileAfterSend()` da resposta de download. O
 * cliente de teste monta a resposta mas nunca a envia, então esse gancho não
 * dispara -- e sem isto cada execução da suíte deixa uma planilha no diretório
 * de temporários da máquina, que é compartilhado com todo o resto do sistema.
 */
function discardTemplateFileAfterTest(string $path): string
{
    test()->beforeApplicationDestroyed(function () use ($path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });

    return $path;
}

/**
 * Monta o disco temporário do Livewire com a semântica do Azure Blob da
 * produção: os bytes continuam legíveis por stream, mas `path()` devolve o
 * caminho relativo (`livewire-tmp/<nome>.xlsx`), porque o disco não tem raiz
 * local na configuração. É o caso em que `getRealPath()` não serve para nada e
 * que a suíte, com o disco `tmp-for-tests` sempre local, nunca exercitava.
 *
 * A raiz física fica em `storage/framework/testing/disks` com o sufixo do
 * processo, então o `tearDown` do TestCase a apaga como apaga as demais.
 */
function useRemoteLikeTemporaryUploadDisk(): string
{
    $root = storage_path('framework/testing/disks/remote-tmp_test_'.ParallelTesting::token());

    if (! is_dir($root)) {
        mkdir($root, 0777, true);
    }

    $adapter = new LocalFilesystemAdapter($root);

    Storage::set('tmp-for-tests', new FilesystemAdapter(new Filesystem($adapter), $adapter, []));

    return $root;
}

/**
 * A planilha do caminho como o navegador a enviaria: é o que o `fillForm()` do
 * Filament transforma no envio temporário assinado do Livewire.
 */
function spreadsheetUpload(string $path, string $name = 'planilha.xlsx'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

/**
 * Troca o cache padrão por um que lê -- sempre "não encontrado" -- mas recusa
 * gravar: o Redis em failover (READONLY) ou cheio sem despejo, que respondem ao
 * GET e recusam o SET. Devolve o contador de gravações recusadas.
 */
function useCacheThatRefusesWrites(): ArrayObject
{
    $refused = new ArrayObject(['put' => 0]);

    Cache::extend('recusa-gravacao', fn (): Repository => Cache::repository(new class($refused) extends ArrayStore
    {
        public function __construct(private readonly ArrayObject $refused)
        {
            parent::__construct();
        }

        public function put($key, $value, $seconds)
        {
            $this->refused['put']++;

            throw new RuntimeException("READONLY You can't write against a read only replica.");
        }
    }));

    config([
        'cache.stores.recusa-gravacao' => ['driver' => 'recusa-gravacao'],
        'cache.default' => 'recusa-gravacao',
    ]);

    return $refused;
}

/**
 * Um envio temporário do Livewire, gravado no disco temporário ativo do teste,
 * com o conteúdo dado.
 */
function temporaryUploadWithContent(string $filename, string $contents): TemporaryUploadedFile
{
    $uploadedFile = UploadedFile::fake()->createWithContent($filename, $contents);
    $storedPath = FileUploadConfiguration::storeTemporaryFile(
        $uploadedFile,
        FileUploadConfiguration::disk(),
    );

    return TemporaryUploadedFile::createFromLivewire(basename($storedPath));
}

/**
 * As linhas classificadas de uma planilha de parcelas montada com as linhas
 * dadas, na ordem do arquivo. A conferência só guarda um resumo limitado; é por
 * aqui que um teste inspeciona a decisão de cada linha.
 *
 * @param  list<array<int, mixed>>  $rows
 * @param  list<string>|null  $headers
 * @return Collection<int, array<string, mixed>>
 */
function classifiedInstallmentRows(array $rows, ?int $contractId = null, ?array $headers = null): Collection
{
    $path = temporaryTestFilePath('contract-installments-rows');
    $headers ??= ContractInstallmentSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, array_pad($row, count($headers), '')));
    }

    $writer->close();

    return collect(iterator_to_array(
        app(AnalyzeContractInstallmentSpreadsheet::class)->open($path, $contractId)->rows(),
        false,
    ));
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
 * The status is left to the factory unless given; new tests pass it explicitly
 * (draft, active or closed), so a rule that depends on it never turns them
 * flaky.
 *
 * @return array{0: Emission, 1: Construction}
 */
function unitEmissionAndConstruction(string $emissionName = 'CRI Conviva', string $developmentName = 'Conviva Camboinhas', ?string $status = null): array
{
    $emission = Emission::factory()->create(array_filter(['name' => $emissionName, 'status' => $status]));
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
