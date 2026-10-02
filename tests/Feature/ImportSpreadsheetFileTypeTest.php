<?php

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Rules\XlsxSpreadsheetFile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Os assistentes de importação aceitam só XLSX.
 *
 * O CSV que eles anunciavam nunca funcionou: a regra global do Livewire o
 * recusava no envio, e o CSV do Excel pt-BR usa `;` e cp1252. E um zip qualquer
 * renomeado não pode chegar à conferência como se fosse planilha: o tipo
 * declarado não basta, a estrutura do arquivo decide.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs(makeAdminUser());
});

/**
 * O campo de arquivo do assistente, como o modal o monta.
 */
function importWizardFileField(string $screen): FileUpload
{
    $component = match ($screen) {
        'parcelas-listagem' => Livewire::test(ListContractInstallments::class)
            ->mountAction(TestAction::make('importContractInstallments')),
        'parcelas-contrato' => Livewire::test(ContractInstallmentsRelationManager::class, [
            'ownerRecord' => Contract::factory()
                ->forUnit(ConstructionUnit::factory()->create())
                ->forClient(Client::factory()->create())
                ->create(),
            'pageClass' => ViewContract::class,
        ])->mountAction(TestAction::make('importContractInstallments')->table()),
        'contratos' => Livewire::test(ListContracts::class)->mountAction(TestAction::make('importContracts')),
        'unidades' => Livewire::test(ListConstructionUnits::class)->mountAction(TestAction::make('importUnits')),
        'valores' => Livewire::test(ListConstructionUnits::class)->mountAction(TestAction::make('updateUnitValues')),
        'clientes' => Livewire::test(ListClients::class)->mountAction(TestAction::make('importClients')),
    };

    $page = $component->instance();

    return collect($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents())
        ->first(fn (mixed $field): bool => $field instanceof FileUpload);
}

it('accepts only the spreadsheet types of the config and checks the xlsx structure', function (string $screen) {
    $field = importWizardFileField($screen);

    $rules = (new ReflectionProperty($field, 'rules'))->getValue($field);

    expect($field->getAcceptedFileTypes())->toBe(config('uploads.spreadsheet_import.allowed_mimes'))
        ->and($field->getAcceptedFileTypes())->not->toContain('text/csv')
        ->and($field->shouldStoreFiles())->toBeFalse()
        ->and(collect($rules)->contains(fn (mixed $rule): bool => data_get($rule, '0') instanceof XlsxSpreadsheetFile))->toBeTrue();
})->with(['parcelas-listagem', 'parcelas-contrato', 'contratos', 'unidades', 'valores', 'clientes']);

it('refuses a csv with a message that says to send the xlsx', function () {
    $csv = temporaryUploadWithContent('parcelas.csv', "Emissão;Empreendimento;Contrato\nCRI;Obra;C-1\n");

    $validator = validator(
        ['upload' => [$csv]],
        ['upload' => importWizardFileField('parcelas-listagem')->getValidationRules()],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->all())->toContain(XlsxSpreadsheetFile::MESSAGE);
});

it('refuses a zip that is not a spreadsheet, even named .xlsx', function () {
    $zipPath = temporaryTestFilePath('nao-e-planilha', 'zip');

    $archive = new ZipArchive;
    $archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString('documento.txt', 'não é uma planilha');
    $archive->close();

    $upload = temporaryUploadWithContent('parcelas.xlsx', (string) file_get_contents($zipPath));

    $validator = validator(['file' => $upload], ['file' => [new XlsxSpreadsheetFile]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('file'))->toBe(XlsxSpreadsheetFile::MESSAGE);
});

it('accepts a real spreadsheet, also when the temporary disk has no local path', function (string $disk) {
    if ($disk === 'remoto') {
        useRemoteLikeTemporaryUploadDisk();
    }

    $path = temporaryTestFilePath('planilha-valida');
    SimpleExcelWriter::create($path)->addHeader(['Coluna'])->addRow(['Coluna' => 'valor'])->close();

    $upload = temporaryUploadWithContent('parcelas.xlsx', (string) file_get_contents($path));

    expect(validator(['file' => $upload], ['file' => [new XlsxSpreadsheetFile]])->passes())->toBeTrue();
})->with(['local', 'remoto']);
