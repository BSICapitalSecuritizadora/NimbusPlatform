<?php

use App\Actions\Clients\ClientSpreadsheetColumns;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetColumns;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetColumns;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\ImportRun;
use App\Models\Receivable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Os seis assistentes de importação com o envio temporário num disco sem
 * caminho local -- a situação da produção, em que o disco padrão é o Azure Blob.
 *
 * Foi assim que as conferências ficaram cegas: `getRealPath()` devolvia
 * `livewire-tmp/<nome>.xlsx`, nenhum `is_file()` o achava e a tela dizia "Não
 * foi possível ler a planilha enviada.", enquanto o Confirmar importava às cegas.
 * A suíte não via, porque o disco `tmp-for-tests` é sempre local e os testes
 * passavam um caminho já gravado.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs(makeAdminUser());

    useRemoteLikeTemporaryUploadDisk();
});

/**
 * @param  list<string>  $headers
 * @param  list<array<int, mixed>>  $rows
 */
function remoteDiskSpreadsheet(string $prefix, array $headers, array $rows): string
{
    $path = temporaryTestFilePath($prefix);
    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, array_pad($row, count($headers), '')));
    }

    $writer->close();

    return $path;
}

/**
 * Uma emissão, um empreendimento, uma unidade com contrato e um cliente --
 * o mínimo que os seis assistentes leem.
 *
 * @return array{emission: Emission, construction: Construction, unit: ConstructionUnit, contract: Contract}
 */
function remoteDiskScenario(): array
{
    [$emission, $construction] = unitEmissionAndConstruction(status: 'active');

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create([
        'block' => '01',
        'unit' => '305',
        'base_value' => '800000.00',
        'base_value_reference_date' => '2024-01-01',
    ]);

    $contract = Contract::factory()
        ->forUnit($unit)
        ->forClient(Client::factory()->create(['name' => 'João da Silva', 'document' => '52998224725']))
        ->create(['code' => 'CVC-00123', 'sale_date' => '2024-03-10', 'sale_value' => 850000.00]);

    return compact('emission', 'construction', 'unit', 'contract');
}

/**
 * O assistente, a planilha e o que muda no banco ao confirmar, por tela.
 *
 * @param  array{emission: Emission, construction: Construction, unit: ConstructionUnit, contract: Contract}  $scenario
 * @return array{component: mixed, action: TestAction, path: string, summary: string, written: Closure(): int, run: string|null}
 */
