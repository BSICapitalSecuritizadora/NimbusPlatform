<?php

use App\Actions\ConstructionUnits\AnalyzeConstructionUnitSpreadsheet;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetColumns;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetTemplate;
use App\Actions\ConstructionUnits\ImportConstructionUnitsFromSpreadsheet;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Emission;
use App\Models\SalesBoard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Builds a spreadsheet with the four original columns.
 *
 * The default is deliberately the legacy shape: the base value pair was added
 * later and is optional, so every test that does not ask for it is proving that
 * a file produced before it existed still imports.
 *
 * @param  list<array<int, string|null>>  $rows
 */
function unitSpreadsheet(array $rows, ?array $headers = null): string
{
    $path = temporaryTestFilePath('units-import');
    $headers ??= ConstructionUnitSpreadsheetColumns::requiredHeaders();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    return $path;
}

function analyzeUnitSpreadsheet(array $rows, ?array $headers = null)
{
    return app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheet($rows, $headers));
}

/**
 * Builds a spreadsheet carrying the optional base value pair as well.
 *
 * @param  list<array<int, string|null>>  $rows
 */
function unitSpreadsheetWithBaseValue(array $rows): string
{
    return unitSpreadsheet($rows, ConstructionUnitSpreadsheetColumns::headers());
}

it('imports a spreadsheet that carries the base value pair', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '900.000,00', '01/01/2026'],
        ['CRI Alfa', 'Residencial Alfa', '01', '102', '1.000.000,00', '2026-01-01'],
    ]));

    expect($analysis->canImport())->toBeTrue();

    app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis);

    $first = ConstructionUnit::query()->where('unit', '101')->sole();
    $second = ConstructionUnit::query()->where('unit', '102')->sole();

    expect($first->base_value)->toBe('900000.00')
        ->and($first->base_value_reference_date->toDateString())->toBe('2026-01-01')
        ->and($second->base_value)->toBe('1000000.00')
        ->and($second->base_value_reference_date->toDateString())->toBe('2026-01-01');
});

it('reads a brazilian reference date as day first', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '900.000,00', '03/09/2026'],
    ]));

    app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis);

    expect(ConstructionUnit::sole()->base_value_reference_date->toDateString())->toBe('2026-09-03');
});

it('leaves the base value pair empty when the spreadsheet omits it', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '', ''],
    ]));

    expect($analysis->canImport())->toBeTrue();

    app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis);

    $unit = ConstructionUnit::sole();

    expect($unit->base_value)->toBeNull()
        ->and($unit->base_value_reference_date)->toBeNull()
        ->and($unit->hasBaseValue())->toBeFalse();
});

it('rejects a row that informs only one half of the base value pair', function (?string $baseValue, ?string $referenceDate, string $expectedMessage) {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', $baseValue, $referenceDate],
    ]));

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->collect()->first()['message'])->toContain($expectedMessage)
        ->and(ConstructionUnit::count())->toBe(0);
})->with([
    'value without date' => ['900.000,00', '', 'sem a data de referência'],
    'date without value' => ['', '01/01/2026', 'sem o valor base'],
]);

it('rejects an unreadable base value or reference date', function (string $baseValue, string $referenceDate, string $expectedMessage) {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', $baseValue, $referenceDate],
    ]));

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->first()['message'])->toContain($expectedMessage);
})->with([
    'invalid value' => ['abc', '01/01/2026', 'Valor base inválido'],
    'negative value' => ['-900.000,00', '01/01/2026', 'não pode ser negativo'],
    'invalid date' => ['900.000,00', '31/02/2026', 'Data de referência do valor base inválida'],
]);

/**
 * Zero used to be accepted as "an informed amount". Used as the "no price yet"
 * placeholder it made every sale of the unit conform and the stock publish at
 * R$ 0,00 with no finding, so a base value, when informed, must be positive.
 */
it('refuses a zero base value', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '0,00', '01/01/2026'],
    ]));

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->collect()->first()['message'])->toContain('O valor base precisa ser maior que zero.')
        ->and(ConstructionUnit::count())->toBe(0);
});

it('never writes value history when importing units', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

    $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
        ['CRI Alfa', 'Residencial Alfa', '01', '101', '900.000,00', '01/01/2026'],
    ]));

    app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis);

    expect(ConstructionUnitValue::count())->toBe(0);
});

