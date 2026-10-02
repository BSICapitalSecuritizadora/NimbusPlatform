<?php

use App\Actions\ContractInstallments\AbsentInstallmentCancellation;
use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentImportResult;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetAnalysis;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetReading;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetTemplate;
use App\Actions\ContractInstallments\ImportContractInstallmentsFromSpreadsheet;
use App\Enums\ContractInstallmentStatus;
use App\Enums\ImportRowWarningCode;
use App\Enums\ReconciliationOutcome;
use App\Enums\SalesBoardUnitClassification;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\ImportRun;
use App\Models\SalesBoard;
use App\Models\User;
use App\Support\Imports\ImportRunDraft;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;

uses(RefreshDatabase::class);

/**
 * Part of the `parity` group: everything here depends on how the database itself
 * behaves -- unique indexes, collation, generated columns, soft deletes against
 * uniqueness, and imports that resolve textual keys. The normal suite runs it on
 * SQLite; `composer test:parity` runs the same tests on MySQL, and both have to
 * agree. See scripts/parity-check.sh.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<array<int, string|null>>  $rows
 */
function installmentSpreadsheet(array $rows, ?array $headers = null): string
{
    $path = temporaryTestFilePath('contract-installments-import');
    $headers ??= ContractInstallmentSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, array_pad($row, count($headers), '')));
    }

    $writer->close();

    return $path;
}

function analyzeInstallmentSpreadsheet(array $rows, ?array $headers = null, ?int $contractId = null)
{
    return app(AnalyzeContractInstallmentSpreadsheet::class)
        ->handle(installmentSpreadsheet($rows, $headers), $contractId);
}

/**
 * Confirms a spreadsheet the way the wizard does: the conference first, then the
 * import guarded by its digest.
 *
 * @param  list<array<int, mixed>>  $rows
 */
function importInstallmentRows(array $rows, ?int $contractId = null, ?AbsentInstallmentCancellation $cancellation = null): ContractInstallmentImportResult
{
    return importInstallmentFile(installmentSpreadsheet($rows), $contractId, $cancellation);
}

function importInstallmentFile(string $path, ?int $contractId = null, ?AbsentInstallmentCancellation $cancellation = null): ContractInstallmentImportResult
{
    $conference = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path, $contractId);

    return app(ImportContractInstallmentsFromSpreadsheet::class)->handle(
        $path,
        $contractId,
        new ImportRunDraft(
            type: ImportRun::TYPE_CONTRACT_INSTALLMENTS,
            fileName: basename($path),
            checksum: hash_file('sha256', $path) ?: null,
            filePath: null,
            userId: null,
            contractId: $contractId,
        ),
        $conference->digest(),
        $cancellation,
    );
}

/**
 * One emission with two developments, each holding a contract. The second pair
 * is what makes "the same code in another development" testable, which is the
 * rule the contracts module made the schedule depend on.
 *
 * @return array{0: Emission, 1: Construction, 2: Contract, 3: Construction, 4: Contract}
 */
function installmentImportScenario(): array
{
    [$emission, $construction] = unitEmissionAndConstruction();

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);
    $contract = Contract::factory()
        ->forUnit($unit)
        ->forClient(Client::factory()->create(['name' => 'João da Silva', 'document' => '52998224725']))
        ->create(['code' => 'CVC-00123', 'sale_value' => 850000.00]);

    $otherConstruction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Conviva Piratininga',
    ]);
    $otherUnit = ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => '02', 'unit' => '101']);
    $otherContract = Contract::factory()->forUnit($otherUnit)->create(['code' => 'CVC-00123']);

    return [$emission, $construction, $contract, $otherConstruction, $otherContract];
}

/**
 * @return array<int, string|null>
 */
function installmentRow(array $overrides = []): array
{
    return array_values([
        'emission' => 'CRI Conviva',
        'construction' => 'Conviva Camboinhas',
        'contract' => 'CVC-00123',
        'number' => '001',
        'due_date' => '10/01/2026',
        'expected_value' => '10000.00',
        'payment_date' => '',
        'paid_value' => '',
        'cancellation_date' => '',
        ...$overrides,
    ]);
}

it('builds a template whose data sheet carries only the headers', function () {
    $path = discardTemplateFileAfterTest(app(ContractInstallmentSpreadsheetTemplate::class)->build());

    $dataRows = SimpleExcelReader::create($path)->getRows()->all();
    $exampleRows = SimpleExcelReader::create($path)
        ->fromSheetName(ContractInstallmentSpreadsheetTemplate::EXAMPLE_SHEET)
        ->getRows()
        ->all();

    expect($dataRows)->toBe([])
        ->and($exampleRows)->toHaveCount(3)
        ->and(array_keys($exampleRows[0]))->toBe(ContractInstallmentSpreadsheetColumns::headers())
        ->and($exampleRows[0]['Número'])->toBe('001')
        ->and($exampleRows[2]['Número'])->toBe('ENTRADA')
        // An unpaid installment leaves the cells empty, never the word NULL.
        ->and($exampleRows[1]['Data Pagamento'])->toBe('')
        ->and($exampleRows[1]['Valor Pago'])->toBe('');

    expect(app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path)->fileErrors)
        ->toBe(['A planilha está vazia.']);
});

it('serves the template through the download route', function () {
    $this->actingAs(makeAdminUser());

    $response = $this->get(route('admin.contract-installments.template.download'))
        ->assertSuccessful()
        ->assertDownload(ContractInstallmentSpreadsheetTemplate::DOWNLOAD_NAME);

    discardTemplateFileAfterTest($response->baseResponse->getFile()->getPathname());
});

it('rejects a spreadsheet without the required columns', function () {
    $analysis = analyzeInstallmentSpreadsheet([['CRI', 'Conviva']], ['Emissão', 'Empreendimento']);

    expect($analysis->fileErrors)->toBe([
        'A planilha não possui as colunas obrigatórias: Contrato, Número, Vencimento, Valor Previsto.',
    ])->and($analysis->canImport())->toBeFalse();
});

it('accepts a spreadsheet without the optional payment and cancellation columns', function () {
    installmentImportScenario();

    $headers = array_values(array_diff(ContractInstallmentSpreadsheetColumns::headers(), [
        ContractInstallmentSpreadsheetColumns::PAYMENT_DATE,
        ContractInstallmentSpreadsheetColumns::PAID_VALUE,
        ContractInstallmentSpreadsheetColumns::CANCELLATION_DATE,
    ]));

    $row = array_slice(installmentRow(), 0, 6);

    $analysis = analyzeInstallmentSpreadsheet([$row], $headers);

    expect($analysis->fileErrors)->toBe([])
        ->and($analysis->newCount())->toBe(1);
});

it('resolves the contract from the emission, the development and the code', function () {
    [, , $contract] = installmentImportScenario();

    $rows = [installmentRow([
        'payment_date' => '10/01/2026',
        'paid_value' => '10000.00',
    ])];
    $analysis = analyzeInstallmentSpreadsheet($rows);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->newCount())->toBe(1);

    $row = classifiedInstallmentRows($rows)->firstWhere('outcome', ReconciliationOutcome::New);

    expect($row['contract_id'])->toBe($contract->id)
        ->and($row['number'])->toBe('001')
        ->and($row['due_date'])->toBe('2026-01-10')
        ->and($row['expected_value'])->toBe(10000.00)
        ->and($row['payment_date'])->toBe('2026-01-10')
        ->and($row['paid_value'])->toBe(10000.00)
        ->and($row['cancellation_date'])->toBeNull();
});

it('never matches a contract code that belongs to another development', function () {
    [, , $contract, , $otherContract] = installmentImportScenario();

    $spreadsheetRows = [
        installmentRow(),
        installmentRow(['construction' => 'Conviva Piratininga', 'number' => '002']),
    ];
    $analysis = analyzeInstallmentSpreadsheet($spreadsheetRows);

    expect($analysis->newCount())->toBe(2);

    $rows = classifiedInstallmentRows($spreadsheetRows)->where('outcome', ReconciliationOutcome::New)->values();

    expect($rows[0]['contract_id'])->toBe($contract->id)
        ->and($rows[1]['contract_id'])->toBe($otherContract->id)
        ->and($contract->id)->not->toBe($otherContract->id);
});

it('finds the contract when the spreadsheet cases the code differently', function () {
    [, , $contract] = installmentImportScenario();

    expect($contract->code)->toBe('CVC-00123');

    $rows = [
        installmentRow(['contract' => 'cvc-00123', 'number' => '001']),
        installmentRow(['contract' => '  CVC-00123  ', 'number' => '002', 'due_date' => '10/02/2026']),
    ];
    $analysis = analyzeInstallmentSpreadsheet($rows);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->canImport())->toBeTrue()
        ->and(classifiedInstallmentRows($rows)->where('outcome', ReconciliationOutcome::New)->pluck('contract_id')->unique()->values()->all())->toBe([$contract->id]);
});

it('still picks the development apart when both carry the same code', function () {
    [, , $contract, , $otherContract] = installmentImportScenario();

    // Both developments hold a CVC-00123; only the development tells them apart.
    $spreadsheetRows = [
        installmentRow(['contract' => 'cvc-00123', 'number' => '001']),
        installmentRow(['construction' => 'Conviva Piratininga', 'contract' => 'CVC-00123', 'number' => '001']),
    ];
    $analysis = analyzeInstallmentSpreadsheet($spreadsheetRows);

    $rows = classifiedInstallmentRows($spreadsheetRows)->where('outcome', ReconciliationOutcome::New)->values();

    expect($analysis->newCount())->toBe(2)
        ->and($rows[0]['contract_id'])->toBe($contract->id)
        ->and($rows[1]['contract_id'])->toBe($otherContract->id)
        ->and($contract->id)->not->toBe($otherContract->id);
});

it('detects a duplicate parcela across differently cased contract codes', function () {
    [, , $contract] = installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['contract' => 'CVC-00123', 'number' => '001']),
        installmentRow(['contract' => 'cvc-00123', 'number' => '001', 'due_date' => '10/02/2026']),
    ]);

    expect($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse()
        ->and($contract->id)->toBeGreaterThan(0);
});

it('never creates a contract that does not exist', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow(['contract' => 'CVC-999'])]);

    expect($analysis->newCount())->toBe(0)
        ->and($analysis->errorCount())->toBe(1)
        ->and(classifiedInstallmentRows([installmentRow(['contract' => 'CVC-999'])])->first()['message'])->toBe('Contrato não encontrado.')
        ->and(Contract::query()->where('code', 'CVC-999')->exists())->toBeFalse();
});

it('says so when the code exists but under another development', function () {
    [, , , $otherConstruction] = installmentImportScenario();

    $lonely = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => '02', 'unit' => '202']))
        ->create(['code' => 'CVC-77777']);

    expect(classifiedInstallmentRows([installmentRow(['contract' => 'CVC-77777'])])->first()['message'])
        ->toBe('Contrato não encontrado neste empreendimento. Este código pertence a outro empreendimento.')
        ->and($lonely->construction_id)->toBe($otherConstruction->id);
});

