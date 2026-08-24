<?php

use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetTemplate;
use App\Actions\ContractInstallments\ImportContractInstallmentsFromSpreadsheet;
use App\Enums\ContractInstallmentStatus;
use App\Enums\ReconciliationOutcome;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

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
    $path = tempnam(sys_get_temp_dir(), 'contract-installments-import-').'.xlsx';
    $headers ??= ContractInstallmentSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
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
    $path = app(ContractInstallmentSpreadsheetTemplate::class)->build();

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

    $this->get(route('admin.contract-installments.template.download'))
        ->assertSuccessful()
        ->assertDownload(ContractInstallmentSpreadsheetTemplate::DOWNLOAD_NAME);
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

    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'payment_date' => '10/01/2026',
        'paid_value' => '10000.00',
    ])]);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->newCount())->toBe(1);

    $row = $analysis->rowsToCreate()->first();

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

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(),
        installmentRow(['construction' => 'Conviva Piratininga', 'number' => '002']),
    ]);

    expect($analysis->newCount())->toBe(2);

    $rows = $analysis->rowsToCreate();

    expect($rows[0]['contract_id'])->toBe($contract->id)
        ->and($rows[1]['contract_id'])->toBe($otherContract->id)
        ->and($contract->id)->not->toBe($otherContract->id);
});

it('finds the contract when the spreadsheet cases the code differently', function () {
    [, , $contract] = installmentImportScenario();

    expect($contract->code)->toBe('CVC-00123');

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['contract' => 'cvc-00123', 'number' => '001']),
        installmentRow(['contract' => '  CVC-00123  ', 'number' => '002', 'due_date' => '10/02/2026']),
    ]);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->canImport())->toBeTrue()
        ->and($analysis->rowsToCreate()->pluck('contract_id')->unique()->all())->toBe([$contract->id]);
});

it('still picks the development apart when both carry the same code', function () {
    [, , $contract, , $otherContract] = installmentImportScenario();

    // Both developments hold a CVC-00123; only the development tells them apart.
    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['contract' => 'cvc-00123', 'number' => '001']),
        installmentRow(['construction' => 'Conviva Piratininga', 'contract' => 'CVC-00123', 'number' => '001']),
    ]);

    $rows = $analysis->rowsToCreate();

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
        ->and($analysis->collect()->first()['message'])->toBe('Contrato não encontrado.')
        ->and(Contract::query()->where('code', 'CVC-999')->exists())->toBeFalse();
});

it('says so when the code exists but under another development', function () {
    [, , , $otherConstruction] = installmentImportScenario();

    $lonely = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => '02', 'unit' => '202']))
        ->create(['code' => 'CVC-77777']);

    $analysis = analyzeInstallmentSpreadsheet([installmentRow(['contract' => 'CVC-77777'])]);

    expect($analysis->collect()->first()['message'])
        ->toBe('Contrato não encontrado neste empreendimento. Este código pertence a outro empreendimento.')
        ->and($lonely->construction_id)->toBe($otherConstruction->id);
});

it('never creates an emission or a development that does not exist', function () {
    installmentImportScenario();

    $missingEmission = analyzeInstallmentSpreadsheet([installmentRow(['emission' => 'CRI Inexistente'])]);
    $missingConstruction = analyzeInstallmentSpreadsheet([installmentRow(['construction' => 'Não existe'])]);

    expect($missingEmission->collect()->first()['message'])->toBe('Emissão não encontrada.')
        ->and($missingConstruction->collect()->first()['message'])->toBe('Empreendimento não encontrado.')
        ->and(Emission::query()->count())->toBe(1)
        ->and(Construction::query()->count())->toBe(2);
});

it('rejects a development that belongs to another emission', function () {
    installmentImportScenario();

    [, $otherEmissionConstruction] = unitEmissionAndConstruction('CRI Bellevue', 'Alto Bellevue');

    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'emission' => 'CRI Conviva',
        'construction' => 'Alto Bellevue',
    ])]);

    expect($analysis->collect()->first()['message'])
        ->toBe('O empreendimento informado não pertence à emissão selecionada.')
        ->and($otherEmissionConstruction->development_name)->toBe('Alto Bellevue');
});

