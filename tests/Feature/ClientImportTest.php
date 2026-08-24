<?php

use App\Actions\Clients\AnalyzeClientSpreadsheet;
use App\Actions\Clients\ClientSpreadsheetColumns;
use App\Actions\Clients\ClientSpreadsheetTemplate;
use App\Actions\Clients\ImportClientsFromSpreadsheet;
use App\Enums\ClientPersonType;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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
function clientSpreadsheet(array $rows, ?array $headers = null): string
{
    $path = temporaryTestFilePath('clients-import');
    $headers ??= ClientSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    return $path;
}

function analyzeClientSpreadsheet(array $rows, ?array $headers = null)
{
    return app(AnalyzeClientSpreadsheet::class)->handle(clientSpreadsheet($rows, $headers));
}

it('builds a template whose data sheet carries only the headers', function () {
    $path = discardTemplateFileAfterTest(app(ClientSpreadsheetTemplate::class)->build());

    $dataRows = SimpleExcelReader::create($path)->getRows()->all();
    $exampleRows = SimpleExcelReader::create($path)->fromSheetName(ClientSpreadsheetTemplate::EXAMPLE_SHEET)->getRows()->all();

    expect($dataRows)->toBe([])
        ->and($exampleRows)->toHaveCount(2)
        ->and(array_keys($exampleRows[0]))->toBe(['Tipo', 'Nome / Razão Social', 'CPF/CNPJ', 'E-mail', 'Telefone']);

    // The demonstration documents are valid, so the example never teaches an
    // invalid CPF/CNPJ, and the example sheet is never read by the importer.
    expect(app(AnalyzeClientSpreadsheet::class)->handle($path)->fileErrors)
        ->toBe(['A planilha está vazia.']);
});

it('serves the template through the download route', function () {
    $this->actingAs(makeAdminUser());

    $response = $this->get(route('admin.clients.template.download'))
        ->assertSuccessful()
        ->assertDownload(ClientSpreadsheetTemplate::DOWNLOAD_NAME);

    discardTemplateFileAfterTest($response->baseResponse->getFile()->getPathname());
});

it('rejects a spreadsheet without the required columns', function () {
    $analysis = analyzeClientSpreadsheet([['PF', 'João']], ['Tipo', 'Nome / Razão Social']);

    expect($analysis->fileErrors)->toBe(['A planilha não possui as colunas obrigatórias: CPF/CNPJ.'])
        ->and($analysis->canImport())->toBeFalse();
});

