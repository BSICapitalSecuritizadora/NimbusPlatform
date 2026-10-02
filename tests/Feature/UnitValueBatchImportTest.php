<?php

use App\Actions\ConstructionUnitValues\AnalyzeUnitValueSpreadsheet;
use App\Actions\ConstructionUnitValues\ImportUnitValuesFromSpreadsheet;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetAnalysis;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetColumns;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetTemplate;
use App\Enums\ContractStatus;
use App\Enums\ImportRowWarningCode;
use App\Enums\ReconciliationOutcome;
use App\Enums\SalesBoardIssueCode;
use App\Enums\UnitValueSource;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitRetirement;
use App\Models\ConstructionUnitValue;
use App\Models\Emission;
use App\Models\ImportRun;
use App\Models\SalesBoard;
use App\Services\SalesBoards\UnitValueResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Support\SalesBoards\DerivationFixture;

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

/**
 * O valor de uma unidade baixada é gravado -- inofensivo enquanto ela estiver
 * baixada, volta a contar se for reativada --, com o aviso de que não muda o
 * Quadro agora. A baixa encerrada não avisa: a unidade já voltou a contar.
 */
it('warns that the value of a retired unit only counts once it is reactivated', function () {
    [, $construction, $unit] = unitValueFixture();
    $reactivated = ConstructionUnit::factory()->create(['construction_id' => $construction->id, 'block' => '01', 'unit' => '102']);

    ConstructionUnitRetirement::factory()->forUnit($unit)->retiredOn('2026-08-10')->create();
    ConstructionUnitRetirement::factory()->forUnit($reactivated)->retiredOn('2026-06-10')->reactivatedOn('2026-07-01')->create();

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '1.000.000,00', '01/09/2026', 'Reajuste'],
        ['CRI Alfa', 'Residencial Alfa', '01', '102', '1.000.000,00', '01/09/2026', 'Reajuste'],
    ]);

    $rows = $analysis->collect()->keyBy('line');

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->newCount())->toBe(2)
        ->and($analysis->warningCount())->toBe(1)
        ->and($rows[2]['warnings'])->toBe([[
            'code' => ImportRowWarningCode::RetiredUnit->value,
            'message' => 'A unidade está baixada desde 10/08/2026: o valor é gravado, mas só conta se ela for reativada.',
        ]])
        ->and($rows[3]['warnings'])->toBe([]);

    app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis);

    expect(ConstructionUnitValue::query()->where('construction_unit_id', $unit->id)->count())->toBe(1);
});

/**
 * Uma planilha salva no Excel guarda a competência como célula de data, e o leitor
 * devolve um objeto -- não texto. Foi assim que a importação estourou no primeiro
 * uso real, antes de qualquer linha ser analisada.
 */
it('reads a date-formatted cell as the competence instead of failing', function () {
    [, , $unit] = unitValueFixture();

    $path = temporaryTestFilePath('unit-values-real-date');
    $headers = UnitValueSpreadsheetColumns::headers();
    $dateStyle = (new Style)->setFormat('dd/mm/yyyy');

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));
    $writer->addRow(new Row([
        Cell::fromValue('CRI Alfa'),
        Cell::fromValue('Residencial Alfa'),
        Cell::fromValue('01'),
        Cell::fromValue('101'),
        Cell::fromValue('1.000.000,00'),
        Cell::fromValue(new DateTimeImmutable('2026-07-01'), $dateStyle),
        Cell::fromValue('Tabela de preços'),
    ]));
    $writer->close();

    $analysis = app(AnalyzeUnitValueSpreadsheet::class)->handle($path);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->newCount())->toBe(1);

    app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis);

    $recorded = ConstructionUnitValue::sole();

    expect($recorded->construction_unit_id)->toBe($unit->id)
        ->and($recorded->effective_from->toDateString())->toBe('2026-07-01')
        ->and($recorded->value)->toBe('1000000.00');
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