it('builds a template whose data sheet carries only the headers', function () {
    $path = discardTemplateFileAfterTest(app(ConstructionUnitSpreadsheetTemplate::class)->build());

    $dataRows = SimpleExcelReader::create($path)->getRows()->all();
    $exampleRows = SimpleExcelReader::create($path)->fromSheetName(ConstructionUnitSpreadsheetTemplate::EXAMPLE_SHEET)->getRows()->all();

    expect($dataRows)->toBe([])
        ->and($exampleRows)->toHaveCount(4)
        ->and(array_keys($exampleRows[0]))->toBe(['Emissão', 'Empreendimento', 'Bloco', 'Unidade', 'Valor Base', 'Data de Referência do Valor Base'])
        ->and($exampleRows[0]['Emissão'])->toBe('CRI Conviva');

    // The example sheet is never read by the importer.
    expect(app(AnalyzeConstructionUnitSpreadsheet::class)->handle($path)->fileErrors)
        ->toBe(['A planilha está vazia.']);
});

it('serves the template through the download route', function () {
    $this->actingAs(makeAdminUser());

    $response = $this->get(route('admin.construction-units.template.download'))
        ->assertSuccessful()
        ->assertDownload(ConstructionUnitSpreadsheetTemplate::DOWNLOAD_NAME);

    discardTemplateFileAfterTest($response->baseResponse->getFile()->getPathname());
});

it('rejects a spreadsheet without the required columns', function () {
    $analysis = analyzeUnitSpreadsheet([['CRI', 'Conviva']], ['Emissão', 'Empreendimento']);

    expect($analysis->fileErrors)->toBe(['A planilha não possui as colunas obrigatórias: Bloco, Unidade.'])
        ->and($analysis->canImport())->toBeFalse();
});

it('validates every row before allowing the import', function () {
    [$emission, $construction] = unitEmissionAndConstruction();
    $otherEmission = Emission::factory()->create(['name' => 'CRA Outro']);
    Construction::factory()->create([
        'emission_id' => $otherEmission->id,
        'development_name' => 'Empreendimento XYZ',
    ]);

    ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '09', 'unit' => '901']);

    $analysis = analyzeUnitSpreadsheet([
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '102'],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Conviva', 'Não existe', '01', '104'],
        ['Emissão Inexistente', 'Conviva Camboinhas', '01', '105'],
        ['CRI Conviva', 'Empreendimento XYZ', '01', '106'],
        ['CRI Conviva', 'Conviva Camboinhas', '', '107'],
        ['CRI Conviva', 'Conviva Camboinhas', '09', '901'],
        [' ', ' ', ' ', ' '],
    ]);

    $byLine = $analysis->collect()->keyBy('line');

    expect($byLine[2]['status'])->toBe(AnalyzeConstructionUnitSpreadsheet::STATUS_VALID)
        ->and($byLine[3]['status'])->toBe(AnalyzeConstructionUnitSpreadsheet::STATUS_VALID)
        ->and($byLine[4]['status'])->toBe(AnalyzeConstructionUnitSpreadsheet::STATUS_DUPLICATED_IN_FILE)
        ->and($byLine[4]['message'])->toBe('Unidade repetida na planilha (linha 2).')
        ->and($byLine[5]['message'])->toBe('Empreendimento não encontrado.')
        ->and($byLine[6]['message'])->toBe('Emissão não encontrada.')
        ->and($byLine[7]['message'])->toBe('O empreendimento informado não pertence à emissão selecionada.')
        ->and($byLine[8]['message'])->toBe('Campos obrigatórios não preenchidos: Bloco.')
        ->and($byLine[9]['status'])->toBe(AnalyzeConstructionUnitSpreadsheet::STATUS_ALREADY_REGISTERED)
        ->and($byLine[10]['status'])->toBe(AnalyzeConstructionUnitSpreadsheet::STATUS_EMPTY);

    expect($analysis->totalLines())->toBe(8)
        ->and($analysis->validCount())->toBe(2)
        ->and($analysis->errorCount())->toBe(4)
        ->and($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->alreadyRegisteredCount())->toBe(1)
        ->and($analysis->emptyLineCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();

    expect($emission->constructionUnits()->count())->toBe(1);
});