it('applies the same document rules as the manual form', function () {
    Client::factory()->create(['document' => '52998224725']);
    $trashed = Client::factory()->create(['document' => '11144477735']);
    $trashed->delete();

    $analysis = analyzeClientSpreadsheet([
        ['PF', 'Cliente Válido', ClientFactory::validCpf(), 'valido@email.com', '21999999999'],
        ['PF', 'CPF Inválido', '12345678900', null, null],
        ['PF', 'CNPJ em PF', '11222333000181', null, null],
        ['PJ', 'CNPJ Inválido', '12345678000199', null, null],
        ['PJ', 'CPF em PJ', '52998224725', null, null],
        ['XX', 'Tipo Inválido', ClientFactory::validCpf(), null, null],
        ['PF', 'Já Cadastrado', '529.982.247-25', null, null],
        ['PF', 'Excluído', '111.444.777-35', null, null],
        ['PF', '', ClientFactory::validCpf(), null, null],
        ['PF', 'E-mail Inválido', ClientFactory::validCpf(), 'nao-e-email', null],
        [' ', ' ', ' ', ' ', ' '],
    ]);

    $byLine = $analysis->collect()->keyBy('line');

    expect($byLine[2]['status'])->toBe(AnalyzeClientSpreadsheet::STATUS_VALID)
        ->and($byLine[3]['message'])->toBe('Informe um CPF válido.')
        ->and($byLine[4]['message'])->toBe('Pessoa Física exige um CPF com 11 dígitos.')
        ->and($byLine[5]['message'])->toBe('Informe um CNPJ válido.')
        ->and($byLine[6]['message'])->toBe('Pessoa Jurídica exige um CNPJ com 14 dígitos.')
        ->and($byLine[7]['message'])->toBe('Tipo de pessoa inválido. Utilize PF ou PJ.')
        ->and($byLine[8]['status'])->toBe(AnalyzeClientSpreadsheet::STATUS_ALREADY_REGISTERED)
        ->and($byLine[9]['status'])->toBe(AnalyzeClientSpreadsheet::STATUS_SOFT_DELETED)
        ->and($byLine[10]['message'])->toBe('Campos obrigatórios não preenchidos: Nome / Razão Social.')
        ->and($byLine[11]['message'])->toBe('E-mail inválido.')
        ->and($byLine[12]['status'])->toBe(AnalyzeClientSpreadsheet::STATUS_EMPTY);

    expect($analysis->validCount())->toBe(1)
        ->and($analysis->alreadyRegisteredCount())->toBe(1)
        ->and($analysis->softDeletedCount())->toBe(1)
        ->and($analysis->emptyLineCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();
});

it('detects a document repeated inside the spreadsheet', function () {
    $cpf = ClientFactory::validCpf();

    $analysis = analyzeClientSpreadsheet([
        ['PF', 'Primeiro', $cpf, null, null],
        ['PF', 'Segundo', $cpf, null, null],
    ]);

    $byLine = $analysis->collect()->keyBy('line');

    expect($byLine[2]['status'])->toBe(AnalyzeClientSpreadsheet::STATUS_VALID)
        ->and($byLine[3]['status'])->toBe(AnalyzeClientSpreadsheet::STATUS_DUPLICATED_IN_FILE)
        ->and($byLine[3]['message'])->toBe('Documento repetido na planilha (linha 2).')
        ->and($analysis->canImport())->toBeFalse();
});

it('accepts documents with and without mask and several type spellings', function () {
    $analysis = analyzeClientSpreadsheet([
        ['PF', 'Sem Máscara', '52998224725', null, null],
        ['Pessoa Jurídica', 'Com Máscara', '11.222.333/0001-81', null, null],
    ]);

    expect($analysis->canImport())->toBeTrue();

    app(ImportClientsFromSpreadsheet::class)->handle($analysis);

    expect(Client::query()->pluck('document')->all())->toBe(['52998224725', '11222333000181'])
        ->and(Client::query()->where('name', 'Com Máscara')->sole()->person_type)->toBe(ClientPersonType::Company);
});

it('refuses to import while a single row is inconsistent', function () {
    $analysis = analyzeClientSpreadsheet([
        ['PF', 'Válido', ClientFactory::validCpf(), null, null],
        ['PF', 'Inválido', '12345678900', null, null],
    ]);

    expect(fn () => app(ImportClientsFromSpreadsheet::class)->handle($analysis))
        ->toThrow(RuntimeException::class, 'A planilha possui inconsistências e não pode ser importada.');

    expect(Client::query()->count())->toBe(0);
});

it('imports every client once the spreadsheet is fully valid', function () {
    $analysis = analyzeClientSpreadsheet([
        ['PF', 'João da Silva', '529.982.247-25', 'joao@email.com', '21999999999'],
        ['PJ', 'Empresa Exemplo Ltda', '11.222.333/0001-81', 'contato@empresa.com', '2133333333'],
    ]);

    $result = app(ImportClientsFromSpreadsheet::class)->handle($analysis);

    expect($result)->toBe(['clients' => 2, 'individuals' => 1, 'companies' => 1]);

    $individual = Client::query()->where('document', '52998224725')->sole();

    expect($individual->person_type)->toBe(ClientPersonType::Individual)
        ->and($individual->name)->toBe('João da Silva')
        ->and($individual->email)->toBe('joao@email.com')
        ->and($individual->phone)->toBe('21999999999')
        ->and($individual->getKey())->toBeInt();
});

it('never overwrites an existing client', function () {
    $existing = Client::factory()->create(['document' => '52998224725', 'name' => 'Nome Original']);

    $analysis = analyzeClientSpreadsheet([
        ['PF', 'Nome Diferente', '52998224725', null, null],
    ]);

    expect($analysis->canImport())->toBeFalse()
        ->and($existing->refresh()->name)->toBe('Nome Original')
        ->and(Client::query()->count())->toBe(1);
});

it('imports through the list page wizard and records the audit trail without pii', function () {
    $user = makeAdminUser();
    $this->actingAs($user);

    $path = clientSpreadsheet([
        ['PF', 'João da Silva', '529.982.247-25', 'joao@email.com', '21999999999'],
        ['PJ', 'Empresa Exemplo Ltda', '11.222.333/0001-81', null, null],
    ]);

    $storedPath = 'imports/clients/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListClients::class)
        ->assertActionExists('downloadTemplate')
        ->assertActionHasLabel('downloadTemplate', 'Baixar Modelo')
        ->assertActionExists('importClients')
        ->assertActionHasLabel('importClients', 'Importar Clientes')
        ->callAction(TestAction::make('importClients'), ['file' => ['upload' => $storedPath]])
        ->assertHasNoActionErrors();

    expect(Client::query()->count())->toBe(2);

    $activity = Activity::query()->where('log_name', 'importacao-clientes')->sole();

    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->properties['clientes_cadastrados'])->toBe(2)
        ->and($activity->properties['pessoas_fisicas'])->toBe(1)
        ->and($activity->properties['pessoas_juridicas'])->toBe(1)
        ->and(json_encode($activity->properties))->not->toContain('52998224725');
});