describe('leitura estrita da vigência', function () {
    /**
     * Anything outside dd/mm/aaaa used to fall into Carbon::parse(), which reads
     * slashes month first: "03/09/26" (3 de setembro) was recorded as 9 de março,
     * and the new value applied six months early with no finding.
     */
    it('refuses a two digit year, an implausible year and a bare serial number', function (mixed $effectiveFrom) {
        unitValueFixture('400000.00', '2026-01-01');

        $analysis = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', $effectiveFrom, 'Reajuste'],
        ]);

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->collect()->first()['effective_from_date'])->toBeNull()
            ->and($analysis->collect()->first()['message'])
            ->toBe('Vigência inválida. Utilize o formato dd/mm/aaaa, com o ano completo (a partir de 1990).');
    })->with([
        'two digit year' => ['03/09/26'],
        'dashes and two digit year' => ['03-09-26'],
        'implausible year' => ['03/09/0026'],
        'bare serial number' => [46000.5],
    ]);

    it('reads the time an export appends as the same day, never month first', function (string $effectiveFrom) {
        unitValueFixture('400000.00', '2026-01-01');

        $analysis = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', $effectiveFrom, 'Reajuste'],
        ]);

        expect($analysis->collect()->first()['effective_from_date'])->toBe('2026-09-03');
    })->with([
        'with time' => ['03/09/2026 00:00:00'],
        'with short time' => ['03/09/2026 10:00'],
        'iso' => ['2026-09-03'],
    ]);

    it('shows the interpreted date on the conference, not the text of the file', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(makeAdminUser());

        unitValueFixture('400000.00', '2026-01-01');

        $path = unitValueSpreadsheet([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', '03/09/2026 00:00:00', 'Reajuste'],
        ]);

        $component = Livewire::test(ListConstructionUnits::class)->instance();
        $preview = (fn (): string => $this->renderValuePreview(temporaryUploadWithContent('planilha.xlsx', file_get_contents($path)))->toHtml())->call($component);

        expect($preview)
            ->toContain('<td style="padding:.25rem .5rem;">03/09/2026</td>')
            ->not->toContain('03/09/2026 00:00:00');
    });
});

/**
 * Zero was accepted as a table price. Used as the "no price yet" placeholder,
 * it made every sale of the unit conform and the stock publish at R$ 0,00.
 */
it('refuses a zero value', function () {
    unitValueFixture('400000.00', '2026-01-01');

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '0,00', '01/07/2026', 'Sem preço'],
    ]);

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->first()['message'])->toBe('O valor atualizado precisa ser maior que zero.');
});

it('reads a numeric value cell for the number it holds', function () {
    unitValueFixture();

    $path = temporaryTestFilePath('unit-values-numeric');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(UnitValueSpreadsheetColumns::headers()));
    $writer->addRow(Row::fromValues(['CRI Alfa', 'Residencial Alfa', '01', '101', 153.919, '01/07/2026', 'Reajuste']));
    $writer->close();

    $analysis = app(AnalyzeUnitValueSpreadsheet::class)->handle($path);

    expect($analysis->collect()->first()['value_cents'])->toBe(15392);
});

/**
 * The development used to be looked up by name across every emission, and only
 * then checked against the emission of the row. A homonym of another series
 * found first made the right row read "não pertence à emissão".
 */
it('resolves the development inside the emission of the row', function () {
    $otherEmission = Emission::factory()->create(['name' => 'CRI Alfa 1ª Série']);
    Construction::factory()->create(['emission_id' => $otherEmission->id, 'development_name' => 'Residencial Alfa']);

    [, $construction, $unit] = unitValueFixture('400000.00', '2026-01-01');

    $analysis = analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', '01/07/2026', 'Reajuste'],
    ]);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->collect()->first()['construction_id'])->toBe($construction->id)
        ->and($analysis->collect()->first()['construction_unit_id'])->toBe($unit->id);

    Construction::factory()->create(['emission_id' => $construction->emission_id, 'development_name' => 'RESIDENCIAL ALFA']);

    expect(analyzeUnitValues([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', '01/07/2026', 'Reajuste'],
    ])->collect()->first()['message'])
        ->toBe('Há mais de um empreendimento com este nome nesta emissão. Diferencie os nomes antes de importar.');
});