it('never creates an emission or a development that does not exist', function () {
    installmentImportScenario();

    $missingEmission = classifiedInstallmentRows([installmentRow(['emission' => 'CRI Inexistente'])]);
    $missingConstruction = classifiedInstallmentRows([installmentRow(['construction' => 'Não existe'])]);

    expect($missingEmission->first()['message'])->toBe('Emissão não encontrada.')
        ->and($missingConstruction->first()['message'])->toBe('Empreendimento não encontrado.')
        ->and(Emission::query()->count())->toBe(1)
        ->and(Construction::query()->count())->toBe(2);
});

it('rejects a development that belongs to another emission', function () {
    installmentImportScenario();

    [, $otherEmissionConstruction] = unitEmissionAndConstruction('CRI Bellevue', 'Alto Bellevue');

    $rows = classifiedInstallmentRows([installmentRow([
        'emission' => 'CRI Conviva',
        'construction' => 'Alto Bellevue',
    ])]);

    expect($rows->first()['message'])
        ->toBe('O empreendimento informado não pertence à emissão selecionada.')
        ->and($otherEmissionConstruction->development_name)->toBe('Alto Bellevue');
});

it('refuses to attach a schedule to a deleted contract', function () {
    [, , $contract] = installmentImportScenario();

    $contract->delete();

    expect(classifiedInstallmentRows([installmentRow()])->first()['message'])
        ->toBe('O contrato está excluído e não pode receber novas parcelas.');
});

it('points out the missing required fields', function () {
    installmentImportScenario();

    $rows = classifiedInstallmentRows([installmentRow([
        'number' => '',
        'due_date' => '',
        'expected_value' => '',
    ])]);

    expect($rows->first()['message'])
        ->toBe('Campos obrigatórios não preenchidos: Número, Vencimento, Valor Previsto.');
});

it('validates the dates and the amounts of every row', function () {
    installmentImportScenario();

    expect(classifiedInstallmentRows([installmentRow(['due_date' => '31/02/2026'])])->first()['message'])
        ->toBe('Data de vencimento inválida. Utilize o formato dd/mm/aaaa, com o ano completo (a partir de 1990).');

    expect(classifiedInstallmentRows([installmentRow(['expected_value' => '0'])])->first()['message'])
        ->toBe('Valor previsto inválido: informe um valor maior que zero.');

    expect(classifiedInstallmentRows([installmentRow(['expected_value' => 'abc'])])->first()['message'])
        ->toBe('Valor previsto inválido: informe um valor maior que zero.');

    expect(classifiedInstallmentRows([installmentRow(['payment_date' => '10/13/2026', 'paid_value' => '10000.00'])])->first()['message'])
        ->toBe('Data do pagamento inválida. Utilize o formato dd/mm/aaaa, com o ano completo (a partir de 1990).');

    expect(classifiedInstallmentRows([installmentRow(['cancellation_date' => 'ontem'])])->first()['message'])
        ->toBe('Data de cancelamento inválida. Utilize o formato dd/mm/aaaa, com o ano completo (a partir de 1990).');
});

it('refuses a payment date without a paid value, and the other way round', function () {
    installmentImportScenario();

    expect(classifiedInstallmentRows([installmentRow(['payment_date' => '10/01/2026'])])->first()['message'])
        ->toBe('Informe o valor pago junto com a data do pagamento.');

    expect(classifiedInstallmentRows([installmentRow(['paid_value' => '10000.00'])])->first()['message'])
        ->toBe('Informe a data do pagamento junto com o valor pago.');
});

/**
 * The files the operators produce fill "Valor Pago" for every installment and
 * carry 0 until money arrives. Reading a zero as a receipt rejected 12.326 rows
 * of a real 13.260 row schedule, so a zero with no date beside it is an unpaid
 * installment, exactly like an empty cell.
 */
it('reads a paid value of zero with no date as an unpaid installment', function () {
    installmentImportScenario();

    $rows = [
        installmentRow(['number' => '001', 'paid_value' => '0']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026', 'paid_value' => '0,00']),
        installmentRow(['number' => '003', 'due_date' => '10/03/2026', 'paid_value' => '0.00']),
    ];
    $analysis = analyzeInstallmentSpreadsheet($rows);
    $created = classifiedInstallmentRows($rows)->where('outcome', ReconciliationOutcome::New);

    expect($analysis->newCount())->toBe(3)
        ->and($analysis->canImport())->toBeTrue()
        ->and($created->pluck('paid_value')->all())->toBe([null, null, null])
        ->and($created->pluck('payment_date')->all())->toBe([null, null, null]);
});

it('persists a zero paid value as nothing received', function () {
    installmentImportScenario();

    importInstallmentRows([installmentRow(['paid_value' => '0'])]);

    $installment = ContractInstallment::query()->sole();

    expect($installment->paid_value)->toBeNull()
        ->and($installment->payment_date)->toBeNull()
        ->and($installment->status)->not->toBe(ContractInstallmentStatus::Paid);
});

/**
 * A zero *with* a payment date is a different thing -- a permuta settles an
 * installment on a given day without cash changing hands. It stays blocked
 * because there is no rule yet for what that should record.
 */
it('still reports a zero paid value that carries a payment date', function () {
    installmentImportScenario();

    $rows = [installmentRow([
        'payment_date' => '27/04/2026',
        'paid_value' => '0',
    ])];
    $analysis = analyzeInstallmentSpreadsheet($rows);

    expect($analysis->newCount())->toBe(0)
        ->and($analysis->canImport())->toBeFalse()
        ->and(classifiedInstallmentRows($rows)->first()['message'])
        ->toBe('Valor pago inválido: informe um valor maior que zero.');
});

it('still reports text that is not a number in the paid value', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow(['paid_value' => 'pago'])]);

    expect($analysis->newCount())->toBe(0)
        ->and(classifiedInstallmentRows([installmentRow(['paid_value' => 'pago'])])->first()['message'])
        ->toBe('Informe a data do pagamento junto com o valor pago.');
});

it('accepts a receipt above the expected value, for juros and multa', function () {
    installmentImportScenario();

    $rows = [installmentRow([
        'payment_date' => '15/02/2026',
        'paid_value' => '10850.00',
    ])];

    expect(analyzeInstallmentSpreadsheet($rows)->newCount())->toBe(1)
        ->and(classifiedInstallmentRows($rows)->first()['paid_value'])->toBe(10850.00);
});

it('accepts values written in the brazilian format', function () {
    installmentImportScenario();

    $row = classifiedInstallmentRows([installmentRow([
        'expected_value' => '12.500,50',
        'payment_date' => '10/01/2026',
        'paid_value' => 'R$ 12.500,50',
    ])])->first();

    expect($row['outcome'])->toBe(ReconciliationOutcome::New)
        ->and($row['expected_value'])->toBe(12500.50)
        ->and($row['paid_value'])->toBe(12500.50);
});

it('preserves the leading zeros and the worded numbers of the schedule', function () {
    installmentImportScenario();

    $rows = classifiedInstallmentRows([
        installmentRow(['number' => '001']),
        installmentRow(['number' => 'ENTRADA', 'due_date' => '05/12/2025']),
        installmentRow(['number' => 'INTERMEDIÁRIA 01', 'due_date' => '05/06/2026']),
    ]);

    expect($rows->where('outcome', ReconciliationOutcome::New)->pluck('number')->all())
        ->toBe(['001', 'ENTRADA', 'INTERMEDIÁRIA 01']);
});

it('rejects a parcela repeated inside the spreadsheet', function () {
    installmentImportScenario();

    $rows = [
        installmentRow(['number' => '001']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
        installmentRow(['number' => '001', 'due_date' => '10/03/2026']),
    ];
    $analysis = analyzeInstallmentSpreadsheet($rows);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();

    $duplicated = classifiedInstallmentRows($rows)->firstWhere('outcome', ReconciliationOutcome::DuplicatedInFile);

    expect($duplicated['line'])->toBe(4)
        ->and($duplicated['message'])->toStartWith('Parcela repetida na planilha (linha 2).');
});

it('treats numbers differing only by case as the same parcela inside the file', function () {
    installmentImportScenario();

    $rows = [
        installmentRow(['number' => 'Entrada']),
        installmentRow(['number' => '001', 'due_date' => '10/02/2026']),
        installmentRow(['number' => 'entrada', 'due_date' => '10/03/2026']),
    ];
    $analysis = analyzeInstallmentSpreadsheet($rows);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();

    $duplicated = classifiedInstallmentRows($rows)->firstWhere('outcome', ReconciliationOutcome::DuplicatedInFile);

    expect($duplicated['line'])->toBe(4)
        ->and($duplicated['number'])->toBe('entrada')
        ->and($duplicated['message'])->toStartWith('Parcela repetida na planilha (linha 2).');
});

it('treats numbers differing only by spacing as the same parcela inside the file', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => 'Intermediária 01']),
        installmentRow(['number' => '  INTERMEDIÁRIA   01  ', 'due_date' => '10/02/2026']),
    ]);

    expect($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();
});

it('keeps an accent difference as two different parcelas inside the file', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => 'INTERMEDIÁRIA 01']),
        installmentRow(['number' => 'INTERMEDIARIA 01', 'due_date' => '10/02/2026']),
    ]);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->duplicatedInFileCount())->toBe(0)
        ->and($analysis->canImport())->toBeTrue();
});

it('matches a parcela already registered even when the case differs', function () {
    [, , $contract] = installmentImportScenario();

    ContractInstallment::factory()->forContract($contract)->create([
        'number' => 'Entrada',
        'due_date' => '2026-01-10',
        'expected_value' => 50000,
    ]);

    // " entrada " is the same parcela as "Entrada", so the row is compared
    // against it -- and the amount moved, which makes it a retificação.
    $rows = [installmentRow([
        'number' => ' entrada ',
        'expected_value' => '99999.00',
    ])];
    $analysis = analyzeInstallmentSpreadsheet($rows);

    expect($analysis->newCount())->toBe(0)
        ->and($analysis->criticalUpdateCount())->toBe(1)
        ->and(classifiedInstallmentRows($rows)->first()['installment_id'])->toBe(ContractInstallment::query()->sole()->id);

    // Still nothing written before confirmation.
    expect((float) ContractInstallment::query()->sole()->expected_value)->toBe(50000.00);
});