it('does not import through the wizard when the spreadsheet has errors', function () {
    $this->actingAs(makeAdminUser());

    $path = clientSpreadsheet([
        ['PF', 'Válido', ClientFactory::validCpf(), null, null],
        ['PF', 'Inválido', '12345678900', null, null],
    ]);

    $storedPath = 'imports/clients/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListClients::class)
        ->callAction(TestAction::make('importClients'), ['file' => ['upload' => $storedPath]]);

    expect(Client::query()->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'importacao-clientes')->count())->toBe(0);
});

it('hides the import action from users who cannot create clients', function () {
    $role = Role::firstOrCreate(['name' => 'clients-viewer']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'clients.view'])]);

    $user = makeAdminUser();
    $user->syncRoles([$role]);

    $this->actingAs($user);

    Livewire::test(ListClients::class)
        ->assertActionHidden('importClients');
});

it('points the soft deleted rows at the client that has to be restored', function () {
    $this->actingAs(makeAdminUser());

    $trashed = Client::factory()->create(['document' => '52998224725']);
    $trashed->delete();

    $analysis = analyzeClientSpreadsheet([
        ['PF', 'Cliente Excluído', '529.982.247-25', null, null],
    ]);

    $row = $analysis->collect()->firstWhere('line', 2);

    // The preview renders this id as a "Restaurar cadastro" link straight to the
    // existing client, instead of leaving the user to hunt for it.
    expect($row['status'])->toBe(AnalyzeClientSpreadsheet::STATUS_SOFT_DELETED)
        ->and($row['existing_client_id'])->toBe($trashed->getKey())
        ->and($analysis->canImport())->toBeFalse();

    expect(ListClients::trashedListingUrl())
        ->toBe(ClientResource::getUrl('index', ['tab' => ListClients::TAB_TRASHED]))
        ->toContain('tab=excluidos');
});

it('offers the deleted clients listing when an import is blocked by a deleted registration', function () {
    $this->actingAs(makeAdminUser());

    $trashed = Client::factory()->create(['document' => '52998224725']);
    $trashed->delete();

    $path = clientSpreadsheet([['PF', 'Cliente Excluído', '529.982.247-25', null, null]]);
    $storedPath = 'imports/clients/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListClients::class)
        ->callAction(TestAction::make('importClients'), ['file' => ['upload' => $storedPath]]);

    // Mounting the notifications component drains them, so it happens once.
    $notifications = new Notifications;
    $notifications->mount();

    $notification = $notifications->notifications->first();
    $action = collect($notification?->getActions())->first();

    expect($notification?->getTitle())->toBe('Importação não realizada.')
        ->and($notification?->getBody())->toContain('Restaure os cadastros existentes em vez de criar novos')
        ->and($action?->getLabel())->toBe('Ver clientes excluídos')
        ->and($action?->getUrl())->toBe(ListClients::trashedListingUrl());

    expect(Client::query()->count())->toBe(0);
});

it('never registers a second client for a document held by a deleted one', function () {
    $this->actingAs(makeAdminUser());

    $trashed = Client::factory()->create(['document' => '52998224725', 'name' => 'Nome Original']);
    $trashed->delete();

    $path = clientSpreadsheet([['PF', 'Nome Diferente', '529.982.247-25', null, null]]);
    $storedPath = 'imports/clients/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListClients::class)
        ->callAction(TestAction::make('importClients'), ['file' => ['upload' => $storedPath]]);

    expect(Client::withTrashed()->count())->toBe(1)
        ->and($trashed->refresh()->name)->toBe('Nome Original')
        ->and($trashed->trashed())->toBeTrue();
});