it('refuses to attach a schedule to a deleted contract', function () {
    [, , $contract] = installmentImportScenario();

    $contract->delete();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow()]);

    expect($analysis->collect()->first()['message'])
        ->toBe('O contrato está excluído e não pode receber novas parcelas.');
});

it('points out the missing required fields', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'number' => '',
        'due_date' => '',
        'expected_value' => '',
    ])]);

    expect($analysis->collect()->first()['message'])
        ->toBe('Campos obrigatórios não preenchidos: Número, Vencimento, Valor Previsto.');
});

it('validates the dates and the amounts of every row', function () {
    installmentImportScenario();

    expect(analyzeInstallmentSpreadsheet([installmentRow(['due_date' => '31/02/2026'])])->collect()->first()['message'])
        ->toBe('Data de vencimento inválida. Utilize o formato dd/mm/aaaa.');

    expect(analyzeInstallmentSpreadsheet([installmentRow(['expected_value' => '0'])])->collect()->first()['message'])
        ->toBe('Valor previsto inválido: informe um valor maior que zero.');

    expect(analyzeInstallmentSpreadsheet([installmentRow(['expected_value' => 'abc'])])->collect()->first()['message'])
        ->toBe('Valor previsto inválido: informe um valor maior que zero.');

    expect(analyzeInstallmentSpreadsheet([installmentRow(['payment_date' => '10/13/2026', 'paid_value' => '10000.00'])])->collect()->first()['message'])
        ->toBe('Data do pagamento inválida. Utilize o formato dd/mm/aaaa.');

    expect(analyzeInstallmentSpreadsheet([installmentRow(['cancellation_date' => 'ontem'])])->collect()->first()['message'])
        ->toBe('Data de cancelamento inválida. Utilize o formato dd/mm/aaaa.');
});

it('refuses a payment date without a paid value, and the other way round', function () {
    installmentImportScenario();

    expect(analyzeInstallmentSpreadsheet([installmentRow(['payment_date' => '10/01/2026'])])->collect()->first()['message'])
        ->toBe('Informe o valor pago junto com a data do pagamento.');

    expect(analyzeInstallmentSpreadsheet([installmentRow(['paid_value' => '10000.00'])])->collect()->first()['message'])
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

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => '001', 'paid_value' => '0']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026', 'paid_value' => '0,00']),
        installmentRow(['number' => '003', 'due_date' => '10/03/2026', 'paid_value' => '0.00']),
    ]);

    expect($analysis->newCount())->toBe(3)
        ->and($analysis->canImport())->toBeTrue()
        ->and($analysis->rowsToCreate()->pluck('paid_value')->all())->toBe([null, null, null])
        ->and($analysis->rowsToCreate()->pluck('payment_date')->all())->toBe([null, null, null]);
});

it('persists a zero paid value as nothing received', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow(['paid_value' => '0'])]);

    app(ImportContractInstallmentsFromSpreadsheet::class)->handle($analysis);

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

    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'payment_date' => '27/04/2026',
        'paid_value' => '0',
    ])]);

    expect($analysis->newCount())->toBe(0)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->first()['message'])
        ->toBe('Valor pago inválido: informe um valor maior que zero.');
});

it('still reports text that is not a number in the paid value', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow(['paid_value' => 'pago'])]);

    expect($analysis->newCount())->toBe(0)
        ->and($analysis->collect()->first()['message'])
        ->toBe('Informe a data do pagamento junto com o valor pago.');
});

it('accepts a receipt above the expected value, for juros and multa', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'payment_date' => '15/02/2026',
        'paid_value' => '10850.00',
    ])]);

    expect($analysis->newCount())->toBe(1)
        ->and($analysis->rowsToCreate()->first()['paid_value'])->toBe(10850.00);
});

it('accepts values written in the brazilian format', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'expected_value' => '12.500,50',
        'payment_date' => '10/01/2026',
        'paid_value' => 'R$ 12.500,50',
    ])]);

    expect($analysis->rowsToCreate()->first()['expected_value'])->toBe(12500.50)
        ->and($analysis->rowsToCreate()->first()['paid_value'])->toBe(12500.50);
});

it('preserves the leading zeros and the worded numbers of the schedule', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => '001']),
        installmentRow(['number' => 'ENTRADA', 'due_date' => '05/12/2025']),
        installmentRow(['number' => 'INTERMEDIÁRIA 01', 'due_date' => '05/06/2026']),
    ]);

    expect($analysis->rowsToCreate()->pluck('number')->all())
        ->toBe(['001', 'ENTRADA', 'INTERMEDIÁRIA 01']);
});