it('reads a parcela that is identical in every field as sem alteração', function () {
    [, , $contract] = installmentImportScenario();

    ContractInstallment::factory()->forContract($contract)->create([
        'number' => 'Entrada',
        'due_date' => '2026-01-10',
        'expected_value' => 10000,
    ]);

    $analysis = analyzeInstallmentSpreadsheet([installmentRow(['number' => ' ENTRADA '])]);

    expect($analysis->unchangedCount())->toBe(1)
        ->and($analysis->newCount())->toBe(0)
        ->and($analysis->writeCount())->toBe(0)
        ->and($analysis->canImport())->toBeTrue();
});

it('applies the same identity rule as the manual form', function () {
    [, , $contract] = installmentImportScenario();

    ContractInstallment::factory()->forContract($contract)->create(['number' => 'Entrada']);

    // The form and the spreadsheet have to agree on what "already taken" means.
    expect(ContractInstallment::isDuplicateNumber($contract->id, ' ENTRADA '))->toBeTrue()
        ->and(analyzeInstallmentSpreadsheet([installmentRow(['number' => ' ENTRADA '])])->newCount())->toBe(0)
        ->and(ContractInstallment::isDuplicateNumber($contract->id, 'ENTRADAS'))->toBeFalse()
        ->and(analyzeInstallmentSpreadsheet([installmentRow(['number' => 'ENTRADAS'])])->newCount())->toBe(1);
});

it('persists the identity alongside the number the spreadsheet carried', function () {
    [, , $contract] = installmentImportScenario();

    importInstallmentRows([
        installmentRow(['number' => 'Intermediária 01']),
        installmentRow(['number' => '  entrada  ', 'due_date' => '05/12/2025']),
    ]);

    $intermediaria = ContractInstallment::query()->where('number_normalized', 'INTERMEDIÁRIA 01')->sole();
    $entrada = ContractInstallment::query()->where('number_normalized', 'ENTRADA')->sole();

    // Display keeps what was typed; the trailing spaces are gone from both.
    expect($intermediaria->number)->toBe('Intermediária 01')
        ->and($entrada->number)->toBe('entrada')
        ->and($entrada->contract_id)->toBe($contract->id);
});

it('accepts the same number twice when the contracts are different', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => '001']),
        installmentRow(['construction' => 'Conviva Piratininga', 'number' => '001']),
    ]);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->duplicatedInFileCount())->toBe(0);
});

it('compares a parcela that already exists instead of refusing it', function () {
    [, , $contract] = installmentImportScenario();

    ContractInstallment::factory()->forContract($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000,
    ]);

    $rows = [installmentRow([
        'expected_value' => '99999.00',
        'due_date' => '20/12/2026',
    ])];
    $analysis = analyzeInstallmentSpreadsheet($rows);
    $message = classifiedInstallmentRows($rows)->first()['message'];

    // Both the schedule and the amount moved, so it is a retificação -- flagged,
    // not applied behind anyone's back, and not silently discarded either.
    expect($analysis->criticalUpdateCount())->toBe(1)
        ->and($analysis->newCount())->toBe(0)
        ->and($analysis->canImport())->toBeTrue()
        ->and($message)
        ->toContain('Vencimento')
        ->and($message)
        ->toContain('Valor previsto');

    // Nothing is written until the import is confirmed.
    $stored = ContractInstallment::query()->sole();

    expect((float) $stored->expected_value)->toBe(10000.00)
        ->and($stored->due_date->toDateString())->toBe('2026-01-10');
});

it('persists the approved rows inside a single transaction', function () {
    [, , $contract, , $otherContract] = installmentImportScenario();

    $result = importInstallmentRows([
        installmentRow(['number' => '001', 'payment_date' => '10/01/2026', 'paid_value' => '10000.00']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
        installmentRow(['number' => 'ENTRADA', 'due_date' => '05/12/2025', 'expected_value' => '50000.00', 'cancellation_date' => '02/01/2026']),
        installmentRow(['construction' => 'Conviva Piratininga', 'number' => '001', 'due_date' => '10/04/2026']),
    ]);

    expect($result->created)->toBe(4)
        ->and($result->contracts)->toBe(2)
        ->and(ContractInstallment::query()->count())->toBe(4);

    $paid = ContractInstallment::query()->where('contract_id', $contract->id)->where('number', '001')->sole();
    $entrada = ContractInstallment::query()->where('contract_id', $contract->id)->where('number', 'ENTRADA')->sole();

    expect($paid->payment_date->toDateString())->toBe('2026-01-10')
        ->and((float) $paid->paid_value)->toBe(10000.00)
        ->and($entrada->cancellation_date->toDateString())->toBe('2026-01-02')
        ->and(ContractInstallment::query()->where('contract_id', $otherContract->id)->count())->toBe(1);
});

it('refuses to import a spreadsheet with any inconsistency', function () {
    installmentImportScenario();

    $rows = collect(range(1, 10))
        ->map(fn (int $index): array => installmentRow([
            'number' => str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'due_date' => '10/0'.(($index % 9) + 1).'/2026',
        ]))
        ->all();

    $rows[] = installmentRow(['contract' => 'CVC-999', 'number' => '999']);

    $analysis = analyzeInstallmentSpreadsheet($rows);

    expect($analysis->newCount())->toBe(10)
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();

    expect(fn () => importInstallmentRows($rows))
        ->toThrow(RuntimeException::class);

    expect(ContractInstallment::query()->count())->toBe(0);
});

it('ignores blank lines without letting them block the import', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(),
        array_fill(0, count(ContractInstallmentSpreadsheetColumns::headers()), ''),
    ]);

    expect($analysis->newCount())->toBe(1)
        ->and($analysis->totalLines())->toBe(1)
        ->and($analysis->canImport())->toBeTrue();
});

it('accepts only the rows of the contract when the import comes from its page', function () {
    [, , $contract, , $otherContract] = installmentImportScenario();

    $rows = [
        installmentRow(['number' => '001']),
        installmentRow(['construction' => 'Conviva Piratininga', 'number' => '002']),
    ];
    $analysis = analyzeInstallmentSpreadsheet($rows, contractId: $contract->id);

    expect($analysis->newCount())->toBe(1)
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse()
        ->and(classifiedInstallmentRows($rows, $contract->id)->last()['message'])
        ->toBe('Esta linha pertence a outro contrato. Importe a partir da listagem geral de parcelas.')
        ->and($otherContract->id)->not->toBe($contract->id);
});

it('imports installments through the listing wizard', function () {
    $this->actingAs(makeAdminUser());

    installmentImportScenario();

    $path = installmentSpreadsheet([
        installmentRow(['number' => '001']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
    ]);

    Livewire::test(ListContractInstallments::class)
        ->assertActionExists('downloadInstallmentTemplate')
        ->assertActionHasLabel('downloadInstallmentTemplate', 'Baixar Modelo')
        ->assertActionExists('importContractInstallments')
        ->assertActionHasLabel('importContractInstallments', 'Importar Parcelas')
        ->callAction(TestAction::make('importContractInstallments'), ['file' => spreadsheetUpload($path)])
        ->assertHasNoActionErrors();

    expect(ContractInstallment::query()->count())->toBe(2)
        ->and(Activity::query()->where('log_name', 'importacao-parcelas')->exists())->toBeTrue();
});

it('imports nothing when the wizard receives an inconsistent spreadsheet', function () {
    $this->actingAs(makeAdminUser());

    installmentImportScenario();

    $path = installmentSpreadsheet([
        installmentRow(['number' => '001']),
        installmentRow(['contract' => 'CVC-999', 'number' => '002']),
    ]);

    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), ['file' => spreadsheetUpload($path)]);

    expect(ContractInstallment::query()->count())->toBe(0);
});

it('imports the schedule from inside the contract page', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentImportScenario();

    $path = installmentSpreadsheet([installmentRow(['number' => '001'])]);

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->callAction(
            TestAction::make('importContractInstallments')->table(),
            ['file' => spreadsheetUpload($path)],
        )
        ->assertHasNoActionErrors();

    expect($contract->installments()->count())->toBe(1);
});

it('costs a bounded number of queries on a large spreadsheet', function () {
    [, , $contract] = installmentImportScenario();

    $rows = collect(range(1, 120))
        ->map(fn (int $index): array => installmentRow([
            'number' => str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'due_date' => '10/'.str_pad((string) (($index % 12) + 1), 2, '0', STR_PAD_LEFT).'/2026',
        ]))
        ->all();

    $path = installmentSpreadsheet($rows);

    DB::enableQueryLog();
    $analysis = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($analysis->newCount())->toBe(120)
        // Emissions, developments, contracts and registered numbers, batched --
        // never one lookup per row.
        ->and($queries)->toBeLessThan(10)
        ->and($contract->id)->toBeGreaterThan(0);
});

