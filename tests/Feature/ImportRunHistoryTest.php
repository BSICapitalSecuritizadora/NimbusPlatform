<?php

use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Enums\AccessPermission;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ImportRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function importHistoryUser(): User
{
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo(AccessPermission::AuditImportRunsView->value);

    return $user;
}

// ── Acesso ────────────────────────────────────────────────────────────────

it('opens the import history for a user holding the audit permission', function () {
    $user = importHistoryUser();

    $this->actingAs($user);

    expect(ImportRunResource::canViewAny())->toBeTrue();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertSuccessful();
});

it('denies the import history to a role without the permission', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user);

    expect(ImportRunResource::canViewAny())->toBeFalse();
})->with(['editor', 'commercial-representative']);

it('refuses to render the import history for a user without the permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->assignRole('editor');

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertForbidden();
});

// ── Listagem ──────────────────────────────────────────────────────────────

it('lists the executions from the most recent to the oldest', function () {
    $user = importHistoryUser();

    $oldest = ImportRun::factory()->create(['created_at' => '2026-08-21 10:15:00']);
    $newest = ImportRun::factory()->installments()->create(['created_at' => '2026-08-24 14:32:00']);
    $middle = ImportRun::factory()->create(['created_at' => '2026-08-22 09:00:00']);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
});

it('shows what each execution processed', function () {
    $user = importHistoryUser();

    $run = ImportRun::factory()->installments()->create([
        'file_name' => 'carteira_08_2026.xlsx',
        'user_id' => User::factory()->create(['name' => 'Anderson'])->id,
        'created_at' => '2026-08-24 14:32:00',
        'records_analyzed' => 9276,
        'records_created' => 340,
        'records_updated' => 87,
        'records_unchanged' => 8849,
        'records_critical' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertTableColumnStateSet('file_name', 'carteira_08_2026.xlsx', $run)
        ->assertTableColumnStateSet('user.name', 'Anderson', $run)
        ->assertTableColumnStateSet('type', ImportRun::TYPE_CONTRACT_INSTALLMENTS, $run)
        ->assertTableColumnStateSet('records_analyzed', 9276, $run)
        ->assertTableColumnStateSet('records_created', 340, $run)
        ->assertTableColumnStateSet('records_updated', 87, $run)
        ->assertTableColumnStateSet('records_unchanged', 8849, $run)
        ->assertTableColumnStateSet('records_critical', 0, $run)
        ->assertTableColumnStateSet('result', 'Concluída', $run)
        ->assertSee('Parcelas')
        ->assertSee('24/08/2026 14:32');
});

it('derives the outcome from the counters the execution recorded', function () {
    $completed = ImportRun::factory()->make();
    $unchanged = ImportRun::factory()->unchanged()->make();
    $critical = ImportRun::factory()->withCriticalUpdates()->make();

    expect($completed->resultLabel())->toBe('Concluída')
        ->and($completed->resultColor())->toBe('success')
        ->and($unchanged->resultLabel())->toBe('Sem alterações')
        ->and($unchanged->resultColor())->toBe('gray')
        ->and($critical->resultLabel())->toBe('Concluída com críticas')
        ->and($critical->resultColor())->toBe('warning');
});

it('keeps the history readable when the user who ran the import is gone', function () {
    $user = importHistoryUser();

    $author = User::factory()->create(['name' => 'Ex-colaborador']);
    $run = ImportRun::factory()->create(['user_id' => $author->id]);

    $author->delete();

    expect($run->fresh()->user_id)->toBeNull();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$run])
        ->assertSee('Usuário indisponível');
});

// ── Visualização ──────────────────────────────────────────────────────────