it('rejects a parcela repeated inside the spreadsheet', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => '001']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
        installmentRow(['number' => '001', 'due_date' => '10/03/2026']),
    ]);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();

    $duplicated = $analysis->collect()->firstWhere('outcome', ReconciliationOutcome::DuplicatedInFile);

    expect($duplicated['line'])->toBe(4)
        ->and($duplicated['message'])->toStartWith('Parcela repetida na planilha (linha 2).');
});

it('treats numbers differing only by case as the same parcela inside the file', function () {
    installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => 'Entrada']),
        installmentRow(['number' => '001', 'due_date' => '10/02/2026']),
        installmentRow(['number' => 'entrada', 'due_date' => '10/03/2026']),
    ]);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();

    $duplicated = $analysis->collect()->firstWhere('outcome', ReconciliationOutcome::DuplicatedInFile);

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
    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'number' => ' entrada ',
        'expected_value' => '99999.00',
    ])]);

    expect($analysis->newCount())->toBe(0)
        ->and($analysis->criticalUpdateCount())->toBe(1)
        ->and($analysis->collect()->first()['installment_id'])->toBe(ContractInstallment::query()->sole()->id);

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

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => 'Intermediária 01']),
        installmentRow(['number' => '  entrada  ', 'due_date' => '05/12/2025']),
    ]);

    app(ImportContractInstallmentsFromSpreadsheet::class)->handle($analysis);

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

    $analysis = analyzeInstallmentSpreadsheet([installmentRow([
        'expected_value' => '99999.00',
        'due_date' => '20/12/2026',
    ])]);

    // Both the schedule and the amount moved, so it is a retificação -- flagged,
    // not applied behind anyone's back, and not silently discarded either.
    expect($analysis->criticalUpdateCount())->toBe(1)
        ->and($analysis->newCount())->toBe(0)
        ->and($analysis->canImport())->toBeTrue()
        ->and($analysis->collect()->first()['message'])
        ->toContain('Vencimento')
        ->and($analysis->collect()->first()['message'])
        ->toContain('Valor previsto');

    // Nothing is written until the import is confirmed.
    $stored = ContractInstallment::query()->sole();

    expect((float) $stored->expected_value)->toBe(10000.00)
        ->and($stored->due_date->toDateString())->toBe('2026-01-10');
});

it('persists the approved rows inside a single transaction', function () {
    [, , $contract, , $otherContract] = installmentImportScenario();

    $analysis = analyzeInstallmentSpreadsheet([
        installmentRow(['number' => '001', 'payment_date' => '10/01/2026', 'paid_value' => '10000.00']),
        installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
        installmentRow(['number' => 'ENTRADA', 'due_date' => '05/12/2025', 'expected_value' => '50000.00', 'cancellation_date' => '02/01/2026']),
        installmentRow(['construction' => 'Conviva Piratininga', 'number' => '001', 'due_date' => '10/04/2026']),
    ]);

    $result = app(ImportContractInstallmentsFromSpreadsheet::class)->handle($analysis);

    expect($result['created'])->toBe(4)
        ->and($result['contracts'])->toBe(2)
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

    expect(fn () => app(ImportContractInstallmentsFromSpreadsheet::class)->handle($analysis))
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

    $analysis = analyzeInstallmentSpreadsheet(
        [
            installmentRow(['number' => '001']),
            installmentRow(['construction' => 'Conviva Piratininga', 'number' => '002']),
        ],
        contractId: $contract->id,
    );

    expect($analysis->newCount())->toBe(1)
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->last()['message'])
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
    $storedPath = 'imports/contract-installments/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContractInstallments::class)
        ->assertActionExists('downloadInstallmentTemplate')
        ->assertActionHasLabel('downloadInstallmentTemplate', 'Baixar Modelo')
        ->assertActionExists('importContractInstallments')
        ->assertActionHasLabel('importContractInstallments', 'Importar Parcelas')
        ->callAction(TestAction::make('importContractInstallments'), ['file' => ['upload' => $storedPath]])
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
    $storedPath = 'imports/contract-installments/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), ['file' => ['upload' => $storedPath]]);

    expect(ContractInstallment::query()->count())->toBe(0);
});

