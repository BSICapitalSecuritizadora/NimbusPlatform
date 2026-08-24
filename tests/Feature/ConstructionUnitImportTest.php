<?php

use App\Actions\ConstructionUnits\AnalyzeConstructionUnitSpreadsheet;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetColumns;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetTemplate;
use App\Actions\ConstructionUnits\ImportConstructionUnitsFromSpreadsheet;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
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
 * @param  list<array<int, string|null>>  $rows
 */
function unitSpreadsheet(array $rows, ?array $headers = null): string
{
    $path = temporaryTestFilePath('units-import');
    $headers ??= ConstructionUnitSpreadsheetColumns::headers();

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

it('builds a template whose data sheet carries only the headers', function () {
    $path = app(ConstructionUnitSpreadsheetTemplate::class)->build();

    $dataRows = SimpleExcelReader::create($path)->getRows()->all();
    $exampleRows = SimpleExcelReader::create($path)->fromSheetName(ConstructionUnitSpreadsheetTemplate::EXAMPLE_SHEET)->getRows()->all();

    expect($dataRows)->toBe([])
        ->and($exampleRows)->toHaveCount(4)
        ->and(array_keys($exampleRows[0]))->toBe(['Emissão', 'Empreendimento', 'Bloco', 'Unidade'])
        ->and($exampleRows[0]['Emissão'])->toBe('CRI Conviva');

    // The example sheet is never read by the importer.
    expect(app(AnalyzeConstructionUnitSpreadsheet::class)->handle($path)->fileErrors)
        ->toBe(['A planilha está vazia.']);
});

it('serves the template through the download route', function () {
    $this->actingAs(makeAdminUser());

    $this->get(route('admin.construction-units.template.download'))
        ->assertSuccessful()
        ->assertDownload(ConstructionUnitSpreadsheetTemplate::DOWNLOAD_NAME);
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
        ->assertActionExists('downloadTemplate')
        ->assertActionHasLabel('downloadTemplate', 'Baixar Modelo')
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