describe('reconciliação mensal', function () {
    /**
     * The whole point of the exercise: the same position imported twice must
     * leave the second run with nothing to do.
     */
    it('is idempotent: re-importing the same file writes nothing', function () {
        installmentImportScenario();

        $rows = [
            installmentRow(['number' => '001', 'payment_date' => '10/01/2026', 'paid_value' => '10000.00']),
            installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
            installmentRow(['number' => 'ENTRADA', 'due_date' => '05/12/2025', 'expected_value' => '50000.00']),
        ];

        $first = importInstallmentRows($rows);

        expect($first->created)->toBe(3)
            ->and($first->updated)->toBe(0);

        $touchedAt = ContractInstallment::query()->orderBy('id')->pluck('updated_at', 'id');
        $activityBefore = Activity::query()->where('subject_type', ContractInstallment::class)->count();

        $second = analyzeInstallmentSpreadsheet($rows);

        expect($second->unchangedCount())->toBe(3)
            ->and($second->newCount())->toBe(0)
            ->and($second->updateCount())->toBe(0)
            ->and($second->criticalUpdateCount())->toBe(0)
            ->and($second->writeCount())->toBe(0)
            ->and($second->canImport())->toBeTrue();

        $result = importInstallmentRows($rows);

        expect($result->created)->toBe(0)
            ->and($result->updated)->toBe(0)
            ->and($result->unchanged)->toBe(3)
            ->and(ContractInstallment::query()->count())->toBe(3)
            // No write means no updated_at moved and no activity entry appeared.
            ->and(ContractInstallment::query()->orderBy('id')->pluck('updated_at', 'id')->toArray())->toEqual($touchedAt->toArray())
            ->and(Activity::query()->where('subject_type', ContractInstallment::class)->count())->toBe($activityBefore);
    });

    it('reads a receipt arriving on an open parcela as a normal update', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
        ]);

        $rows = [installmentRow([
            'payment_date' => '25/08/2026',
            'paid_value' => '10000.00',
        ])];
        $analysis = analyzeInstallmentSpreadsheet($rows);

        expect($analysis->updateCount())->toBe(1)
            ->and($analysis->criticalUpdateCount())->toBe(0);

        $changes = classifiedInstallmentRows($rows)->first()['comparison']->changes;

        expect(collect($changes)->pluck('field')->all())->toBe(['payment_date', 'paid_value'])
            ->and($changes[0]->currentForDisplay())->toBe('—')
            ->and($changes[0]->newForDisplay())->toBe('25/08/2026')
            ->and($changes[1]->newForDisplay())->toBe('R$ 10.000,00');

        importInstallmentRows($rows);

        $installment = ContractInstallment::query()->sole();

        expect($installment->payment_date->toDateString())->toBe('2026-08-25')
            ->and((float) $installment->paid_value)->toBe(10000.00)
            // The status is derived, never written by the import.
            ->and($installment->status)->toBe(ContractInstallmentStatus::Paid);
    });

    it('audits an update the way a manual edit is audited', function () {
        [, , $contract] = installmentImportScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
        ]);

        importInstallmentRows([installmentRow(['payment_date' => '25/08/2026', 'paid_value' => '10000.00'])]);

        $activity = Activity::query()
            ->where('subject_type', ContractInstallment::class)
            ->where('subject_id', $installment->getKey())
            ->where('event', 'updated')
            ->sole();

        expect($activity->attribute_changes['old']['payment_date'])->toBeNull()
            ->and($activity->attribute_changes['attributes']['payment_date'])->toStartWith('2026-08-25')
            ->and((float) $activity->attribute_changes['attributes']['paid_value'])->toBe(10000.00);
    });

    it('flags a due date or an expected value moving as a critical update', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
        ]);

        $dueDateMoved = analyzeInstallmentSpreadsheet([installmentRow(['due_date' => '10/10/2026'])]);
        $amountMoved = analyzeInstallmentSpreadsheet([installmentRow(['expected_value' => '8000.00'])]);

        expect($dueDateMoved->criticalUpdateCount())->toBe(1)
            ->and($dueDateMoved->updateCount())->toBe(0)
            ->and($amountMoved->criticalUpdateCount())->toBe(1)
            ->and(classifiedInstallmentRows([installmentRow(['expected_value' => '8000.00'])])->first()['message'])->toBe('Valor previsto: R$ 10.000,00 → R$ 8.000,00');
    });

    it('flags a receipt changing value as a critical update', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'payment_date' => '2026-01-10',
            'paid_value' => 10000,
        ]);

        $analysis = analyzeInstallmentSpreadsheet([installmentRow([
            'payment_date' => '10/01/2026',
            'paid_value' => '5000.00',
        ])]);

        expect($analysis->criticalUpdateCount())->toBe(1);
    });

    it('reads the same receipt written differently as sem alteração', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'payment_date' => '2026-01-10',
            'paid_value' => 10000,
        ]);

        // Brazilian formatting against a stored decimal, and the same day.
        $analysis = analyzeInstallmentSpreadsheet([installmentRow([
            'expected_value' => '10.000,00',
            'payment_date' => '10/01/2026',
            'paid_value' => 'R$ 10.000,00',
        ])]);

        expect($analysis->unchangedCount())->toBe(1)
            ->and($analysis->writeCount())->toBe(0);
    });

    /**
     * The source writes an unpaid installment as an empty cell or a zero, so a
     * reverted payment is indistinguishable from one that never happened.
     * Clearing on that basis would let one incomplete export wipe real receipts.
     *
     * Not silent either: the row used to read "sem alteração", and a receipt
     * reverted at the source (a cheque returned) kept the contract settled with
     * nothing on the conference saying so. It is now an informative divergence
     * -- shown, counted, never written.
     */
    it('never clears a recorded payment because the sheet came empty', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'payment_date' => '2026-01-10',
            'paid_value' => 10000,
        ]);

        foreach ([['payment_date' => '', 'paid_value' => ''], ['payment_date' => '', 'paid_value' => '0']] as $emptyPayment) {
            $analysis = analyzeInstallmentSpreadsheet([installmentRow($emptyPayment)]);
            $row = classifiedInstallmentRows([installmentRow($emptyPayment)])->first();

            expect($row['outcome'])->toBe(ReconciliationOutcome::InformativeDivergence)
                ->and($row['message'])->toBe('Recebimento: 10/01/2026 · R$ 10.000,00 → não consta na planilha (mantido; para estornar, edite a parcela)')
                ->and($row['comparison']->attributes())->toBe([])
                ->and($analysis->informativeDivergenceCount())->toBe(1)
                ->and($analysis->unchangedCount())->toBe(0)
                ->and($analysis->writeCount())->toBe(0)
                ->and($analysis->canImport())->toBeTrue();
        }

        importInstallmentRows([installmentRow(['payment_date' => '', 'paid_value' => ''])]);

        $installment = ContractInstallment::query()->sole();

        expect($installment->payment_date->toDateString())->toBe('2026-01-10')
            ->and((float) $installment->paid_value)->toBe(10000.00);
    });

    it('never removes a cancelamento because the sheet came empty', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'cancellation_date' => '2026-02-01',
        ]);

        $analysis = analyzeInstallmentSpreadsheet([installmentRow(['cancellation_date' => ''])]);

        expect($analysis->unchangedCount())->toBe(1)
            ->and($analysis->writeCount())->toBe(0)
            ->and(ContractInstallment::query()->sole()->cancellation_date->toDateString())->toBe('2026-02-01');
    });

    it('leaves a parcela absent from the file completely alone', function () {
        [, , $contract] = installmentImportScenario();

        $absent = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '999',
            'due_date' => '2026-06-10',
            'expected_value' => 7000,
        ]);

        $before = $absent->updated_at;

        importInstallmentRows([installmentRow(['number' => '001'])]);

        $absent->refresh();

        expect($absent->exists)->toBeTrue()
            ->and($absent->trashed())->toBeFalse()
            ->and($absent->cancellation_date)->toBeNull()
            ->and($absent->updated_at->eq($before))->toBeTrue();
    });

    it('creates and updates in the same run, inside one transaction', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
        ]);

        $result = importInstallmentRows([
            installmentRow(['number' => '001', 'payment_date' => '25/08/2026', 'paid_value' => '10000.00']),
            installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
            installmentRow(['number' => '003', 'due_date' => '10/03/2026']),
        ]);

        expect($result->created)->toBe(2)
            ->and($result->updated)->toBe(1)
            ->and(ContractInstallment::query()->count())->toBe(3);
    });
});

it('records each confirmed reconciliation for later reference', function () {
    $this->actingAs($user = makeAdminUser());

    installmentImportScenario();

    $path = installmentSpreadsheet([
        installmentRow(['number' => '001']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
    ]);

    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), ['file' => spreadsheetUpload($path)])
        ->assertHasNoActionErrors();

    $run = ImportRun::query()->sole();

    expect($run->type)->toBe(ImportRun::TYPE_CONTRACT_INSTALLMENTS)
        ->and($run->user_id)->toBe($user->id)
        ->and($run->records_analyzed)->toBe(2)
        ->and($run->records_created)->toBe(2)
        ->and($run->records_updated)->toBe(0)
        ->and($run->records_unchanged)->toBe(0)
        ->and($run->madeChanges())->toBeTrue()
        ->and($run->checksum)->toHaveLength(64);

    // Running the very same file again records a run that moved nothing --
    // which is the proof the position was already reconciled.
    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), ['file' => spreadsheetUpload($path)])
        ->assertHasNoActionErrors();

    $second = ImportRun::query()->latest('id')->first();

    expect(ImportRun::query()->count())->toBe(2)
        ->and($second->records_created)->toBe(0)
        ->and($second->records_updated)->toBe(0)
        ->and($second->records_unchanged)->toBe(2)
        ->and($second->madeChanges())->toBeFalse()
        ->and($second->checksum)->toBe($run->checksum)
        ->and(ContractInstallment::query()->count())->toBe(2);
});

describe('leitura estrita da carga inicial', function () {
    /**
     * A numeric cell used to be turned into text before being read: 1553.919
     * (1523.45 x 1.02) became "1553.919", read as R$ 1.553.919,00, and the
     * installment paid with 1.553,92 was never settled again.
     */
    it('reads numeric amount cells for the number they hold', function () {
        installmentImportScenario();

        $rows = [
            installmentRow(['expected_value' => 1523.45 * 1.02, 'payment_date' => '10/01/2026', 'paid_value' => 1553.92]),
            installmentRow(['number' => '002', 'expected_value' => '1,553.92']),
            installmentRow(['number' => '003', 'expected_value' => '1.553,92']),
        ];
        $analysis = analyzeInstallmentSpreadsheet($rows);
        $created = classifiedInstallmentRows($rows)->where('outcome', ReconciliationOutcome::New);

        expect($analysis->canImport())->toBeTrue()
            ->and($created->pluck('expected_value')->all())->toBe([1553.92, 1553.92, 1553.92])
            ->and($created->first()['paid_value'])->toBe(1553.92);

        importInstallmentRows($rows);

        $installment = ContractInstallment::query()->where('number', '001')->sole();

        expect($installment->expected_value)->toBe('1553.92')
            ->and($installment->paid_value)->toBe('1553.92');
    });

    it('refuses a two digit year on every date instead of booking it in the year 26', function (string $column, string $message) {
        installmentImportScenario();

        $row = match ($column) {
            'due_date' => installmentRow(['due_date' => '10/01/26']),
            'payment_date' => installmentRow(['payment_date' => '05/03/26', 'paid_value' => '10000.00']),
            'cancellation_date' => installmentRow(['cancellation_date' => '05/03/26']),
        };

        expect(classifiedInstallmentRows([$row])->first()['message'])
            ->toBe($message.' Utilize o formato dd/mm/aaaa, com o ano completo (a partir de 1990).');
    })->with([
        'due date' => ['due_date', 'Data de vencimento inválida.'],
        'payment date' => ['payment_date', 'Data do pagamento inválida.'],
        'cancellation date' => ['cancellation_date', 'Data de cancelamento inválida.'],
    ]);

    it('refuses a payment dated after today in the business calendar', function () {
        installmentImportScenario();

        // 22:30 in São Paulo on 2026-09-25 is already the 26th in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 22:30:00', 'America/Sao_Paulo'));

        $future = classifiedInstallmentRows([installmentRow(['payment_date' => '26/09/2026', 'paid_value' => '10000.00'])]);
        $today = analyzeInstallmentSpreadsheet([installmentRow(['payment_date' => '25/09/2026', 'paid_value' => '10000.00'])]);

        expect($future->first()['message'])
            ->toBe('A data do pagamento não pode ser futura: um recebimento só é registrado depois de acontecer.')
            ->and($today->canImport())->toBeTrue();
    });

    it('shows the interpreted payment date and paid value on the conference', function () {
        $this->actingAs(makeAdminUser());

        installmentImportScenario();

        $path = installmentSpreadsheet([installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 1523.45 * 1.02])]);

        $component = Livewire::test(ListContractInstallments::class)->instance();
        $preview = (fn (): string => $this->renderInstallmentPreview(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)->toHtml())->call($component);

        expect($preview)
            ->toContain('<th style="text-align:left;padding:.25rem .5rem;">Pagamento</th>')
            ->toContain('<th style="text-align:right;padding:.25rem .5rem;">Pago</th>')
            ->toContain('10/01/2026')
            ->toContain('R$ 1.553,92')
            ->not->toContain('1.553.919');
    });
});