function remoteDiskWizard(string $screen, array $scenario): array
{
    return match ($screen) {
        'parcelas-listagem' => [
            'component' => Livewire::test(ListContractInstallments::class),
            'action' => TestAction::make('importContractInstallments'),
            'path' => remoteDiskSpreadsheet('remoto-parcelas', ContractInstallmentSpreadsheetColumns::headers(), [
                ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', 10000.00, '', '', ''],
                ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '002', '10/02/2026', 10000.00, '', '', ''],
            ]),
            'summary' => 'Total analisado: <b>2</b>',
            'written' => fn (): int => ContractInstallment::query()->count(),
            'run' => ImportRun::TYPE_CONTRACT_INSTALLMENTS,
        ],
        'parcelas-contrato' => [
            'component' => Livewire::test(ContractInstallmentsRelationManager::class, [
                'ownerRecord' => $scenario['contract'],
                'pageClass' => ViewContract::class,
            ]),
            'action' => TestAction::make('importContractInstallments')->table(),
            'path' => remoteDiskSpreadsheet('remoto-parcelas-contrato', ContractInstallmentSpreadsheetColumns::headers(), [
                ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', 10000.00, '', '', ''],
            ]),
            'summary' => 'Total analisado: <b>1</b>',
            'written' => fn (): int => ContractInstallment::query()->count(),
            'run' => ImportRun::TYPE_CONTRACT_INSTALLMENTS,
        ],
        'contratos' => [
            'component' => Livewire::test(ListContracts::class),
            'action' => TestAction::make('importContracts'),
            'path' => remoteDiskSpreadsheet('remoto-contratos', ContractSpreadsheetColumns::headers(), [
                ['CRI Conviva', 'Conviva Camboinhas', '01', '402', '52998224725', 'CVC-00999', '10/03/2024', 820000.00, 'Ativo', ''],
            ]),
            'summary' => 'Total analisado: <b>1</b>',
            'written' => fn (): int => Contract::query()->count() - 1,
            'run' => ImportRun::TYPE_CONTRACTS,
        ],
        'unidades' => [
            'component' => Livewire::test(ListConstructionUnits::class),
            'action' => TestAction::make('importUnits'),
            'path' => remoteDiskSpreadsheet('remoto-unidades', ConstructionUnitSpreadsheetColumns::headers(), [
                ['CRI Conviva', 'Conviva Camboinhas', '05', '501', 810000.00, '01/01/2024'],
                ['CRI Conviva', 'Conviva Camboinhas', '05', '502', 815000.00, '01/01/2024'],
            ]),
            'summary' => 'Total de linhas: <b>2</b>',
            'written' => fn (): int => ConstructionUnit::query()->count() - 2,
            'run' => ImportRun::TYPE_CONSTRUCTION_UNITS,
        ],
        'valores' => [
            'component' => Livewire::test(ListConstructionUnits::class),
            'action' => TestAction::make('updateUnitValues'),
            'path' => remoteDiskSpreadsheet('remoto-valores', UnitValueSpreadsheetColumns::headers(), [
                ['CRI Conviva', 'Conviva Camboinhas', '01', '305', 880000.00, '01/06/2026', 'Reajuste'],
            ]),
            'summary' => 'Total de linhas: <b>1</b>',
            'written' => fn (): int => ConstructionUnitValue::query()->count(),
            'run' => ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES,
        ],
        'clientes' => [
            'component' => Livewire::test(ListClients::class),
            'action' => TestAction::make('importClients'),
            'path' => remoteDiskSpreadsheet('remoto-clientes', ClientSpreadsheetColumns::headers(), [
                ['PF', 'Maria Souza', '111.444.777-35', 'maria@example.com', ''],
            ]),
            'summary' => 'Total de linhas: <b>1</b>',
            'written' => fn (): int => Client::query()->count() - 1,
            'run' => null,
        ],
    };
}

it('shows the conference instead of "could not read" with the temporary on a disk with no local path', function (string $screen) {
    $scenario = remoteDiskScenario();

    // A second unit for the contract and unit files of the dataset.
    ConstructionUnit::factory()->forConstruction($scenario['construction'])->create(['block' => '01', 'unit' => '402']);

    $wizard = remoteDiskWizard($screen, $scenario);

    $wizard['component']
        ->mountAction($wizard['action'])
        ->fillForm(['file' => spreadsheetUpload($wizard['path'])])
        ->assertMountedActionModalSee($wizard['summary'], escape: false)
        ->assertMountedActionModalDontSee('Não foi possível ler a planilha enviada.');
})->with(['parcelas-listagem', 'parcelas-contrato', 'contratos', 'unidades', 'valores', 'clientes']);

it('confirms from the uploaded file and records its name, checksum and archive', function (string $screen) {
    $scenario = remoteDiskScenario();

    ConstructionUnit::factory()->forConstruction($scenario['construction'])->create(['block' => '01', 'unit' => '402']);

    $wizard = remoteDiskWizard($screen, $scenario);
    $before = ($wizard['written'])();

    $wizard['component']
        ->callAction($wizard['action'], ['file' => spreadsheetUpload($wizard['path'], 'posicao-'.$screen.'.xlsx')])
        ->assertHasNoActionErrors();

    expect(($wizard['written'])())->toBeGreaterThan($before);

    /**
     * Clientes não têm ImportRun: a planilha com CPF/CNPJ não é arquivada, e a
     * trilha guarda o nome original e o checksum do arquivo importado.
     */
    if ($wizard['run'] === null) {
        $activity = Activity::query()->where('log_name', 'importacao-clientes')->sole();

        expect(Storage::disk('local')->allFiles('imports'))->toBe([])
            ->and($activity->properties['arquivo'])->toBe('posicao-'.$screen.'.xlsx')
            ->and($activity->properties['checksum'])->toBe(hash_file('sha256', $wizard['path']));

        return;
    }

    $run = ImportRun::query()->sole();

    expect($run->type)->toBe($wizard['run'])
        ->and($run->file_name)->toBe('posicao-'.$screen.'.xlsx')
        ->and($run->checksum)->toBe(hash_file('sha256', $wizard['path']))
        ->and($run->file_path)->toMatch('#^imports/[a-z-]+/[0-9a-z]{26}\.xlsx$#')
        ->and(Storage::disk('local')->get((string) $run->file_path))->toBe(file_get_contents($wizard['path']));
})->with(['parcelas-listagem', 'parcelas-contrato', 'contratos', 'unidades', 'valores', 'clientes']);