it('imports the schedule from inside the contract page', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentImportScenario();

    $path = installmentSpreadsheet([installmentRow(['number' => '001'])]);
    $storedPath = 'imports/contract-installments/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->callAction(
            TestAction::make('importContractInstallments')->table(),
            ['file' => ['upload' => $storedPath]],
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

        $first = app(ImportContractInstallmentsFromSpreadsheet::class)->handle(analyzeInstallmentSpreadsheet($rows));

        expect($first['created'])->toBe(3)
            ->and($first['updated'])->toBe(0);

        $touchedAt = ContractInstallment::query()->orderBy('id')->pluck('updated_at', 'id');
        $activityBefore = Activity::query()->where('subject_type', ContractInstallment::class)->count();

        $second = analyzeInstallmentSpreadsheet($rows);

        expect($second->unchangedCount())->toBe(3)
            ->and($second->newCount())->toBe(0)
            ->and($second->updateCount())->toBe(0)
            ->and($second->criticalUpdateCount())->toBe(0)
            ->and($second->writeCount())->toBe(0)
            ->and($second->canImport())->toBeTrue();

        $result = app(ImportContractInstallmentsFromSpreadsheet::class)->handle($second);

        expect($result['created'])->toBe(0)
            ->and($result['updated'])->toBe(0)
            ->and($result['unchanged'])->toBe(3)
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

        $analysis = analyzeInstallmentSpreadsheet([installmentRow([
            'payment_date' => '25/08/2026',
            'paid_value' => '10000.00',
        ])]);

        expect($analysis->updateCount())->toBe(1)
            ->and($analysis->criticalUpdateCount())->toBe(0);

        $changes = $analysis->collect()->first()['comparison']->changes;

        expect(collect($changes)->pluck('field')->all())->toBe(['payment_date', 'paid_value'])
            ->and($changes[0]->currentForDisplay())->toBe('—')
            ->and($changes[0]->newForDisplay())->toBe('25/08/2026')
            ->and($changes[1]->newForDisplay())->toBe('R$ 10.000,00');

        app(ImportContractInstallmentsFromSpreadsheet::class)->handle($analysis);

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

        app(ImportContractInstallmentsFromSpreadsheet::class)->handle(
            analyzeInstallmentSpreadsheet([installmentRow(['payment_date' => '25/08/2026', 'paid_value' => '10000.00'])])
        );

        $activity = Activity::query()
            ->where('subject_type', ContractInstallment::class)
            ->where('subject_id', $installment->getKey())
            ->where('event', 'updated')
            ->sole();

        expect($activity->properties['old']['payment_date'])->toBeNull()
            ->and($activity->properties['attributes']['payment_date'])->toStartWith('2026-08-25')
            ->and((float) $activity->properties['attributes']['paid_value'])->toBe(10000.00);
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
            ->and($amountMoved->collect()->first()['message'])->toBe('Valor previsto: R$ 10.000,00 → R$ 8.000,00');
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

            expect($analysis->unchangedCount())->toBe(1)
                ->and($analysis->writeCount())->toBe(0);
        }

        app(ImportContractInstallmentsFromSpreadsheet::class)->handle(
            analyzeInstallmentSpreadsheet([installmentRow(['payment_date' => '', 'paid_value' => ''])])
        );

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

        app(ImportContractInstallmentsFromSpreadsheet::class)->handle(
            analyzeInstallmentSpreadsheet([installmentRow(['number' => '001'])])
        );

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

        $result = app(ImportContractInstallmentsFromSpreadsheet::class)->handle(analyzeInstallmentSpreadsheet([
            installmentRow(['number' => '001', 'payment_date' => '25/08/2026', 'paid_value' => '10000.00']),
            installmentRow(['number' => '002', 'due_date' => '10/02/2026']),
            installmentRow(['number' => '003', 'due_date' => '10/03/2026']),
        ]));

        expect($result['created'])->toBe(2)
            ->and($result['updated'])->toBe(1)
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
    $storedPath = 'imports/contract-installments/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), ['file' => ['upload' => $storedPath]])
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
        ->callAction(TestAction::make('importContractInstallments'), ['file' => ['upload' => $storedPath]])
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
