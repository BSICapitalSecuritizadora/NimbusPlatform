<?php

use App\Actions\ConstructionUnits\AnalyzeConstructionUnitSpreadsheet;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetColumns;
use App\Actions\ConstructionUnits\ImportConstructionUnitsFromSpreadsheet;
use App\Actions\ConstructionUnitValues\AnalyzeUnitValueSpreadsheet;
use App\Actions\ConstructionUnitValues\ImportUnitValuesFromSpreadsheet;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetColumns;
use App\Actions\ContractInstallments\AnalyzeContractInstallmentSpreadsheet;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\ContractInstallments\ImportContractInstallmentsFromSpreadsheet;
use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Actions\Contracts\ImportContractsFromSpreadsheet;
use App\Exceptions\ImportConferenceOutdatedException;
use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\ImportRun;
use App\Support\Imports\ImportRunDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Qual importação criou cada registro.
 *
 * Até aqui só as alterações ligavam ao arquivo, pelo `batch_uuid` da trilha; o
 * que uma importação criava em lote -- contratos, parcelas, unidades e linhas de
 * valor -- só se encontrava pelo horário. `import_run_id` é carimbado só no
 * insert da importação: uma alteração posterior não muda quem criou, e a criação
 * manual nunca o preenche.
 *
 * Grupo `parity`: coluna, índice e gravação dependem do banco; a esteira roda o
 * mesmo arquivo no MySQL.
 */
uses(RefreshDatabase::class);

pest()->group('parity');

/**
 * @param  list<string>  $headers
 * @param  list<array<int, mixed>>  $rows
 */
function provenanceSpreadsheet(string $prefix, array $headers, array $rows): string
{
    $path = temporaryTestFilePath($prefix);
    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, array_pad($row, count($headers), '')));
    }

    $writer->close();

    return $path;
}

function provenanceDraft(string $type, string $path): ImportRunDraft
{
    return new ImportRunDraft(
        type: $type,
        fileName: basename($path),
        checksum: hash_file('sha256', $path) ?: null,
        filePath: 'imports/teste/'.basename($path),
        userId: null,
    );
}

it('keeps a nullable, indexed import_run_id on the four tables', function (string $table) {
    $column = collect(Schema::getColumns($table))->firstWhere('name', 'import_run_id');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue()
        ->and(Schema::hasIndex($table, $table.'_import_run_id_index'))->toBeTrue();
})->with(['contracts', 'contract_installments', 'construction_units', 'construction_unit_values']);

it('stamps only the installments an import created, never the updated or the manual ones', function () {
    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']))
        ->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00123', 'sale_value' => 850000.00]);

    $updated = ContractInstallment::factory()->forContract($contract)->create([
        'number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 10000,
    ]);
    $manual = ContractInstallment::factory()->forContract($contract)->create([
        'number' => '099', 'due_date' => '2026-09-10', 'expected_value' => 10000,
    ]);

    $path = provenanceSpreadsheet('proveniencia-parcelas', ContractInstallmentSpreadsheetColumns::headers(), [
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', 10000.00, '10/01/2026', 10000.00, ''],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '002', '10/02/2026', 10000.00, '', '', ''],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '003', '10/03/2026', 10000.00, '', '', ''],
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '099', '10/09/2026', 10000.00, '', '', ''],
    ]);

    $result = app(ImportContractInstallmentsFromSpreadsheet::class)->handle(
        $path,
        null,
        provenanceDraft(ImportRun::TYPE_CONTRACT_INSTALLMENTS, $path),
        app(AnalyzeContractInstallmentSpreadsheet::class)->handle($path)->digest(),
    );

    $runId = $result->run->getKey();

    expect(ContractInstallment::query()->whereIn('number', ['002', '003'])->pluck('import_run_id')->unique()->all())->toBe([$runId])
        ->and($updated->refresh()->import_run_id)->toBeNull()
        ->and((float) $updated->paid_value)->toBe(10000.00)
        ->and($manual->refresh()->import_run_id)->toBeNull()
        ->and($result->run->createdInstallments()->count())->toBe(2)
        ->and(ContractInstallment::query()->where('number', '002')->sole()->importRun->is($result->run))->toBeTrue();
});

