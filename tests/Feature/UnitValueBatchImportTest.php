<?php

use App\Actions\ConstructionUnitValues\AnalyzeUnitValueSpreadsheet;
use App\Actions\ConstructionUnitValues\ImportUnitValuesFromSpreadsheet;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetAnalysis;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetColumns;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetTemplate;
use App\Enums\UnitValueSource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Emission;
use App\Services\SalesBoards\UnitValueResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

/**
 * @param  list<array<int, string|null>>  $rows
 */
function unitValueSpreadsheet(array $rows, ?array $headers = null): string
{
    $path = temporaryTestFilePath('unit-values-import');
    $headers ??= UnitValueSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    return $path;
}

function analyzeUnitValues(array $rows, ?array $headers = null): UnitValueSpreadsheetAnalysis
{
    return app(AnalyzeUnitValueSpreadsheet::class)->handle(unitValueSpreadsheet($rows, $headers));
}

/**
 * @return array{0: Emission, 1: Construction, 2: ConstructionUnit}
 */
function unitValueFixture(?string $baseValue = null, ?string $baseValueReferenceDate = null): array
{
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);
    $unit = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => '01',
        'unit' => '101',
        'base_value' => $baseValue,
        'base_value_reference_date' => $baseValueReferenceDate,
    ]);

    return [$emission, $construction, $unit];
}

it('builds a template whose data sheet carries only the headers', function () {
    $path = discardTemplateFileAfterTest(app(UnitValueSpreadsheetTemplate::class)->build());

    $dataRows = SimpleExcelReader::create($path)->getRows()->all();
    $exampleRows = SimpleExcelReader::create($path)->fromSheetName(UnitValueSpreadsheetTemplate::EXAMPLE_SHEET)->getRows()->all();

    expect($dataRows)->toBe([])
        ->and($exampleRows)->toHaveCount(3)
        ->and(array_keys($exampleRows[0]))->toBe([
            'Emissão', 'Empreendimento', 'Bloco', 'Unidade', 'Valor Atualizado', 'Vigência', 'Motivo',
        ]);
});

it('records a first value for a unit that had none', function () {
    [, , $unit] = unitValueFixture();

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', 'Reajuste'],
    ]);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->newCount())->toBe(1);

    $result = app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis);

    $recorded = ConstructionUnitValue::sole();

    expect($result['created'])->toBe(1)
        ->and($recorded->construction_unit_id)->toBe($unit->id)
        ->and($recorded->value)->toBe('1000000.00')
        ->and($recorded->effective_from->toDateString())->toBe('2026-07-01')
        ->and($recorded->source)->toBe(UnitValueSource::SpreadsheetImport)
        ->and($recorded->reason)->toBe('Reajuste');
});

it('classifies a repricing over an existing base value as an update', function () {
    unitValueFixture('900000.00', '2026-01-01');

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', ''],
    ]);

    expect($analysis->updateCount())->toBe(1)
        ->and($analysis->newCount())->toBe(0)
        ->and($analysis->collect()->first()['current_value_cents'])->toBe(90_000_000);
});

it('is idempotent: re-sending the same file writes nothing', function () {
    unitValueFixture('900000.00', '2026-01-01');

    $rows = [['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', 'Reajuste']];

    app(ImportUnitValuesFromSpreadsheet::class)->handle(analyzeUnitValues($rows));

    expect(ConstructionUnitValue::count())->toBe(1);

    $second = analyzeUnitValues($rows);

    expect($second->unchangedCount())->toBe(1)
        ->and($second->writesAnything())->toBeFalse()
        ->and($second->canImport())->toBeTrue();

    $result = app(ImportUnitValuesFromSpreadsheet::class)->handle($second);

    expect($result['created'])->toBe(0)
        ->and($result['unchanged'])->toBe(1)
        ->and(ConstructionUnitValue::count())->toBe(1);
});

it('appends a correction for the same effective date without touching the previous line', function () {
    [, , $unit] = unitValueFixture();

    app(ImportUnitValuesFromSpreadsheet::class)->handle(analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', 'Lançamento'],
    ]));

    $original = ConstructionUnitValue::sole();

    $correction = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.010.000,00', '01/07/2026', 'Correção'],
    ]);

    expect($correction->updateCount())->toBe(1);

    app(ImportUnitValuesFromSpreadsheet::class)->handle($correction);

    $resolved = app(UnitValueResolver::class)->forUnit($unit, CarbonImmutable::parse('2026-07-31'));

    expect(ConstructionUnitValue::count())->toBe(2)
        ->and($original->fresh()->value)->toBe('1000000.00')
        ->and($resolved->valueCents)->toBe(101_000_000);
});