describe('estorno na fonte', function () {
    it('shows a recorded receipt missing from the file as an informative divergence on the conference', function () {
        $this->actingAs(makeAdminUser());

        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'payment_date' => '2026-03-05',
            'paid_value' => 10000,
        ]);

        $path = installmentSpreadsheet([installmentRow()]);

        $component = Livewire::test(ListContractInstallments::class)->instance();
        $preview = (fn (): string => $this->renderInstallmentPreview(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)->toHtml())->call($component);

        expect($preview)
            ->toContain('Divergências informativas: <b>1</b>')
            ->toContain('Nada a gravar, mas a planilha diverge do registrado em 1 linha(s).')
            ->toContain('<b>Divergência informativa</b>')
            ->toContain('Recebimento: 05/03/2026 · R$ 10.000,00 → não consta na planilha');

        $result = importInstallmentFile($path);

        expect($result->updated)->toBe(0)
            ->and($result->unchanged)->toBe(1)
            ->and(ContractInstallment::query()->sole()->payment_date->toDateString())->toBe('2026-03-05');
    });

    it('still applies the real changes of a row that also misses its receipt', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'payment_date' => '2026-03-05',
            'paid_value' => 10000,
        ]);

        $rows = [installmentRow(['due_date' => '15/01/2026'])];
        $row = classifiedInstallmentRows($rows)->first();

        expect($row['outcome'])->toBe(ReconciliationOutcome::CriticalUpdate)
            ->and($row['message'])->toContain('Recebimento: 05/03/2026')
            ->and($row['comparison']->attributes())->toBe(['due_date' => '2026-01-15']);

        importInstallmentRows($rows);

        $installment = ContractInstallment::query()->sole();

        expect($installment->due_date->toDateString())->toBe('2026-01-15')
            ->and($installment->payment_date->toDateString())->toBe('2026-03-05');
    });
});

describe('competência já registrada no Quadro de Vendas', function () {
    it('flags receipts that reach a registered competence, from the day of the receipt', function () {
        [$emission, $construction, $contract] = installmentImportScenario();
        $contract->forceFill(['sale_date' => '2025-06-10'])->save();

        foreach (['2026-02-01', '2026-03-01', '2026-04-01'] as $month) {
            SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => $month]);
        }

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
        ]);
        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '003',
            'due_date' => '2026-12-10',
            'expected_value' => 10000,
        ]);

        $rows = [
            // A receipt on an installment already on the schedule.
            installmentRow(['payment_date' => '05/03/2026', 'paid_value' => '10000.00']),
            // A new obligation reaches back to the sale.
            installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
            // A new expected value on an unpaid installment settles nothing.
            installmentRow(['number' => '003', 'due_date' => '10/12/2026', 'expected_value' => '12000.00']),
        ];
        $analysis = analyzeInstallmentSpreadsheet($rows);

        $byNumber = classifiedInstallmentRows($rows)->keyBy('number');

        expect($analysis->canImport())->toBeTrue()
            ->and($byNumber['001']['registered_competences'])->toBe(['2026-03', '2026-04'])
            ->and($byNumber['002']['registered_competences'])->toBe(['2026-02', '2026-03', '2026-04'])
            ->and($byNumber['003']['registered_competences'])->toBe([])
            ->and($analysis->registeredCompetenceCount())->toBe(2);
    });

    it('warns on the conference screen without blocking the import', function () {
        $this->actingAs(makeAdminUser());

        [$emission, $construction] = installmentImportScenario();
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-03-01']);

        $path = installmentSpreadsheet([installmentRow(['payment_date' => '05/03/2026', 'paid_value' => '10000.00'])]);

        $component = Livewire::test(ListContractInstallments::class);
        $preview = (fn (): string => $this->renderInstallmentPreview(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)->toHtml())->call($component->instance());

        expect($preview)
            ->toContain('Alteram competência já registrada no Quadro de Vendas: <b>1</b>')
            // O ⚑ traz o aviso da linha: o quadro de 03/2026 foi registrado à mão.
            ->toContain('Altera fato da competência 03/2026, registrada manualmente no Quadro de Vendas: revise o quadro dessa competência em “Nova Atualização”, informando o motivo.');

        $component->callAction(TestAction::make('importContractInstallments'), ['file' => spreadsheetUpload($path)])
            ->assertHasNoActionErrors();

        expect(ContractInstallment::query()->sole()->payment_date->toDateString())->toBe('2026-03-05');
    });
});

describe('linhas a corrigir na conferência', function () {
    it('keeps the rows that block the import apart, each group in the order of the file', function () {
        installmentImportScenario();

        $rows = [
            installmentRow(['contract' => 'CVC-999', 'number' => '001']),
            installmentRow(['number' => '002']),
            installmentRow(['number' => '003']),
            installmentRow(['number' => '002']),
        ];
        $analysis = analyzeInstallmentSpreadsheet($rows);

        $lines = classifiedInstallmentRows($rows)->pluck('line')->all();

        expect($analysis->blockingRows()->pluck('line')->all())->toBe([$lines[0], $lines[3]])
            ->and($analysis->blockingCount())->toBe(2)
            ->and($analysis->previewRows()->pluck('line')->all())->toBe([$lines[1], $lines[2]]);
    });

    it('lists every row to fix above the conference, as the file wrote it', function () {
        $this->actingAs(makeAdminUser());

        installmentImportScenario();

        $path = installmentSpreadsheet([
            installmentRow(['number' => '001']),
            installmentRow(['contract' => 'CVC-999', 'number' => '002']),
        ]);

        $component = Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload($path)])
            ->assertSchemaComponentVisible('installmentProblems');

        $page = $component->instance();
        $problems = $page->getSchema($page->getMountedActionSchemaName())->getComponent('installmentProblems');
        $preview = (fn (): string => $this->renderInstallmentPreview(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)->toHtml())->call($page);

        expect($problems->getHeading())->toBe('Linhas a corrigir na planilha: 1')
            ->and($problems->toEmbeddedHtml())
            ->toContain('Corrija estas linhas no arquivo e envie-o novamente na etapa Arquivo.')
            ->toContain('<td style="padding:.25rem .5rem;">CVC-999</td>')
            ->toContain('Contrato não encontrado.')
            ->and($preview)
            ->toContain('CVC-00123')
            ->not->toContain('CVC-999');
    });

    it('shows no rows to fix when nothing blocks the import', function () {
        $this->actingAs(makeAdminUser());

        installmentImportScenario();

        $path = installmentSpreadsheet([installmentRow()]);

        Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload($path)])
            ->assertSchemaComponentHidden('installmentProblems');
    });

    it('caps the rows to fix rendered on screen', function () {
        $this->actingAs(makeAdminUser());

        installmentImportScenario();

        $analysis = analyzeInstallmentSpreadsheet(collect(range(1, 201))
            ->map(fn (int $index): array => installmentRow(['contract' => 'CVC-999', 'number' => (string) $index]))
            ->all());

        $component = Livewire::test(ListContractInstallments::class)->instance();
        $problems = (fn (): string => $this->renderInstallmentProblems($analysis))->call($component);

        expect($analysis->blockingCount())->toBe(201)
            ->and(substr_count($problems, '<tr>'))->toBe(201)
            ->and($problems)->toContain('Exibindo as primeiras 200 de 201 linhas a corrigir, na ordem da planilha.');
    });
});

describe('prioridade da prévia', function () {
    /**
     * Sessenta parcelas cadastradas pagas com três vezes o previsto -- a
     * quitação antecipada lançada numa parcela, aceita pelo desenho -- reenviadas
     * iguais todo mês, cada uma com o aviso de pago acima do previsto, e a
     * parcela 061 com o previsto lido dez vezes maior na linha 62.
     *
     * @return list<array<int, string|null>>
     */
    function crowdedPreviewRows(Contract $contract): array
    {
        $now = now()->toDateTimeString();
        $records = [];
        $rows = [];

        foreach (range(1, 61) as $number) {
            $label = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $due = CarbonImmutable::parse('2020-01-10')->addMonths($number - 1);
            $paid = $number <= 60;

            $records[] = [
                'contract_id' => $contract->id,
                'number' => $label,
                'number_normalized' => ContractInstallment::normalizeNumberForComparison($label),
                'due_date' => $due->toDateString(),
                'expected_value' => '1000.00',
                'payment_date' => $paid ? $due->toDateString() : null,
                'paid_value' => $paid ? '3000.00' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $rows[] = installmentRow([
                'number' => $label,
                'due_date' => $due->format('d/m/Y'),
                'expected_value' => $paid ? '1000.00' : '10000.00',
                'payment_date' => $paid ? $due->format('d/m/Y') : '',
                'paid_value' => $paid ? '3000.00' : '',
            ]);
        }

        DB::table('contract_installments')->insert($records);

        return $rows;
    }

    /**
     * Uma atualização crítica nunca é gravada sem ter sido vista: as linhas sem
     * alteração com aviso vêm depois dela na prévia, por mais que sejam e
     * estejam antes dela no arquivo.
     */
    it('keeps a critical update among the rows shown when sixty unchanged rows with a warning come before it', function () {
        $this->actingAs(makeAdminUser());

        [, , $contract] = installmentImportScenario();
        $path = installmentSpreadsheet(crowdedPreviewRows($contract));

        $analysis = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path);
        $shown = $analysis->previewRows()->take(ContractInstallmentSpreadsheetAnalysis::PREVIEW_LIMIT);

        expect($analysis->criticalUpdateCount())->toBe(1)
            ->and($analysis->unchangedCount())->toBe(60)
            ->and($analysis->warningsByCode())->toBe([ImportRowWarningCode::PaidFarAboveExpected->value => 60])
            ->and($shown->first()['line'])->toBe(62)
            ->and($shown->first()['outcome'])->toBe(ReconciliationOutcome::CriticalUpdate)
            // As sem alteração com aviso continuam acima das sem aviso.
            ->and($shown->slice(1)->pluck('outcome')->unique()->values()->all())->toBe([ReconciliationOutcome::Unchanged]);

        Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload($path)])
            ->assertMountedActionModalSee('<b>Atualização crítica</b>', escape: false)
            ->assertMountedActionModalSee('R$ 10.000,00')
            ->assertMountedActionModalSee('em ordem de prioridade: alterações críticas e divergências, linhas gravadas com aviso ou competência registrada, linhas sem alteração com aviso');
    });

    /**
     * O ⚑ marca toda parcela nova de um contrato vendido antes de uma
     * competência registrada -- o aditivo de um contrato antigo traz dezenas
     * delas de uma vez. Elas ficam acima das atualizações comuns, mas abaixo da
     * atualização crítica de outro contrato.
     */
    it('keeps a critical update ahead of sixty new rows reaching a registered competence', function () {
        [$emission, $construction, $contract] = installmentImportScenario();
        $contract->forceFill(['sale_date' => '2025-06-10'])->save();

        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-03-01']);

        $other = Contract::factory()
            ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']))
            ->create(['code' => 'CVC-00402', 'sale_date' => '2025-06-10', 'sale_value' => 850000.00]);

        ContractInstallment::factory()->forContract($other)->create([
            'number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 1000,
        ]);

        $rows = [];

        foreach (range(1, 60) as $number) {
            $rows[] = installmentRow([
                'number' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'due_date' => CarbonImmutable::parse('2025-07-10')->addMonths($number - 1)->format('d/m/Y'),
                'expected_value' => '1000.00',
            ]);
        }

        $rows[] = installmentRow(['contract' => 'CVC-00402', 'due_date' => '10/01/2026', 'expected_value' => '10000.00']);

        $analysis = analyzeInstallmentSpreadsheet($rows);
        $shown = $analysis->previewRows()->take(ContractInstallmentSpreadsheetAnalysis::PREVIEW_LIMIT);

        expect($analysis->newCount())->toBe(60)
            ->and($analysis->registeredCompetenceCount())->toBe(60)
            ->and($analysis->criticalUpdateCount())->toBe(1)
            ->and($shown->first()['line'])->toBe(62)
            ->and($shown->first()['outcome'])->toBe(ReconciliationOutcome::CriticalUpdate)
            ->and($shown->slice(1)->every(fn (array $row): bool => ($row['outcome'] === ReconciliationOutcome::New) && ($row['registered_competences'] !== [])))->toBeTrue();
    });

    it('says how many critical updates did not fit the preview', function () {
        $this->actingAs(makeAdminUser());

        [, , $contract] = installmentImportScenario();

        $now = now()->toDateTimeString();
        $records = [];
        $rows = [];

        foreach (range(1, 52) as $number) {
            $label = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $due = CarbonImmutable::parse('2026-01-10')->addMonths($number - 1);

            $records[] = [
                'contract_id' => $contract->id, 'number' => $label, 'number_normalized' => $label,
                'due_date' => $due->toDateString(), 'expected_value' => '1000.00', 'created_at' => $now, 'updated_at' => $now,
            ];
            $rows[] = installmentRow(['number' => $label, 'due_date' => $due->format('d/m/Y'), 'expected_value' => '2000.00']);
        }

        DB::table('contract_installments')->insert($records);

        $analysis = analyzeInstallmentSpreadsheet($rows);
        $table = (fn (): string => $this->renderInstallmentTable($analysis))->call(Livewire::test(ListContractInstallments::class)->instance());

        expect($analysis->criticalUpdateCount())->toBe(52)
            ->and($table)->toContain('<b>2</b> alteração(ões) crítica(s) ou divergência(s) não cabem nesta tabela: confira-as na planilha antes de confirmar.');
    });
});