it('stamps only the contracts an import created', function () {
    [, $construction] = unitEmissionAndConstruction(status: 'active');

    ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);
    $manual = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']))
        ->forClient(Client::factory()->create(['document' => '52998224725']))
        ->create(['code' => 'CVC-00123']);

    $path = provenanceSpreadsheet('proveniencia-contratos', ContractSpreadsheetColumns::headers(), [
        ['CRI Conviva', 'Conviva Camboinhas', '01', '402', '52998224725', 'CVC-00500', '10/03/2024', 850000.00, 'Ativo', ''],
    ]);

    $result = app(ImportContractsFromSpreadsheet::class)->handle(
        app(AnalyzeContractSpreadsheet::class)->handle($path),
        provenanceDraft(ImportRun::TYPE_CONTRACTS, $path),
    );

    $created = Contract::query()->where('code', 'CVC-00500')->sole();

    expect($created->import_run_id)->toBe($result['run']->getKey())
        ->and($manual->refresh()->import_run_id)->toBeNull()
        ->and($result['run']->createdContracts()->count())->toBe(1)
        ->and($result['run']->records_created)->toBe(1)
        ->and($created->importRun->is($result['run']))->toBeTrue();
});

it('opens a run for units and for unit values, and counts what each one created', function () {
    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $manual = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);

    $unitsPath = provenanceSpreadsheet('proveniencia-unidades', ConstructionUnitSpreadsheetColumns::headers(), [
        ['CRI Conviva', 'Conviva Camboinhas', '02', '201', 500000.00, '01/01/2026'],
        ['CRI Conviva', 'Conviva Camboinhas', '02', '202', 510000.00, '01/01/2026'],
    ]);

    $units = app(ImportConstructionUnitsFromSpreadsheet::class)->handle(
        app(AnalyzeConstructionUnitSpreadsheet::class)->handle($unitsPath),
        provenanceDraft(ImportRun::TYPE_CONSTRUCTION_UNITS, $unitsPath),
    );

    $valuesPath = provenanceSpreadsheet('proveniencia-valores', UnitValueSpreadsheetColumns::headers(), [
        ['CRI Conviva', 'Conviva Camboinhas', '01', '305', 600000.00, '01/06/2026', 'Reajuste'],
        ['CRI Conviva', 'Conviva Camboinhas', '02', '201', 520000.00, '01/06/2026', 'Reajuste'],
    ]);

    $values = app(ImportUnitValuesFromSpreadsheet::class)->handle(
        app(AnalyzeUnitValueSpreadsheet::class)->handle($valuesPath),
        draft: provenanceDraft(ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES, $valuesPath),
    );

    $unitsRun = $units['run'];
    $valuesRun = $values['run'];

    expect($unitsRun->type)->toBe(ImportRun::TYPE_CONSTRUCTION_UNITS)
        ->and($unitsRun->records_created)->toBe(2)
        ->and($unitsRun->createdUnits()->count())->toBe(2)
        ->and($manual->refresh()->import_run_id)->toBeNull()
        ->and($valuesRun->type)->toBe(ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES)
        ->and($valuesRun->records_created)->toBe(2)
        ->and($valuesRun->createdUnitValues()->count())->toBe(2)
        ->and(ConstructionUnitValue::query()->pluck('import_run_id')->unique()->all())->toBe([$valuesRun->getKey()])
        ->and($unitsRun->coverageLabel())->toBe('Cadastro de unidades')
        ->and($valuesRun->coverageLabel())->toBe('Tabela de valores');
});

it('leaves no run and no stamped row behind when the import is rolled back', function () {
    [, $construction] = unitEmissionAndConstruction(status: 'active');

    Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']))
        ->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00123', 'sale_value' => 850000.00]);

    $path = provenanceSpreadsheet('proveniencia-desfeita', ContractInstallmentSpreadsheetColumns::headers(), [
        ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', 10000.00, '', '', ''],
    ]);

    // A digest that is not the one of the file: the pass that writes rolls back.
    expect(fn () => app(ImportContractInstallmentsFromSpreadsheet::class)->handle(
        $path,
        null,
        provenanceDraft(ImportRun::TYPE_CONTRACT_INSTALLMENTS, $path),
        hash('sha256', 'outra conferência'),
    ))->toThrow(ImportConferenceOutdatedException::class);

    expect(ImportRun::query()->count())->toBe(0)
        ->and(ContractInstallment::query()->count())->toBe(0);
});