it('opens one execution with its file, checksum and counters', function () {
    $user = importHistoryUser();

    $run = ImportRun::factory()->installments()->create([
        'file_name' => 'carteira_08_2026.xlsx',
        'checksum' => str_repeat('a', 64),
        'user_id' => User::factory()->create(['name' => 'Anderson'])->id,
        'created_at' => '2026-08-24 14:32:00',
        'records_analyzed' => 9276,
        'records_created' => 340,
        'records_updated' => 87,
        'records_unchanged' => 8849,
        'records_critical' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertSuccessful()
        ->assertSee('Visualizar Importação #'.$run->getKey())
        ->assertSee('Parcelas')
        ->assertSee('carteira_08_2026.xlsx')
        ->assertSee('Anderson')
        ->assertSee('24/08/2026 14:32')
        ->assertSee(str_repeat('a', 64))
        ->assertSee('Carteira completa')
        ->assertSee('9.276')
        ->assertSee('8.849')
        ->assertSee('Concluída');
});

it('names the contract when the import was scoped to one', function () {
    $user = importHistoryUser();

    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->create())
        ->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00123']);

    $run = ImportRun::factory()->installments()->create(['contract_id' => $contract->id]);

    expect($run->coverageLabel())->toBe('CVC-00123');

    Livewire::actingAs($user)
        ->test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertSuccessful()
        ->assertSee('CVC-00123');
});

it('points out a file whose content was already processed before', function () {
    $user = importHistoryUser();

    $checksum = str_repeat('b', 64);
    $first = ImportRun::factory()->create(['checksum' => $checksum]);
    $second = ImportRun::factory()->unchanged()->create(['checksum' => $checksum]);
    $only = ImportRun::factory()->create(['checksum' => str_repeat('c', 64)]);

    expect($first->fileWasProcessedBefore())->toBeTrue()
        ->and($second->fileWasProcessedBefore())->toBeTrue()
        ->and($only->fileWasProcessedBefore())->toBeFalse();

    Livewire::actingAs($user)
        ->test(ViewImportRun::class, ['record' => $second->getKey()])
        ->assertSee('Arquivo já processado anteriormente');
});

// ── Somente leitura ───────────────────────────────────────────────────────

it('exposes only listing and viewing', function () {
    $run = ImportRun::factory()->create();

    expect(array_keys(ImportRunResource::getPages()))->toBe(['index', 'view'])
        ->and(ImportRunResource::canCreate())->toBeFalse()
        ->and(ImportRunResource::canEdit($run))->toBeFalse()
        ->and(ImportRunResource::canDelete($run))->toBeFalse()
        ->and(ImportRunResource::canDeleteAny())->toBeFalse()
        ->and(ImportRunResource::canForceDelete($run))->toBeFalse()
        ->and(ImportRunResource::canForceDeleteAny())->toBeFalse()
        ->and(ImportRunResource::canRestore($run))->toBeFalse()
        ->and(ImportRunResource::canRestoreAny())->toBeFalse();
});

it('offers no destructive action on the history table', function () {
    $user = importHistoryUser();

    $run = ImportRun::factory()->create();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertTableActionExists('view', record: $run)
        ->assertTableActionDoesNotExist('edit', record: $run)
        ->assertTableActionDoesNotExist('delete', record: $run)
        ->assertTableActionDoesNotExist('forceDelete', record: $run)
        ->assertTableActionDoesNotExist('restore', record: $run)
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore')
        ->assertActionDoesNotExist('create');
});

it('keeps the listing free of toolbar and bulk actions', function () {
    $user = importHistoryUser();

    $table = Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->instance()
        ->getTable();

    expect($table->getToolbarActions())->toBe([]);
});

// ── Filtros ───────────────────────────────────────────────────────────────

it('filters by import type', function () {
    $user = importHistoryUser();

    $contracts = ImportRun::factory()->create();
    $installments = ImportRun::factory()->installments()->create();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->filterTable('type', ImportRun::TYPE_CONTRACTS)
        ->assertCanSeeTableRecords([$contracts])
        ->assertCanNotSeeTableRecords([$installments]);
});

it('filters by the user who ran the import', function () {
    $user = importHistoryUser();

    $mine = ImportRun::factory()->create(['user_id' => $user->id]);
    $theirs = ImportRun::factory()->create();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->filterTable('user_id', $user->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('filters by period', function () {
    $user = importHistoryUser();

    $inside = ImportRun::factory()->create(['created_at' => '2026-08-10 08:00:00']);
    $before = ImportRun::factory()->create(['created_at' => '2026-07-31 23:59:00']);
    $after = ImportRun::factory()->create(['created_at' => '2026-09-01 00:01:00']);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->filterTable('created_at', ['from' => '2026-08-01', 'until' => '2026-08-31'])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$before, $after]);
});

it('filters by the derived outcome', function () {
    $user = importHistoryUser();

    $completed = ImportRun::factory()->create();
    $unchanged = ImportRun::factory()->unchanged()->create();
    $critical = ImportRun::factory()->withCriticalUpdates()->create();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->filterTable('result', ImportRun::RESULT_UNCHANGED)
        ->assertCanSeeTableRecords([$unchanged])
        ->assertCanNotSeeTableRecords([$completed, $critical])
        ->filterTable('result', ImportRun::RESULT_CRITICAL)
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$completed, $unchanged])
        ->filterTable('result', ImportRun::RESULT_COMPLETED)
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$critical, $unchanged]);
});