it('points the installment callout at the blocking row with the remote disk', function () {
    remoteDiskScenario();

    $path = remoteDiskSpreadsheet('remoto-parcelas-bloqueio', ContractInstallmentSpreadsheetColumns::headers(), [
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', 10000.00, '', '', ''],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-999', '002', '10/02/2026', 10000.00, '', '', ''],
    ]);

    $component = Livewire::test(ListContractInstallments::class)
        ->mountAction(TestAction::make('importContractInstallments'))
        ->fillForm(['file' => spreadsheetUpload($path)])
        ->assertSchemaComponentVisible('installmentProblems');

    $page = $component->instance();
    $problems = $page->getSchema($page->getMountedActionSchemaName())->getComponent('installmentProblems');

    expect($problems->getHeading())->toBe('Linhas a corrigir na planilha: 1')
        ->and($problems->toEmbeddedHtml())
        ->toContain('<td style="padding:.25rem .5rem;">3</td>')
        ->toContain('CVC-999')
        ->toContain('Contrato não encontrado.');
});

it('leaves neither the temporary upload nor its metadata behind after confirming', function (string $screen) {
    $scenario = remoteDiskScenario();

    ConstructionUnit::factory()->forConstruction($scenario['construction'])->create(['block' => '01', 'unit' => '402']);

    $wizard = remoteDiskWizard($screen, $scenario);
    $temporary = Storage::disk(FileUploadConfiguration::disk());

    $wizard['component']
        ->mountAction($wizard['action'])
        ->fillForm(['file' => spreadsheetUpload($wizard['path'])]);

    expect($temporary->allFiles(FileUploadConfiguration::path()))->not->toBeEmpty();

    $wizard['component']
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($temporary->allFiles(FileUploadConfiguration::path()))->toBe([]);
})->with(['parcelas-listagem', 'contratos', 'unidades', 'valores', 'clientes']);

it('imports the receivables summary with the temporary on the remote disk', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Recebíveis Remoto', 'status' => 'active']);

    $path = temporaryTestFilePath('remoto-recebiveis');

    SimpleExcelWriter::create($path)
        ->noHeaderRow()
        ->nameCurrentSheet('Resumo')
        ->addRows(remoteDiskReceivableSummaryRows())
        ->close();

    Livewire::test(ListReceivables::class)
        ->callAction(TestAction::make('import'), [
            'emission_id' => $emission->id,
            'file' => spreadsheetUpload($path, 'resumo.xlsx'),
        ])
        ->assertHasNoActionErrors();

    expect(Receivable::query()->where('emission_id', $emission->id)->count())->toBe(1);
});

/**
 * O resumo de recebíveis no layout da aba "Resumo", com os rótulos que o
 * importador lê.
 *
 * @return list<list<mixed>>
 */