describe('parcelas cadastradas ausentes da planilha', function () {
    /**
     * Quatro parcelas do contrato no cadastro: a 001 vem na planilha; a 002
     * está em aberto, a 003 paga e a 004 já cancelada, e nenhuma delas vem.
     */
    function absenceScenario(): array
    {
        [$emission, $construction, $contract, $otherConstruction, $otherContract] = installmentImportScenario();

        $contract->forceFill(['sale_date' => '2025-12-01', 'sale_value' => 40000.00])->save();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 10000,
            'payment_date' => '2026-01-10', 'paid_value' => 10000,
        ]);
        $open = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '002', 'due_date' => '2026-02-10', 'expected_value' => 10000,
        ]);
        $paid = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '003', 'due_date' => '2026-03-10', 'expected_value' => 10000,
            'payment_date' => '2026-03-10', 'paid_value' => 10000,
        ]);
        $cancelled = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '004', 'due_date' => '2026-04-10', 'expected_value' => 10000, 'cancellation_date' => '2026-04-01',
        ]);

        // Another contract with an installment the file never mentions.
        ContractInstallment::factory()->forContract($otherContract)->create([
            'number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 10000,
        ]);

        return compact('emission', 'construction', 'contract', 'open', 'paid', 'cancelled', 'otherContract', 'otherConstruction');
    }

    function absenceFileRows(): array
    {
        return [
            installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 10000.00]),
            installmentRow(['number' => 'R01', 'due_date' => '10/05/2026', 'expected_value' => 20000.00, 'payment_date' => '15/06/2026', 'paid_value' => 20000.00]),
        ];
    }

    it('lists the absent installments of the contracts in the file, apart by situation', function () {
        ['open' => $open, 'paid' => $paid] = absenceScenario();

        $analysis = analyzeInstallmentSpreadsheet(absenceFileRows());

        expect($analysis->absentOpenCount())->toBe(1)
            ->and($analysis->absentPaidCount())->toBe(1)
            ->and($analysis->absentCancelledCount())->toBe(1)
            ->and($analysis->absentCount())->toBe(2)
            ->and($analysis->absentRows()->pluck('number')->all())->toBe(['002', '003'])
            ->and($analysis->absentRows()->pluck('situation')->all())->toBe(['em_aberto', 'paga'])
            ->and($analysis->absentRows()->pluck('contract')->unique()->all())->toBe(['CVC-00123'])
            ->and($open->exists && $paid->exists)->toBeTrue();
    });

    it('never lists installments of a contract the file does not carry', function () {
        absenceScenario();

        $analysis = analyzeInstallmentSpreadsheet(absenceFileRows());

        // Conviva Piratininga's CVC-00123 has an open installment too, but no line.
        expect($analysis->absentRows()->pluck('contract')->all())->each->toBe('CVC-00123')
            ->and($analysis->absentOpenCount())->toBe(1);
    });

    it('limits the absences to the contract of the page when imported from inside it', function () {
        ['contract' => $contract] = absenceScenario();

        $analysis = analyzeInstallmentSpreadsheet([
            ...absenceFileRows(),
            installmentRow(['construction' => 'Conviva Piratininga', 'number' => '009']),
        ], contractId: $contract->id);

        expect($analysis->errorCount())->toBe(1)
            ->and($analysis->absentOpenCount())->toBe(1)
            ->and($analysis->absentRows()->pluck('number')->all())->toBe(['002', '003']);
    });

    it('says the file left installments out instead of "nothing to update"', function () {
        $this->actingAs(makeAdminUser());

        absenceScenario();

        $path = installmentSpreadsheet([installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 10000.00])]);

        $component = Livewire::test(ListContractInstallments::class)->instance();
        $preview = (fn (): string => $this->renderInstallmentPreview(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)->toHtml())->call($component);
        $absences = (fn (): string => $this->renderInstallmentAbsences($this->installmentAnalysisFor(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)))->call($component);

        expect($preview)
            ->toContain('Ausentes da planilha: <b>1</b> em aberto, <b>1</b> pagas (1 já canceladas, ignoradas)')
            ->toContain('Nada a gravar nas linhas da planilha, mas 1 parcela(s) em aberto cadastrada(s) não vieram nela. Confira a lista abaixo.')
            ->not->toContain('Nada a atualizar')
            ->and($absences)
            ->toContain('Parcelas cadastradas que não vieram na planilha')
            ->toContain('Consideradas apenas as parcelas dos contratos presentes nesta planilha. Nada muda nelas, a menos que você marque a opção abaixo.')
            ->toContain('Em aberto')
            ->toContain('Paga em 10/03/2026');
    });

    it('cancels the absent open installments with the date and reason chosen, leaving the paid ones alone', function () {
        $this->actingAs(makeAdminUser());

        ['open' => $open, 'paid' => $paid, 'cancelled' => $cancelled] = absenceScenario();

        $path = installmentSpreadsheet(absenceFileRows());

        Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload($path)])
            ->assertFormFieldVisible('cancel_absent_open')
            ->fillForm([
                'cancel_absent_open' => true,
                'absent_cancellation_date' => '2026-07-05',
                'absent_cancellation_reason' => 'Renegociação com novo cronograma de parcelas.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $run = ImportRun::query()->sole();
        $cancellation = Activity::query()
            ->where('subject_type', ContractInstallment::class)
            ->where('subject_id', $open->id)
            ->where('event', 'updated')
            ->sole();

        expect($open->refresh()->cancellation_date->toDateString())->toBe('2026-07-05')
            ->and($paid->refresh()->cancellation_date)->toBeNull()
            ->and($cancelled->refresh()->cancellation_date->toDateString())->toBe('2026-04-01')
            ->and($run->records_cancelled)->toBe(1)
            ->and($run->records_absent)->toBe(2)
            ->and($run->absence_cancellation_date->toDateString())->toBe('2026-07-05')
            ->and($run->absence_cancellation_reason)->toBe('Renegociação com novo cronograma de parcelas.')
            ->and($run->result())->toBe(ImportRun::RESULT_CRITICAL)
            ->and($cancellation->log_name)->toBe('contract_installments')
            ->and($cancellation->batch_uuid)->toBe($run->batch_uuid)
            ->and($cancellation->attribute_changes['attributes']['cancellation_date'])->toStartWith('2026-07-05');
    });

    /**
     * A data escolhida para o cancelamento das ausentes passa pelo mesmo aviso
     * que o formulário da parcela dá: só avisa, nunca bloqueia. Para o quadro
     * registrado à mão, é o único sinal de que ele precisa ser revisto.
     */
    it('warns that the cancellation date reaches competences registered by hand, on the conference and after confirming', function () {
        $this->travelTo('2026-09-20 15:00:00');
        $this->actingAs(makeAdminUser());

        ['emission' => $emission, 'construction' => $construction, 'open' => $open] = absenceScenario();

        foreach (['2026-03-01', '2026-04-01'] as $month) {
            SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => $month]);
        }

        $notice = 'Altera fatos de 2 competências registradas manualmente no Quadro de Vendas (03/2026 a 04/2026): revise o quadro dessas competências em “Nova Atualização”, informando o motivo.';

        $component = Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload(installmentSpreadsheet(absenceFileRows()))])
            ->fillForm(['cancel_absent_open' => true, 'absent_cancellation_reason' => 'Renegociação com novo cronograma de parcelas.'])
            // A data padrão, hoje, não alcança competência registrada.
            ->assertSchemaComponentHidden('absentCancellationNotice')
            ->fillForm(['absent_cancellation_date' => '2026-03-15'])
            ->assertSchemaComponentVisible('absentCancellationNotice')
            ->assertMountedActionModalSee('Data em competência já registrada no Quadro de Vendas')
            ->assertMountedActionModalSee($notice);

        $component->callMountedAction()->assertHasNoActionErrors();

        $warning = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
            ->firstWhere('title', 'Fato em competência já registrada no Quadro de Vendas');

        expect($open->refresh()->cancellation_date->toDateString())->toBe('2026-03-15')
            ->and($warning)->not->toBeNull()
            ->and((string) $warning['body'])->toBe($notice)
            ->and($warning['status'])->toBe('warning');
    });

    it('says the cancellation enters as a late movement when the date falls in a competence published by the cycle', function () {
        $scenario = ExtemporaneousFixture::publishedJuly();
        $scenario['construction']->emission->forceFill(['name' => 'CRI Conviva'])->save();
        $scenario['construction']->forceFill(['development_name' => 'Conviva Camboinhas'])->save();
        $this->actingAs(makeAdminUser());

        $path = installmentSpreadsheet([installmentRow([
            'contract' => (string) $scenario['financed']->code,
            'due_date' => '10/04/2026',
            'expected_value' => '300000.00',
            'payment_date' => '09/04/2026',
            'paid_value' => '300000.00',
        ])]);

        $notice = 'O cancelamento de parcela de 28/07/2026 cai em competência já publicada no Quadro de Vendas (07/2026). A posição publicada não muda: o fato entra como movimento extemporâneo em 08/2026 e passa pela validação da construtora e pela Gestão. Para corrigir a posição publicada de 07/2026, a Gestão pode usar “Retificar competência”.';

        Livewire::test(ContractInstallmentsRelationManager::class, ['ownerRecord' => $scenario['financed'], 'pageClass' => ViewContract::class])
            ->mountAction(TestAction::make('importContractInstallments')->table())
            ->fillForm(['file' => spreadsheetUpload($path)])
            ->fillForm([
                'cancel_absent_open' => true,
                'absent_cancellation_date' => '2026-07-28',
                'absent_cancellation_reason' => 'Renegociação com novo cronograma de parcelas.',
            ])
            ->assertMountedActionModalSee($notice)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $warning = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
            ->firstWhere('title', 'Fato em competência já registrada no Quadro de Vendas');

        expect($scenario['financed']->installments()->where('number', '002')->sole()->cancellation_date->toDateString())->toBe('2026-07-28')
            ->and((string) $warning['body'])->toBe($notice);
    });

    /**
     * Roda o gancho logo depois de a passada de gravação ler as ausências,
     * dentro da transação do Confirmar -- o ponto em que outra tela pode pagar
     * ou cancelar uma delas.
     */
    function afterAbsencesAreReadOnConfirm(Closure $hook): void
    {
        $baseline = DB::transactionLevel();

        app()->bind(AnalyzeContractInstallmentSpreadsheet::class, fn (): AnalyzeContractInstallmentSpreadsheet => new class($hook, $baseline) extends AnalyzeContractInstallmentSpreadsheet
        {
            public function __construct(private readonly Closure $hook, private readonly int $baseline)
            {
                parent::__construct();
            }

            public function open(string $path, ?int $restrictToContractId = null): ContractInstallmentSpreadsheetReading
            {
                $reading = parent::open($path, $restrictToContractId);

                if ((DB::transactionLevel() <= $this->baseline) || ($reading->fileErrors() !== [])) {
                    return $reading;
                }

                $hook = $this->hook;

                return ContractInstallmentSpreadsheetReading::streaming((function () use ($reading, $hook): Generator {
                    yield from $reading->rows();

                    $absences = $reading->absences();
                    $hook();

                    return $absences;
                })());
            }
        });
    }

    /**
     * Paga por outra tela enquanto o Confirmar gravava, a ausente fica como está
     * -- e quem confirmou é avisado de que a lista da conferência não foi
     * cancelada inteira.
     */
    it('leaves alone, counts and reports an absent installment paid while the confirmation was writing', function () {
        $this->actingAs(makeAdminUser());

        ['open' => $open] = absenceScenario();

        afterAbsencesAreReadOnConfirm(function () use ($open): void {
            DB::table('contract_installments')->where('id', $open->id)->update(['payment_date' => '2026-02-10', 'paid_value' => '10000.00']);
        });

        Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload(installmentSpreadsheet(absenceFileRows()))])
            ->fillForm([
                'cancel_absent_open' => true,
                'absent_cancellation_date' => '2026-07-05',
                'absent_cancellation_reason' => 'Renegociação com novo cronograma de parcelas.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $completed = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
            ->firstWhere('title', 'Posição processada com sucesso.');
        $log = Activity::query()->where('log_name', 'importacao-parcelas')->sole();

        expect($open->refresh()->cancellation_date)->toBeNull()
            ->and($open->payment_date->toDateString())->toBe('2026-02-10')
            ->and(ImportRun::query()->sole()->records_cancelled)->toBe(0)
            ->and((string) $completed['body'])->toContain('1 parcela(s) ausente(s) não foram canceladas porque receberam pagamento ou cancelamento durante a confirmação.')
            ->and($log->properties['parcelas_ausentes_preservadas_por_mudanca_concorrente'])->toBe(1);
    });

    it('requires a date and a reason when the option is marked, and refuses a future date or one before 1990', function (string $case) {
        $this->actingAs(makeAdminUser());

        absenceScenario();

        [$data, $errors] = match ($case) {
            'sem data nem motivo' => [
                ['absent_cancellation_date' => null, 'absent_cancellation_reason' => ''],
                ['absent_cancellation_date' => 'required', 'absent_cancellation_reason' => 'required'],
            ],
            'motivo curto' => [
                ['absent_cancellation_date' => '2026-07-05', 'absent_cancellation_reason' => 'curto'],
                ['absent_cancellation_reason' => 'min'],
            ],
            'data futura' => [
                ['absent_cancellation_date' => now()->addYear()->toDateString(), 'absent_cancellation_reason' => 'Renegociação com novo cronograma.'],
                ['absent_cancellation_date' => 'before_or_equal'],
            ],
            'data anterior a 1990' => [
                ['absent_cancellation_date' => '1989-12-31', 'absent_cancellation_reason' => 'Renegociação com novo cronograma.'],
                ['absent_cancellation_date' => 'after_or_equal'],
            ],
        };

        Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload(installmentSpreadsheet(absenceFileRows()))])
            ->fillForm(['cancel_absent_open' => true, ...$data])
            ->callMountedAction()
            ->assertHasActionErrors($errors);

        expect(ImportRun::query()->count())->toBe(0);
    })->with(['sem data nem motivo', 'motivo curto', 'data futura', 'data anterior a 1990']);

    it('maps the date bounds to messages that say what is wrong', function () {
        $this->actingAs(makeAdminUser());

        absenceScenario();

        $component = Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload(installmentSpreadsheet(absenceFileRows()))])
            ->fillForm([
                'cancel_absent_open' => true,
                'absent_cancellation_date' => '1989-12-31',
                'absent_cancellation_reason' => 'Renegociação com novo cronograma.',
            ])
            ->callMountedAction();

        expect(collect($component->errors()->all()))->toContain('A data do cancelamento não pode ser anterior a 01/01/1990.');
    });

    /**
     * Cancelar as ausentes edita parcelas. Desde que importar passou a exigir
     * criar e editar (`ContractInstallmentResource::canImport()`), quem só cria
     * não chega ao assistente -- nem, com ele, à opção: a ação some, o mount
     * forjado não abre nada e nenhuma parcela é cancelada.
     */
    it('does not offer the import, nor its absence option, to a role that may only create installments, and ignores a forged mount', function () {
        $user = User::factory()->withTwoFactor()->create(['approved_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(['contract-installments.view', 'contract-installments.create']);
        $this->actingAs($user);

        ['open' => $open] = absenceScenario();

        Livewire::test(ListContractInstallments::class)
            ->assertActionHidden('importContractInstallments')
            ->call('mountAction', 'importContractInstallments')
            ->assertSet('mountedActions', []);

        expect($open->refresh()->cancellation_date)->toBeNull()
            ->and(ImportRun::query()->count())->toBe(0);
    });

    /**
     * A permissão é conferida de novo no servidor quando o Confirmar executa:
     * retirada no meio do assistente, depois de a opção ter sido marcada, nada
     * é gravado -- nem a importação, nem o cancelamento.
     */
    it('writes nothing, the cancellation included, when the permission to edit installments is revoked mid-wizard', function () {
        $user = User::factory()->withTwoFactor()->create(['approved_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(['contract-installments.view', 'contract-installments.create', 'contract-installments.update']);
        $this->actingAs($user);

        ['open' => $open] = absenceScenario();

        $component = Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload(installmentSpreadsheet(absenceFileRows()))])
            ->assertFormFieldVisible('cancel_absent_open')
            ->fillForm([
                'cancel_absent_open' => true,
                'absent_cancellation_date' => '2026-07-05',
                'absent_cancellation_reason' => 'Renegociação com novo cronograma de parcelas.',
            ]);

        $user->revokePermissionTo('contract-installments.update');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->callMountedAction();

        expect($open->refresh()->cancellation_date)->toBeNull()
            ->and(ImportRun::query()->count())->toBe(0);
    });

    /**
     * A renegociação renumerou o cronograma: a parcela 002 não vem mais, e sem o
     * cancelamento ela segurava a quitação para sempre. Cancelada na data
     * escolhida, a derivação deixa de contá-la a partir daí, e a quitação
     * aparece no mês do cancelamento.
     */
    it('settles a renumbered renegotiation once its orphan installment is cancelled', function () {
        $this->actingAs(makeAdminUser());

        ['construction' => $construction, 'contract' => $contract, 'paid' => $paid, 'cancelled' => $cancelled] = absenceScenario();

        // The renegotiation replaced 002 and 003; only the open one is cancelled.
        $paid->forceDelete();
        $cancelled->forceDelete();

        $path = installmentSpreadsheet(absenceFileRows());

        $junePosition = DerivationFixture::derive($construction, '2026-06-01');

        Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments'))
            ->fillForm(['file' => spreadsheetUpload($path)])
            ->fillForm([
                'cancel_absent_open' => true,
                'absent_cancellation_date' => '2026-07-05',
                'absent_cancellation_reason' => 'Renegociação com novo cronograma de parcelas.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $june = DerivationFixture::derive($construction, '2026-06-01');
        $july = DerivationFixture::derive($construction, '2026-07-01');

        expect(DerivationFixture::lineFor($junePosition, $contract->constructionUnit)->classification)->toBe(SalesBoardUnitClassification::Financed)
            ->and(DerivationFixture::lineFor($june, $contract->constructionUnit)->classification)->toBe(SalesBoardUnitClassification::Financed)
            ->and(DerivationFixture::lineFor($july, $contract->constructionUnit)->classification)->toBe(SalesBoardUnitClassification::Settled)
            ->and(collect($july->movements->settlements)->pluck('contractId')->all())->toBe([$contract->id]);
    });
});

describe('leitura dos valores em texto', function () {
    it('reads text with a comma as a decimal and warns about the other reading', function () {
        installmentImportScenario();

        $rows = classifiedInstallmentRows([
            installmentRow(['number' => '001', 'expected_value' => '600,00', 'payment_date' => '10/01/2026', 'paid_value' => '553,919']),
            installmentRow(['number' => '002', 'expected_value' => '553.919', 'due_date' => '10/02/2026']),
            installmentRow(['number' => '003', 'expected_value' => 553.919, 'due_date' => '10/03/2026']),
        ])->keyBy('number');

        expect($rows['001']['paid_value'])->toBe(553.92)
            ->and($rows['001']['warnings'])->toBe([[
                'code' => ImportRowWarningCode::AmbiguousAmountText->value,
                'message' => "Valor pago '553,919' lido como R$ 553,92 (vírgula como separador decimal). Se o valor é R$ 553.919,00, escreva 553.919,00 ou use célula numérica.",
            ]])
            ->and($rows['002']['expected_value'])->toBe(553919.00)
            ->and(collect($rows['002']['warnings'])->pluck('message')->first())
            ->toBe("Valor previsto '553.919' lido como R$ 553.919,00 (ponto como separador de milhar). Se o valor é R$ 553,92, escreva 553,92 ou use célula numérica.")
            ->and($rows['003']['expected_value'])->toBe(553.92)
            ->and($rows['003']['warnings'])->toBe([]);

        expect(analyzeInstallmentSpreadsheet([
            installmentRow(['number' => '001', 'expected_value' => '600,00', 'payment_date' => '10/01/2026', 'paid_value' => '553,919']),
        ])->warningCount())->toBe(1);
    });
});

describe('plausibilidade contra o valor da venda', function () {
    it('refuses an installment above twice the sale value', function (string $field) {
        installmentImportScenario();

        $row = match ($field) {
            'previsto' => installmentRow(['expected_value' => 1700000.01]),
            'pago' => installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 1700000.01]),
        };

        $classified = classifiedInstallmentRows([$row])->first();

        expect($classified['outcome'])->toBe(ReconciliationOutcome::Error)
            ->and($classified['message'])->toBe(sprintf(
                '%s (R$ 1.700.000,01) maior que o dobro do valor da venda do contrato (R$ 850.000,00): confira a leitura do valor.',
                $field === 'previsto' ? 'Valor previsto' : 'Valor pago',
            ));
    })->with(['previsto', 'pago']);

    it('accepts exactly twice the sale value, warning above the sale and about a payment far above the expected', function () {
        installmentImportScenario();

        $rows = classifiedInstallmentRows([
            installmentRow(['number' => '001', 'expected_value' => 1700000.00]),
            installmentRow(['number' => '002', 'expected_value' => 10000.00, 'payment_date' => '10/01/2026', 'paid_value' => 20000.00]),
        ])->keyBy('number');

        expect($rows['001']['outcome'])->toBe(ReconciliationOutcome::New)
            ->and(collect($rows['001']['warnings'])->pluck('code')->all())->toBe([ImportRowWarningCode::InstallmentAboveSaleValue->value])
            ->and($rows['002']['outcome'])->toBe(ReconciliationOutcome::New)
            ->and(collect($rows['002']['warnings'])->pluck('code')->all())->toBe([ImportRowWarningCode::PaidFarAboveExpected->value]);
    });
});