// ── Pesquisa ──────────────────────────────────────────────────────────────

it('searches by file name', function () {
    $user = importHistoryUser();

    $wanted = ImportRun::factory()->create(['file_name' => 'carteira_agosto.xlsx']);
    $other = ImportRun::factory()->create(['file_name' => 'contratos_julho.xlsx']);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->searchTable('carteira_agosto')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

it('searches by checksum, whole or partial', function () {
    $user = importHistoryUser();

    $checksum = hash('sha256', 'posicao-de-agosto');
    $wanted = ImportRun::factory()->create(['checksum' => $checksum]);
    $other = ImportRun::factory()->create(['checksum' => hash('sha256', 'outra-posicao')]);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->searchTable($checksum)
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable(substr($checksum, 0, 12))
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

it('searches by the user who ran the import', function () {
    $user = importHistoryUser();

    $wanted = ImportRun::factory()->create([
        'user_id' => User::factory()->create(['name' => 'Anderson Cavalcante'])->id,
    ]);
    $other = ImportRun::factory()->create([
        'user_id' => User::factory()->create(['name' => 'Outra Pessoa'])->id,
    ]);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->searchTable('Anderson Cavalcante')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

// ── Tela vazia ────────────────────────────────────────────────────────────

it('explains the empty history instead of showing a bare table', function () {
    $user = importHistoryUser();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertSuccessful()
        ->assertSee('Nenhuma importação registrada')
        ->assertSee('As próximas importações e conciliações confirmadas de contratos e parcelas serão exibidas aqui.');
});

// ── Imutabilidade do resultado ────────────────────────────────────────────

it('keeps the counters of an execution untouched by later changes to the records', function () {
    $user = importHistoryUser();

    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->create())
        ->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00500']);

    $run = ImportRun::factory()->create([
        'contract_id' => $contract->id,
        'records_created' => 12,
        'records_updated' => 87,
        'records_unchanged' => 1250,
    ]);

    $contract->update(['sale_value' => 999999.99]);
    Contract::factory()
        ->forUnit(ConstructionUnit::factory()->create())
        ->forClient(Client::factory()->create())
        ->create();

    $run->refresh();

    expect($run->records_created)->toBe(12)
        ->and($run->records_updated)->toBe(87)
        ->and($run->records_unchanged)->toBe(1250);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertTableColumnStateSet('records_updated', 87, $run)
        ->assertTableColumnStateSet('records_unchanged', 1250, $run);
});

// ── Integração com a conciliação ──────────────────────────────────────────

it('records the name the operator uploaded, not the generated storage name', function () {
    $user = makeAdminUser();
    $this->actingAs($user);

    [, $construction] = unitEmissionAndConstruction();

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);
    Contract::factory()
        ->forUnit($unit)
        ->forClient(Client::factory()->create())
        ->create(['code' => 'CVC-00123']);

    $path = temporaryTestFilePath('import-run-history');
    $headers = ContractInstallmentSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);
    $writer->addRow(array_combine($headers, [
        'CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', '10000.00', '', '', '',
    ]));
    $writer->close();

    $storedPath = 'imports/contract-installments/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), [
            'file' => ['upload' => $storedPath],
            'original_file_name' => 'carteira_08_2026.xlsx',
        ])
        ->assertHasNoActionErrors();

    $run = ImportRun::query()->sole();

    expect($run->file_name)->toBe('carteira_08_2026.xlsx')
        ->and($run->type)->toBe(ImportRun::TYPE_CONTRACT_INSTALLMENTS)
        ->and($run->user_id)->toBe($user->id);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$run])
        ->assertTableColumnStateSet('file_name', 'carteira_08_2026.xlsx', $run);
});

/**
 * The upload is stored under a generated name, so the name the operator
 * recognises only reaches the history because the component keeps it aside. The
 * resolved state path proves it lands inside the action data the import reads.
 */
it('keeps the original file name beside the stored upload on both import screens', function (string $page, string $action) {
    $this->actingAs(makeAdminUser());

    $component = Livewire::test($page)->mountAction($action)->instance();

    $upload = collect($component->getSchema($component->getMountedActionSchemaName())->getFlatComponents())
        ->first(fn ($field): bool => $field instanceof FileUpload);

    expect($upload)->not->toBeNull()
        ->and($upload->getFileNamesStatePath())->toEndWith('data.original_file_name');
})->with([
    'parcelas' => [ListContractInstallments::class, 'importContractInstallments'],
    'contratos' => [ListContracts::class, 'importContracts'],
]);