it('flags a repricing whose effective date reaches a registered competence of the Sales Board', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    [$emission, $construction] = unitValueFixture('400000.00', '2026-01-01');

    foreach (['2026-05-01', '2026-08-01'] as $month) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => $month]);
    }

    $retroactive = analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', '01/07/2026', 'Reajuste']]);
    $ahead = analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', '01/09/2026', 'Reajuste']]);

    expect($retroactive->collect()->first()['registered_competences'])->toBe(['2026-08'])
        ->and($retroactive->canImport())->toBeTrue()
        ->and($ahead->registeredCompetenceCount())->toBe(0);

    $path = unitValueSpreadsheet([['CRI Alfa', 'Residencial Alfa', '01', '101', '500.000,00', '01/05/2026', 'Reajuste']]);

    $component = Livewire::test(ListConstructionUnits::class)->instance();
    $preview = (fn (): string => $this->renderValuePreview(temporaryUploadWithContent('planilha.xlsx', file_get_contents($path)))->toHtml())->call($component);

    expect($preview)
        ->toContain('Alcançam competência já registrada no Quadro de Vendas: <b>1</b>')
        // O ⚑ traz o aviso da linha: os dois quadros foram registrados à mão.
        ->toContain('Altera fatos de 2 competências registradas manualmente no Quadro de Vendas (05/2026 a 08/2026): revise o quadro dessas competências em “Nova Atualização”, informando o motivo.');
});

describe('ordem da conferência', function () {
    it('puts the blocking rows first, then warnings, divergences and registered competences, then the rest', function () {
        [, $construction, $unit] = unitValueFixture('400000.00', '2026-01-01');

        $others = collect(range(102, 106))->map(fn (int $number): ConstructionUnit => ConstructionUnit::factory()->create([
            'construction_id' => $construction->id, 'block' => '01', 'unit' => (string) $number,
            'base_value' => '400000.00', 'base_value_reference_date' => '2026-01-01',
        ]));

        $analysis = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '102', '410.000,00', '01/07/2026', 'Reajuste'],
            ['CRI Alfa', 'Residencial Alfa', '01', '103', '400.000,00', '01/01/2026', ''],
            ['CRI Alfa', 'Residencial Alfa', '01', '104', '900.000,00', '01/07/2026', 'Reajuste'],
            ['CRI Alfa', 'Residencial Alfa', '01', '999', '410.000,00', '01/07/2026', ''],
            ['CRI Alfa', 'Residencial Alfa', '01', '105', '415.000,00', '01/07/2026', 'Reajuste'],
        ]);

        expect($analysis->previewRows()->pluck('line')->all())->toBe([5, 4, 2, 6, 3])
            ->and($analysis->warningCount())->toBe(1)
            ->and($others)->toHaveCount(5)
            ->and($unit->id)->toBeGreaterThan(0);
    });
});