it('refuses to import while a single row is inconsistent', function () {
    [, $construction] = unitEmissionAndConstruction();

    $analysis = analyzeUnitSpreadsheet([
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Conviva', 'Não existe', '01', '102'],
    ]);

    expect(fn () => app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis))
        ->toThrow(RuntimeException::class, 'A planilha possui inconsistências e não pode ser importada.');

    expect($construction->units()->count())->toBe(0);
});

it('imports every unit once the spreadsheet is fully valid', function () {
    [$emission, $camboinhas] = unitEmissionAndConstruction();
    $piratininga = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Conviva Piratininga',
    ]);

    $analysis = analyzeUnitSpreadsheet([
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '102'],
        ['CRI Conviva', 'Conviva Camboinhas', '02', '201'],
        ['CRI Conviva', 'Conviva Piratininga', 'A', 'Loja 01'],
    ]);

    expect($analysis->canImport())->toBeTrue();

    $result = app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis);

    expect($result)->toBe(['units' => 4, 'constructions' => 2, 'emissions' => 1])
        ->and($camboinhas->units()->count())->toBe(3)
        ->and($piratininga->units()->count())->toBe(1);

    $loja = $piratininga->units()->where('unit', 'Loja 01')->sole();

    expect($loja->block)->toBe('A')
        ->and($loja->getKey())->toBeInt();
});

it('preserves leading zeros and textual identifiers', function () {
    [, $construction] = unitEmissionAndConstruction();

    $analysis = analyzeUnitSpreadsheet([
        ['CRI Conviva', 'Conviva Camboinhas', '01', '0101'],
        ['CRI Conviva', 'Conviva Camboinhas', 'Torre A', 'Cobertura 02'],
    ]);

    app(ImportConstructionUnitsFromSpreadsheet::class)->handle($analysis);

    expect($construction->units()->orderBy('id')->get()->map(fn (ConstructionUnit $unit): string => $unit->block.'|'.$unit->unit)->all())
        ->toBe(['01|0101', 'Torre A|Cobertura 02']);
});

it('never creates emissions or constructions', function () {
    unitEmissionAndConstruction();

    $analysis = analyzeUnitSpreadsheet([
        ['Emissão Nova', 'Empreendimento Novo', '01', '101'],
    ]);

    expect($analysis->canImport())->toBeFalse()
        ->and(Emission::query()->count())->toBe(1)
        ->and(Construction::query()->count())->toBe(1);
});

it('imports through the list page wizard and records the audit trail', function () {
    $user = makeAdminUser();
    $this->actingAs($user);

    [, $construction] = unitEmissionAndConstruction();

    $path = unitSpreadsheet([
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Conviva', 'Conviva Camboinhas', '01', '102'],
    ]);

    $storedPath = 'imports/construction-units/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListConstructionUnits::class)
        // Os dois modelos ficam num menu rotulado "Baixar Modelo"; o rótulo de
        // cada item distingue o de cadastro do de atualização de valores.
        ->assertActionExists('downloadTemplate')
        ->assertActionHasLabel('downloadTemplate', 'Modelo de cadastro')
        ->assertActionExists('downloadValueTemplate')
        ->assertActionExists('importUnits')
        ->assertActionHasLabel('importUnits', 'Importar Unidades')
        ->callAction(TestAction::make('importUnits'), ['file' => ['upload' => $storedPath]])
        ->assertHasNoActionErrors();

    expect($construction->units()->count())->toBe(2);

    $activity = Activity::query()->where('log_name', 'importacao-unidades')->sole();

    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->properties['unidades_cadastradas'])->toBe(2)
        ->and($activity->properties['empreendimentos_envolvidos'])->toBe(1)
        ->and($activity->properties['emissoes_envolvidas'])->toBe(1);
});

it('does not import through the wizard when the spreadsheet has errors', function () {
    $this->actingAs(makeAdminUser());

    [, $construction] = unitEmissionAndConstruction();

    $path = unitSpreadsheet([
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Conviva', 'Não existe', '01', '102'],
    ]);

    $storedPath = 'imports/construction-units/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListConstructionUnits::class)
        ->callAction(TestAction::make('importUnits'), ['file' => ['upload' => $storedPath]]);

    expect($construction->units()->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'importacao-unidades')->count())->toBe(0);
});