it('treats two identical lines for the same unit and date as a duplicate', function () {
    unitValueFixture();

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', ''],
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', ''],
    ]);

    expect($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();
});

it('treats two conflicting lines for the same unit and date as a conflict', function () {
    unitValueFixture();

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', ''],
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.010.000,00', '01/07/2026', ''],
    ]);

    expect($analysis->conflictCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->last()['message'])->toContain('outro valor');
});

it('never creates a unit that is not registered', function () {
    unitValueFixture();

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '999', '1.000.000,00', '01/07/2026', ''],
    ]);

    expect($analysis->errorCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->first()['message'])->toContain('não está cadastrada')
        ->and(ConstructionUnit::count())->toBe(1);
});

it('reads brazilian money and day-first dates', function () {
    [, , $unit] = unitValueFixture();

    app(ImportUnitValuesFromSpreadsheet::class)->handle(analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', 'R$ 1.234.567,89', '03/09/2026', ''],
    ]));

    $recorded = ConstructionUnitValue::sole();

    expect($recorded->value)->toBe('1234567.89')
        ->and($recorded->effective_from->toDateString())->toBe('2026-09-03');
});

it('rejects an invalid effective date', function () {
    unitValueFixture();

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '31/02/2026', ''],
    ]);

    expect($analysis->errorCount())->toBe(1)
        ->and($analysis->collect()->first()['message'])->toContain('Vigência inválida');
});

it('accepts a future effective date and keeps it out of earlier queries', function () {
    [, , $unit] = unitValueFixture('900000.00', '2026-01-01');

    app(ImportUnitValuesFromSpreadsheet::class)->handle(analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.100.000,00', '01/10/2026', 'Tabela futura'],
    ]));

    $resolver = app(UnitValueResolver::class);

    expect($resolver->forUnit($unit, CarbonImmutable::parse('2026-09-30'))->valueCents)->toBe(90_000_000)
        ->and($resolver->forUnit($unit, CarbonImmutable::parse('2026-10-01'))->valueCents)->toBe(110_000_000);
});

it('writes nothing at all when a single row is blocking', function () {
    unitValueFixture();

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', ''],
        ['CRI Alfa', 'Residencial Alfa', '01', '999', '1.000.000,00', '01/07/2026', ''],
    ]);

    expect($analysis->canImport())->toBeFalse()
        ->and(fn () => app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis))
        ->toThrow(RuntimeException::class)
        ->and(ConstructionUnitValue::count())->toBe(0);
});

it('falls back to the batch reason when the row has none', function () {
    unitValueFixture();

    app(ImportUnitValuesFromSpreadsheet::class)->handle(
        analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/07/2026', '']]),
        batchReason: 'Reajuste anual da tabela',
    );

    expect(ConstructionUnitValue::sole()->reason)->toBe('Reajuste anual da tabela');
});

it('reports the file as unreadable when a required column is missing', function () {
    $analysis = analyzeUnitValues(
        [['CRI Alfa', 'Residencial Alfa', '01', '101']],
        ['Emissão', 'Empreendimento', 'Bloco', 'Unidade'],
    );

    expect($analysis->fileErrors)->not->toBe([])
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->fileErrors[0])->toContain('Valor Atualizado');
});

it('analyses hundreds of units without a query per row', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $rows = [];

    foreach (range(1, 200) as $number) {
        ConstructionUnit::factory()->create([
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => (string) $number,
            'base_value' => '900000.00',
            'base_value_reference_date' => '2026-01-01',
        ]);

        $rows[] = ['CRI Alfa', 'Residencial Alfa', '01', (string) $number, '1.000.000,00', '01/07/2026', ''];
    }

    $path = unitValueSpreadsheet($rows);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $analysis = app(AnalyzeUnitValueSpreadsheet::class)->handle($path);

    $analysisQueries = count(DB::getQueryLog());
    DB::flushQueryLog();

    app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis);

    $importQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($analysis->newCount() + $analysis->updateCount())->toBe(200)
        ->and(ConstructionUnitValue::count())->toBe(200)
        // A single chunked insert plus the transaction statements, never one
        // insert per row.
        ->and($importQueries)->toBeLessThan(10)
        ->and($analysisQueries)->toBeLessThan(200 * 5);
});