describe('histórico projetado por unidade', function () {
    /**
     * O leitor antigo gravou 420.000,00 com vigência 09/03/2026, que era
     * 03/09/2026 com dia e mês trocados. A correção vem numa planilha só: a
     * linha compensatória devolve o valor anterior a partir da data errada, e a
     * linha certa grava o novo valor na data certa.
     */
    it('fixes the history with a compensating and a corrected line in one file, in either order', function (string $order) {
        [, , $unit] = unitValueFixture('400000.00', '2026-01-01');

        ConstructionUnitValue::factory()->create([
            'construction_unit_id' => $unit->id, 'value' => '420000.00', 'effective_from' => '2026-03-09',
        ]);

        $compensating = ['CRI Alfa', 'Residencial Alfa', '01', '101', '400.000,00', '09/03/2026', 'Compensação da vigência trocada'];
        $corrected = ['CRI Alfa', 'Residencial Alfa', '01', '101', '420.000,00', '03/09/2026', 'Reajuste'];

        $analysis = analyzeUnitValues($order === 'compensatória primeiro' ? [$compensating, $corrected] : [$corrected, $compensating]);

        expect($analysis->updateCount())->toBe(2)
            ->and($analysis->canImport())->toBeTrue();

        app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis);

        $resolver = app(UnitValueResolver::class);

        expect($resolver->forUnit($unit->refresh(), CarbonImmutable::parse('2026-05-31'))->valueCents)->toBe(40_000_000)
            ->and($resolver->forUnit($unit, CarbonImmutable::parse('2026-09-30'))->valueCents)->toBe(42_000_000);
    })->with(['compensatória primeiro', 'corrigida primeiro']);

    it('reads the same value in force from a swapped day and month as an informative divergence that writes nothing', function () {
        [, , $unit] = unitValueFixture('400000.00', '2026-01-01');

        ConstructionUnitValue::factory()->create([
            'construction_unit_id' => $unit->id, 'value' => '420000.00', 'effective_from' => '2026-03-09',
        ]);

        $analysis = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '420.000,00', '03/09/2026', 'Reajuste'],
        ]);
        $row = $analysis->collect()->first();

        expect($row['outcome'])->toBe(ReconciliationOutcome::InformativeDivergence)
            ->and($row['message'])->toBe('O valor já vigora desde 09/03/2026, que é 03/09/2026 com dia e mês trocados (leitura antiga de planilha). Nada será gravado por esta linha. Se a vigência registrada estiver errada, inclua nesta planilha uma linha da mesma unidade com o valor anterior (R$ 400.000,00) e vigência 09/03/2026.')
            ->and($row['current_effective_from'])->toBe('2026-03-09')
            ->and($analysis->informativeDivergenceCount())->toBe(1)
            ->and($analysis->canImport())->toBeTrue();

        $result = app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis);

        expect($result['created'])->toBe(0)
            ->and(ConstructionUnitValue::query()->count())->toBe(1);
    });

    it('keeps the same value from an earlier date without the signature as unchanged, saying since when', function () {
        [, , $unit] = unitValueFixture('400000.00', '2026-01-01');

        ConstructionUnitValue::factory()->create([
            'construction_unit_id' => $unit->id, 'value' => '420000.00', 'effective_from' => '2026-02-15',
        ]);

        $row = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '420.000,00', '03/09/2026', 'Reajuste'],
        ])->collect()->first();

        expect($row['outcome'])->toBe(ReconciliationOutcome::Unchanged)
            ->and($row['message'])->toBe('Já vigora desde 15/02/2026.');
    });

    it('reads a value in force from before 1990 as an informative divergence', function () {
        [, , $unit] = unitValueFixture();

        DB::table('construction_unit_values')->insert([
            'construction_unit_id' => $unit->id, 'value' => '420000.00', 'effective_from' => '1970-01-01',
            'source' => 'spreadsheet_import', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '420.000,00', '01/07/2026', 'Reajuste'],
        ])->collect()->first();

        expect($row['outcome'])->toBe(ReconciliationOutcome::InformativeDivergence)
            ->and($row['message'])->toStartWith('O valor já vigora desde 01/01/1970, data anterior a 1990 (leitura antiga de planilha). Nada será gravado por esta linha.')
            ->and($row['message'])->toContain('vigência 01/01/1990');
    });

    it('shows the effective date on record beside the value in force', function () {
        $this->actingAs(makeSalesBoardAdminUser());

        [, , $unit] = unitValueFixture('400000.00', '2026-01-01');

        $path = unitValueSpreadsheet([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '420.000,00', '01/07/2026', 'Reajuste'],
        ]);

        $component = Livewire::test(ListConstructionUnits::class)->instance();
        $preview = (fn (): string => $this->renderValuePreview(temporaryUploadWithContent('planilha.xlsx', file_get_contents($path)))->toHtml())->call($component);

        expect($preview)
            ->toContain('<th style="text-align:left;padding:.25rem .5rem;">Vigência registrada</th>')
            ->toContain('01/01/2026')
            ->and($unit->id)->toBeGreaterThan(0);
    });
});