function remoteDiskReceivableSummaryRows(): array
{
    $rows = [
        ['Mes ano referencia', '2026-03-31', null],
        ['ID da Carteira', 98, null],
        ['Numero de contratos ativos', 131, null],
        ['Esperado a receber no Mes de Juros', 7307.63, null],
        ['Esperado de Receber no Mes de Amortizacao', 950402.10, null],
        ['Recebido no Mes de parcelas do Mes - Juros', 4841.44, null],
        ['Recebido no Mes de parcelas do Mes - Amortizacao', 530573.38, null],
        ['Recebido no Mes de Antecipacao - Juros', 200.30, null],
        ['Recebido no Mes de Antecipacao - Amortizacao', 34295.52, null],
        ['Recebido no mes de Inadimplencia - Juros', 875.20, null],
        ['Recebido no mes de Inadimplencia - Amortizacao', 121344.20, null],
        ['Recebido no Mes de Juros e Mora', 291.86, null],
        ['Saldo devedor da carteria Adimplente pre evento do mes', 21143330.25, null],
        ['Saldo devedor da carteria Inadimplente pre evento do mes', 6587749.80, null],
        ['Saldo devedor da carteria Adimplente pos evento do mes', 29967754.77, null],
        ['Saldo devedor da carteria Inadimplente pos evento do mes', 909400.43, null],
        ['Saldo Inadimplencia Mes', 292160.79, null],
        ['Saldo Inadimplencia Geral', 598656.76, null],
        ['Creditos vinculados em dia', 27731080.05, null],
        ['Vencidos E Nao Pagos Ate 30 Dias', 282995.60, null],
        ['Vencidos E Nao Pagos De 31 A 60 Dias', 71114.28, null],
        ['Vencidos E Nao Pagos Ds 61 A 90 Dias', 37366.07, null],
        ['Vencidos E Nao Pagos De 91 A 120 Dias', 49628.64, null],
        ['Vencidos E Nao Pagos De 121 A 150 Dias', 17197.18, null],
        ['Vencidos E Nao Pagos De 151 A 180 Dias', 58381.03, null],
        ['Vencidos E Nao Pagos De 181 A 360 Dias', 56123.76, null],
        ['Vencidos E Nao Pagos Acima De 360 Dias', 25850.20, null],
        ['Pagos Antecipadamente Ate 30 Dias', 27211.42, null],
        ['Pagos Antecipadamente De 31 A 60 Dias', 7284.40, null],
        ['Pagos Antecipadamente Ds 61 A 90 Dias', 0, null],
        ['Pagos Antecipadamente De 91 A 120 Dias', 0, null],
        ['Pagos Antecipadamente De 121 A 150 Dias', 0, null],
        ['Pagos Antecipadamente De 151 A 180 Dias', 0, null],
        ['Pagos Antecipadamente De 181 A 360 Dias', 0, null],
        ['Pagos Antecipadamente Acima De 360 Dias', 0, null],
        ['Creditos Vinculados Ate 30 Dias', 677460.59, null],
        ['Creditos Vinculados De 31 A 60 Dias', 549864.57, null],
        ['Creditos Vinculados Ds 61 A 90 Dias', 838581.34, null],
        ['Creditos Vinculados De 91 A 120 Dias', 760076.64, null],
        ['Creditos Vinculados De 121 A 150 Dias', 642294.49, null],
        ['Creditos Vinculados De 151 A 180 Dias', 696501.45, null],
        ['Creditos Vinculados De 181 A 360 Dias', 10644039.28, null],
        ['Creditos Vinculados Acima De 360 Dias', 12922261.69, null],
        ['Valor das garantias incorporadas ao PL do CRI', null, null],
        ['Valor total de pre-pagamento no mes', 38151.42, null],
        ['% De Concentracao Dos 5 Maiores Devedores', null, null],
        ['Os cinco maiores devedores', null, null],
        ['Nome e CNPJ de cada Devedor', null, null],
        ['Participacao em relacao a Oferta', null, null],
        ['Saldo devedor Total', 28329736.81, null],
        ['LTV', null, null],
        ['LTV Venda', 1.765278, null],
        ['Duration Carteira (Anos)', 1.399974, null],
        ['Duration Carteira (Meses)', 16.799698, null],
        ['Taxa Media da Carteira', 'INCC-DI - 11.33% a.a', null],
        [null, 'IPCA - 10.28% a.a', null],
    ];

    return $rows;
}