it('reports a corrupted xlsx as a file error, without an exception', function () {
    installmentImportScenario();

    $path = temporaryTestFilePath('parcelas-corrompida');

    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('[Content_Types].xml', '<Types>');
    $archive->addFromString('xl/workbook.xml', '<workbook><sheets><sheet');
    $archive->close();

    $analysis = app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path);

    expect($analysis->fileErrors)->toBe([AnalyzeContractInstallmentSpreadsheet::UNREADABLE_FILE_MESSAGE])
        ->and($analysis->canImport())->toBeFalse();
});

describe('desconto concedido na planilha', function () {
    it('imports the optional discount column and reconciles it like a receipt', function () {
        [, , $contract] = installmentImportScenario();

        $paidWithDiscount = fn (string $discount, string $number = '001'): array => installmentRow([
            'number' => $number,
            'payment_date' => '10/01/2026',
            'paid_value' => 9500.00,
            'discount' => $discount,
        ]);

        // Desconto numa parcela nova: gravado no insert em lote, e a parcela fica paga.
        importInstallmentRows([$paidWithDiscount('500.00')]);
        $installment = ContractInstallment::query()->where('contract_id', $contract->id)->sole();

        expect($installment->discount_value)->toBe('500.00')
            ->and($installment->status)->toBe(ContractInstallmentStatus::Paid);

        // Desconto onde não havia: o movimento normal de um mês.
        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '002', 'due_date' => '2026-01-10', 'expected_value' => 10000,
            'payment_date' => '2026-01-10', 'paid_value' => 9500,
        ]);

        $rows = classifiedInstallmentRows([
            $paidWithDiscount('400.00'),
            $paidWithDiscount('500.00', '002'),
        ])->keyBy('number');

        expect($rows['001']['outcome'])->toBe(ReconciliationOutcome::CriticalUpdate)
            ->and($rows['001']['message'])->toBe('Desconto: R$ 500,00 → R$ 400,00')
            ->and($rows['002']['outcome'])->toBe(ReconciliationOutcome::Update)
            ->and($rows['002']['message'])->toBe('Desconto: — → R$ 500,00');

        // Célula vazia com desconto cadastrado: mantido, como divergência informativa.
        $kept = classifiedInstallmentRows([$paidWithDiscount('')])->sole();

        expect($kept['outcome'])->toBe(ReconciliationOutcome::InformativeDivergence)
            ->and($kept['message'])->toBe('Desconto: R$ 500,00 → não consta na planilha (mantido; para retirar, edite a parcela)');

        // Arquivo sem a coluna: não diz nada sobre o desconto, e continua importando.
        $withoutColumn = array_values(array_diff(ContractInstallmentSpreadsheetColumns::headers(), [ContractInstallmentSpreadsheetColumns::DISCOUNT_VALUE]));
        $unchanged = classifiedInstallmentRows([array_slice($paidWithDiscount(''), 0, 9)], null, $withoutColumn)->sole();

        expect($unchanged['outcome'])->toBe(ReconciliationOutcome::Unchanged);

        $result = importInstallmentRows([$paidWithDiscount('400.00'), $paidWithDiscount('500.00', '002')]);

        expect($result->updated)->toBe(2)
            ->and($installment->fresh()->discount_value)->toBe('400.00')
            ->and(ContractInstallment::query()->where('number', '002')->sole()->discount_value)->toBe('500.00');
    });

    it('refuses a discount without payment or above the expected value', function (string $case) {
        installmentImportScenario();

        [$row, $error] = match ($case) {
            'sem pagamento' => [installmentRow(['discount' => '500.00']), 'Informe o pagamento junto com o desconto: desconto só existe na baixa da parcela.'],
            'acima do previsto' => [installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 100.00, 'discount' => '10000.01']), 'O desconto não pode superar o valor previsto da parcela.'],
            'negativo' => [installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 9500.00, 'discount' => '-500.00']), 'Desconto inválido: informe um valor maior que zero.'],
            'texto que não é valor' => [installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 9500.00, 'discount' => 'quinhentos']), 'Desconto inválido: informe um valor maior que zero.'],
        };

        $classified = classifiedInstallmentRows([$row])->sole();

        expect($classified['outcome'])->toBe(ReconciliationOutcome::Error)
            ->and($classified['message'])->toBe($error);

        // O par: zero é "sem desconto", como o zero do valor pago.
        $zero = classifiedInstallmentRows([installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 10000.00, 'discount' => '0'])])->sole();

        expect($zero['outcome'])->toBe(ReconciliationOutcome::New)
            ->and($zero['discount_cents'])->toBeNull();
    })->with(['sem pagamento', 'acima do previsto', 'negativo', 'texto que não é valor']);

    it('groups the absent partially paid installments apart from the paid ones, outside the cancellation option', function () {
        [, , $contract] = installmentImportScenario();

        ContractInstallment::factory()->forContract($contract)->create([
            'number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 10000,
            'payment_date' => '2026-01-10', 'paid_value' => 10000,
        ]);
        $partial = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '002', 'due_date' => '2026-02-10', 'expected_value' => 10000,
            'payment_date' => '2026-02-10', 'paid_value' => 9000,
        ]);
        $discounted = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '003', 'due_date' => '2026-03-10', 'expected_value' => 10000,
            'payment_date' => '2026-03-10', 'paid_value' => 9500, 'discount_value' => 500,
        ]);
        $open = ContractInstallment::factory()->forContract($contract)->create([
            'number' => '004', 'due_date' => '2026-04-10', 'expected_value' => 10000,
        ]);

        $file = [installmentRow(['payment_date' => '10/01/2026', 'paid_value' => 10000.00])];
        $analysis = analyzeInstallmentSpreadsheet($file);

        expect($analysis->absentOpenCount())->toBe(1)
            ->and($analysis->absentPaidCount())->toBe(1)
            ->and($analysis->absentPartialCount())->toBe(1)
            ->and($analysis->absentCount())->toBe(3)
            ->and($analysis->absentRows()->pluck('situation', 'number')->all())->toBe([
                '002' => 'parcialmente_paga',
                '003' => 'paga',
                '004' => 'em_aberto',
            ]);

        $this->actingAs(makeAdminUser());
        $path = installmentSpreadsheet($file);
        $component = Livewire::test(ListContractInstallments::class)->instance();
        $preview = (fn (): string => $this->renderInstallmentPreview(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)->toHtml())->call($component);
        $absences = (fn (): string => $this->renderInstallmentAbsences($this->installmentAnalysisFor(temporaryUploadWithContent('parcelas.xlsx', file_get_contents($path)), null)))->call($component);

        expect($preview)->toContain('Ausentes da planilha: <b>1</b> em aberto, <b>1</b> pagas, <b>1</b> parcialmente pagas (0 já canceladas, ignoradas)')
            ->and($absences)->toContain('Parcialmente paga em 10/02/2026 (R$ 9.000,00 de R$ 10.000,00)')
            ->and($absences)->toContain('Paga em 10/03/2026')
            ->and($absences)->toContain('1 parcela(s) parcialmente paga(s): o pago não cobre o previsto e o contrato continua financiado no Quadro de Vendas.');

        importInstallmentRows($file, cancellation: new AbsentInstallmentCancellation('2026-07-05', 'Renegociação com novo cronograma de parcelas.', null));

        expect($open->fresh()->cancellation_date->toDateString())->toBe('2026-07-05')
            ->and($partial->fresh()->cancellation_date)->toBeNull()
            ->and($discounted->fresh()->cancellation_date)->toBeNull();
    });
});