describe('plausibilidade contra o valor vigente', function () {
    it('warns at twice the value in force and refuses ten times', function (string $case) {
        unitValueFixture('400000.00', '2026-01-01');

        [$value, $outcome, $warning] = match ($case) {
            'o dobro' => ['800.000,00', ReconciliationOutcome::Update, ImportRowWarningCode::UnitValueFarFromCurrent->value],
            'dez vezes' => ['4.000.000,00', ReconciliationOutcome::Error, null],
            'um décimo' => ['40.000,00', ReconciliationOutcome::Error, null],
            'reajuste comum' => ['420.000,00', ReconciliationOutcome::Update, null],
        };

        $row = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', $value, '01/07/2026', 'Reajuste'],
        ])->collect()->first();

        expect($row['outcome'])->toBe($outcome)
            ->and(collect($row['warnings'])->pluck('code')->first())->toBe($warning);
    })->with(['o dobro', 'dez vezes', 'um décimo', 'reajuste comum']);
});

/**
 * Sem valor vigente na data, a linha passa a ser a referência das vendas da
 * unidade -- a derivação mede a venda contra a tabela da data da venda e, sem
 * ela, contra a da data da posição. A importação mede igual: antes, o primeiro
 * valor de uma unidade já vendida entrava sem conferência, e um zero a menos
 * bloqueava a venda na apuração seguinte.
 */