describe('leitura estrita da data de referência', function () {
    /**
     * Anything outside dd/mm/aaaa used to fall into Carbon::parse(), which reads
     * slashes month first: "01/07/26" became 7 de janeiro and the base value
     * was placed six months away from where the operator put it.
     */
    it('refuses a two digit year and an implausible year', function (string $referenceDate) {
        $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
        Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

        $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '400.000,00', $referenceDate],
        ]));

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->collect()->first()['message'])
            ->toBe('Data de referência do valor base inválida. Utilize o formato dd/mm/aaaa, com o ano completo (a partir de 1990).');
    })->with([
        'two digit year' => ['01/07/26'],
        'implausible year' => ['01/07/0026'],
        'dashes' => ['01-07-26'],
    ]);

    it('reads the time an export appends as the same day, never month first', function () {
        $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
        Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

        $analysis = app(AnalyzeConstructionUnitSpreadsheet::class)->handle(unitSpreadsheetWithBaseValue([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '400.000,00', '01/07/2026 00:00:00'],
        ]));

        expect($analysis->collect()->first()['base_value_reference_date'])->toBe('2026-07-01');
    });

    it('shows the interpreted base value and reference date on the conference', function () {
        $this->actingAs(makeAdminUser());

        $emission = Emission::factory()->create(['name' => 'CRI Alfa']);
        Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']);

        $path = unitSpreadsheetWithBaseValue([
            ['CRI Alfa', 'Residencial Alfa', '01', '101', '400.000,00', '01/07/2026 00:00:00'],
        ]);
        $storedPath = 'imports/construction-units/'.basename($path);
        Storage::disk('local')->put($storedPath, file_get_contents($path));

        $component = Livewire::test(ListConstructionUnits::class)->instance();
        $preview = (fn (): string => $this->renderPreview($storedPath)->toHtml())->call($component);

        expect($preview)
            ->toContain('<th style="text-align:right;padding:.25rem .5rem;">Valor base</th>')
            ->toContain('R$ 400.000,00')
            ->toContain('01/07/2026');
    });
});

/**
 * The development used to be looked up by name across every emission, and only
 * then checked against the emission of the row. A homonym of another series
 * found first made the right row read "não pertence à emissão".
 */
it('resolves the development inside the emission of the row', function () {
    $firstSeries = Emission::factory()->create(['name' => 'CRI Alfa 1ª Série']);
    Construction::factory()->create(['emission_id' => $firstSeries->id, 'development_name' => 'Residencial Alfa']);

    $secondSeries = Emission::factory()->create(['name' => 'CRI Alfa 2ª Série']);
    $construction = Construction::factory()->create(['emission_id' => $secondSeries->id, 'development_name' => 'Residencial Alfa']);

    $analysis = analyzeUnitSpreadsheet([['CRI Alfa 2ª Série', 'Residencial Alfa', '01', '101']]);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->collect()->first()['construction_id'])->toBe($construction->id);

    Construction::factory()->create(['emission_id' => $secondSeries->id, 'development_name' => ' residencial alfa']);

    expect(analyzeUnitSpreadsheet([['CRI Alfa 2ª Série', 'Residencial Alfa', '01', '101']])->collect()->first()['message'])
        ->toBe('Há mais de um empreendimento com este nome nesta emissão. Diferencie os nomes antes de importar.');
});

it('flags new units of a development that already has registered Sales Board competences', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction] = unitEmissionAndConstruction();
    [, $otherConstruction] = unitEmissionAndConstruction('CRI Outra', 'Outro Empreendimento');

    foreach (['2026-05-01', '2026-06-01'] as $month) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => $month]);
    }

    $analysis = analyzeUnitSpreadsheet([
        ['CRI Conviva', 'Conviva Camboinhas', '01', '101'],
        ['CRI Outra', 'Outro Empreendimento', '01', '101'],
    ]);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->registeredCompetenceCount())->toBe(1)
        ->and($analysis->collect()->firstWhere('construction_id', $construction->id)['registered_competences'])->toBe(['2026-05', '2026-06'])
        ->and($analysis->collect()->firstWhere('construction_id', $otherConstruction->id)['registered_competences'])->toBe([]);

    $path = unitSpreadsheet([['CRI Conviva', 'Conviva Camboinhas', '01', '101']]);
    $storedPath = 'imports/construction-units/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    $component = Livewire::test(ListConstructionUnits::class)->instance();
    $preview = (fn (): string => $this->renderPreview($storedPath)->toHtml())->call($component);

    expect($preview)->toContain('Altera fatos de 2 competências já registradas no Quadro de Vendas (05/2026 a 06/2026).');
});