describe('valor sem vigente contra as vendas da unidade', function () {
    /**
     * A unidade 01/101, sem valor nenhum, vendida em 10/01/2026 por
     * R$ 600.000,00, com parcela em aberto.
     *
     * @return array{construction: Construction, unit: ConstructionUnit}
     */
    function unitValueSoldUnitFixture(ContractStatus $status = ContractStatus::Active): array
    {
        $emission = Emission::factory()->create(['name' => 'CRI Alfa', 'status' => 'active']);
        $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);
        $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '101']);

        $contract = DerivationFixture::contract(
            $unit,
            '2026-01-10',
            '600000.00',
            $status === ContractStatus::Cancelled ? '2026-02-10' : null,
            $status,
        );
        $contract->forceFill(['code' => 'ALFA-101'])->save();
        DerivationFixture::installment($contract, '001', '2026-12-10', '100000.00');

        return ['construction' => $construction, 'unit' => $unit];
    }

    it('refuses a first value an order of magnitude away from the sale of the unit and warns at twice', function (string $case) {
        unitValueSoldUnitFixture();

        [$value, $outcome, $warning] = match ($case) {
            'um zero a menos' => ['60.000,00', ReconciliationOutcome::Error, null],
            'um zero a mais' => ['6.000.000,00', ReconciliationOutcome::Error, null],
            'o dobro' => ['1.200.000,00', ReconciliationOutcome::New, ImportRowWarningCode::SaleValueOffTable->value],
            'a tabela da venda' => ['600.000,00', ReconciliationOutcome::New, null],
        };

        $analysis = analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', $value, '01/01/2026', 'Carga da tabela']]);
        $row = $analysis->collect()->sole();

        expect($row['outcome'])->toBe($outcome)
            ->and(collect($row['warnings'])->pluck('code')->first())->toBe($warning)
            ->and($analysis->canImport())->toBe($outcome !== ReconciliationOutcome::Error);

        if ($case === 'um zero a menos') {
            expect($row['message'])->toBe('Valor atualizado (R$ 60.000,00) é cerca de 1/10 do valor da venda do contrato ALFA-101 (R$ 600.000,00, vendido em 10/01/2026): um dos dois foi lido errado. A unidade não tem valor cadastrado nesta data, e este valor passaria a ser a referência da venda no Quadro de Vendas. Confira a planilha e o contrato.');
        }
    })->with(['um zero a menos', 'um zero a mais', 'o dobro', 'a tabela da venda']);

    it('also measures a sale made before the effective date, which the position falls back to', function () {
        unitValueSoldUnitFixture();

        $row = analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', '50.000,00', '01/03/2026', 'Carga da tabela']])->collect()->sole();

        expect($row['outcome'])->toBe(ReconciliationOutcome::Error);
    });

    it('measures nothing against a unit with no live sale', function (string $case) {
        match ($case) {
            'sem contrato' => unitValueFixture(),
            'distratado' => unitValueSoldUnitFixture(ContractStatus::Cancelled),
            'permutado' => unitValueSoldUnitFixture(ContractStatus::Exchanged),
        };

        $row = analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', '60.000,00', '01/01/2026', 'Carga da tabela']])->collect()->sole();

        expect($row['outcome'])->toBe(ReconciliationOutcome::New)
            ->and($row['warnings'])->toBe([]);
    })->with(['sem contrato', 'distratado', 'permutado']);

    /**
     * Os dois lados com a mesma venda: o valor que a importação recusa é o que
     * bloquearia a obra, e o que ela aceita com aviso não bloqueia.
     */
    it('agrees with the derivation: what it refuses blocks the position, what it accepts does not', function (string $value) {
        ['construction' => $construction, 'unit' => $unit] = unitValueSoldUnitFixture();

        $row = analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', $value, '01/01/2026', 'Carga da tabela']])->collect()->sole();

        ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth(str_replace(['.', ','], ['', '.'], $value))->create();

        $blocked = collect(DerivationFixture::derive($construction, '2026-07-01')->issues)
            ->contains(fn ($issue): bool => $issue->code === SalesBoardIssueCode::SaleValueOutOfScale);

        expect($blocked)->toBe($row['outcome'] === ReconciliationOutcome::Error);
    })->with(['60.000,00', '1.200.000,00']);

    /**
     * A unidade 01/101 com R$ 600.000,00 desde 01/01/2026, vendida em
     * 10/03/2026 por R$ 650.000,00: a venda tem tabela própria na data dela.
     *
     * @return array{construction: Construction, unit: ConstructionUnit}
     */
    function unitValueTabledSaleFixture(): array
    {
        $emission = Emission::factory()->create(['name' => 'CRI Alfa', 'status' => 'active']);
        $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);
        $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '101']);
        ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('600000.00')->create();

        $contract = DerivationFixture::contract($unit, '2026-03-10', '650000.00');
        $contract->forceFill(['code' => 'ALFA-101'])->save();
        DerivationFixture::installment($contract, '001', '2026-12-10', '100000.00');

        return ['construction' => $construction, 'unit' => $unit];
    }

    /**
     * A linha histórica não é a referência da venda -- a tabela de 2026 vem
     * entre ela e a data da venda -- nem da posição, e não é medida contra a
     * venda. Medida contra todas as vendas da unidade, a de 2005 virava erro
     * ("cerca de 1/11") e a de 2015 ganhava o aviso de venda fora da tabela,
     * enquanto a derivação, com a linha gravada, não acusa nada.
     */
    it('does not measure a historical line against a sale that has a table of its own', function (string $value, string $effectiveFrom) {
        ['construction' => $construction] = unitValueTabledSaleFixture();

        $analysis = analyzeUnitValues([['CRI Alfa', 'Residencial Alfa', '01', '101', $value, $effectiveFrom, 'Histórico de tabela']]);
        $row = $analysis->collect()->sole();

        expect($row['outcome'])->toBe(ReconciliationOutcome::New)
            ->and($row['warnings'])->toBe([])
            ->and($analysis->canImport())->toBeTrue();

        expect(app(ImportUnitValuesFromSpreadsheet::class)->handle($analysis)['created'])->toBe(1)
            ->and(DerivationFixture::issueCodes(DerivationFixture::derive($construction->fresh(), '2026-07-01')))
            ->not->toContain(SalesBoardIssueCode::SaleValueOutOfScale->value)
            ->not->toContain(SalesBoardIssueCode::SaleValueAtypical->value);
    })->with([
        'R$ 60.000,00 em 01/01/2005' => ['60.000,00', '01/01/2005'],
        'R$ 300.000,00 em 01/01/2015' => ['300.000,00', '01/01/2015'],
    ]);

    /**
     * A conferência vai para a linha que a venda de fato usa. A unidade não
     * tem valor cadastrado e foi vendida em 10/01/2026 por R$ 600.000,00; o
     * arquivo traz a tabela de 2005 e a de 01/01/2026 com um zero a menos. A de
     * 2005 não é referência de nada e entra sem aviso; a de 2026 é a tabela da
     * data da venda, fora de escala, e continua recusada -- mesmo tendo como
     * vigente a linha de 2005, do próprio arquivo, e não um valor cadastrado.
     */
    it('still refuses the line that becomes the table of the sale day, out of scale', function () {
        ['construction' => $construction] = unitValueSoldUnitFixture();

        $analysis = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '300.000,00', '01/01/2005', 'Histórico de tabela'],
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '59.000,00', '01/01/2026', 'Carga da tabela'],
        ]);
        [$historical, $reference] = $analysis->collect()->sortBy('line')->values()->all();

        expect($historical['outcome'])->toBe(ReconciliationOutcome::New)
            ->and($historical['warnings'])->toBe([])
            ->and($reference['outcome'])->toBe(ReconciliationOutcome::Error)
            ->and($reference['message'])->toBe('Valor atualizado (R$ 59.000,00) é cerca de 1/10 do valor da venda do contrato ALFA-101 (R$ 600.000,00, vendido em 10/01/2026): um dos dois foi lido errado. A unidade não tem valor cadastrado nesta data, e este valor passaria a ser a referência da venda no Quadro de Vendas. Confira a planilha e o contrato.')
            ->and($analysis->canImport())->toBeFalse();

        // Gravadas as duas por fora, a derivação bloqueia a venda pela de 2026.
        ConstructionUnitValue::factory()->forUnit(ConstructionUnit::query()->sole())->effectiveFrom('2005-01-01')->worth('300000.00')->create();
        ConstructionUnitValue::factory()->forUnit(ConstructionUnit::query()->sole())->effectiveFrom('2026-01-01')->worth('59000.00')->create();

        expect(DerivationFixture::issueCodes(DerivationFixture::derive($construction->fresh(), '2026-07-01')))
            ->toContain(SalesBoardIssueCode::SaleValueOutOfScale->value);
    });

    /**
     * A venda anterior à vigência fica sem tabela na data dela, e a derivação a
     * mede pela tabela da data da posição. Só a linha que ainda vale na
     * posição é essa referência: a de 01/03/2026, superada pela de
     * 01/06/2026 antes da posição, não é medida contra a venda.
     */
    it('measures against a sale with no table on its day only the line in force on the position date', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00'));
        unitValueSoldUnitFixture();

        $analysis = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '60.000,00', '01/03/2026', 'Histórico de tabela'],
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '590.000,00', '01/06/2026', 'Carga da tabela'],
        ]);
        [$superseded, $inForce] = $analysis->collect()->sortBy('line')->values()->all();

        expect($superseded['outcome'])->toBe(ReconciliationOutcome::New)
            ->and($superseded['warnings'])->toBe([])
            // O salto de uma linha para a outra continua avisado pela régua do vigente.
            ->and($inForce['outcome'])->toBe(ReconciliationOutcome::Update)
            ->and(collect($inForce['warnings'])->pluck('code')->all())->toBe([ImportRowWarningCode::UnitValueFarFromCurrent->value])
            ->and($analysis->canImport())->toBeTrue();

        $outOfScale = analyzeUnitValues([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '60.000,00', '01/03/2026', 'Histórico de tabela'],
        ])->collect()->sole();

        expect($outOfScale['outcome'])->toBe(ReconciliationOutcome::Error)
            ->and($outOfScale['message'])->toContain('como valor da unidade em 30/09/2026');
    });
});

it('records the run of a values file, stamping the lines it appended', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [, , $unit] = unitValueFixture('400000.00', '2026-01-01');

    $path = unitValueSpreadsheet([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '420.000,00', '01/07/2026', 'Reajuste'],
    ]);

    Livewire::test(ListConstructionUnits::class)
        ->callAction(TestAction::make('updateUnitValues'), ['file' => spreadsheetUpload($path, 'tabela-julho.xlsx')])
        ->assertHasNoActionErrors();

    $run = ImportRun::query()->sole();

    expect($run->type)->toBe(ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES)
        ->and($run->file_name)->toBe('tabela-julho.xlsx')
        ->and($run->records_created)->toBe(1)
        ->and(ConstructionUnitValue::query()->sole()->import_run_id)->toBe($run->id)
        ->and($unit->id)->toBeGreaterThan(0);
});
