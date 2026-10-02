<?php

use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Enums\AccessPermission;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\ImportRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
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
        ->assertSee('24/08/2026 · 14:32:00');
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
        ->assertSee('As próximas importações e conciliações confirmadas de contratos, parcelas, unidades e valores de unidade serão exibidas aqui.');
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
    $writer->addRow(array_combine($headers, array_pad([
        'CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', '10000.00', '', '', '',
    ], count($headers), '')));
    $writer->close();

    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), [
            'file' => spreadsheetUpload($path, 'carteira_08_2026.xlsx'),
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
 * The upload is no longer stored under a generated name while the wizard is
 * open: it stays the temporary file Livewire signed, which keeps the name the
 * operator sent. Both import screens must record that name -- and the archived
 * copy of the very same bytes.
 */
it('records the uploaded name and archives the file on both import screens', function (string $screen) {
    $this->actingAs(makeAdminUser());

    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);
    $client = Client::factory()->create(['document' => '52998224725']);

    Contract::factory()->forUnit($unit)->forClient($client)->create(['code' => 'CVC-00123']);

    [$page, $action, $type, $headers, $row] = match ($screen) {
        'parcelas' => [
            ListContractInstallments::class,
            'importContractInstallments',
            ImportRun::TYPE_CONTRACT_INSTALLMENTS,
            ContractInstallmentSpreadsheetColumns::headers(),
            ['CRI Conviva', 'Conviva Camboinhas', 'CVC-00123', '001', '10/01/2026', '10000.00', '', '', ''],
        ],
        'contratos' => [
            ListContracts::class,
            'importContracts',
            ImportRun::TYPE_CONTRACTS,
            ContractSpreadsheetColumns::headers(),
            ['CRI Conviva', 'Conviva Camboinhas', '01', '305', '52998224725', 'CVC-00123', '10/03/2024', '850000.00', 'Ativo', ''],
        ],
    };

    $path = temporaryTestFilePath('import-run-history-'.$screen);
    SimpleExcelWriter::create($path)->addHeader($headers)->addRow(array_combine($headers, array_pad($row, count($headers), '')))->close();

    Livewire::test($page)
        ->callAction(TestAction::make($action), ['file' => spreadsheetUpload($path, 'posicao_'.$screen.'_08_2026.xlsx')])
        ->assertHasNoActionErrors();

    $run = ImportRun::query()->sole();

    expect($run->type)->toBe($type)
        ->and($run->file_name)->toBe('posicao_'.$screen.'_08_2026.xlsx')
        ->and($run->checksum)->toBe(hash_file('sha256', $path))
        ->and($run->file_path)->toStartWith('imports/')
        ->and(Storage::disk('local')->get((string) $run->file_path))->toBe(file_get_contents($path));
})->with(['parcelas', 'contratos']);

// ── Unidades, valores, ausências e proveniência ──────────────────────────

it('names the units and the unit values runs and filters by them', function () {
    $user = importHistoryUser();

    $units = ImportRun::factory()->units()->create();
    $values = ImportRun::factory()->unitValues()->create();
    $contracts = ImportRun::factory()->create();

    expect(ImportRun::typeOptions())->toMatchArray([
        ImportRun::TYPE_CONSTRUCTION_UNITS => 'Unidades',
        ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES => 'Valores de unidade',
    ])
        ->and($units->typeLabel())->toBe('Unidades')
        ->and($values->typeLabel())->toBe('Valores de unidade')
        ->and($units->coverageLabel())->toBe('Cadastro de unidades')
        ->and($values->coverageLabel())->toBe('Tabela de valores');

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->filterTable('type', ImportRun::TYPE_CONSTRUCTION_UNIT_VALUES)
        ->assertCanSeeTableRecords([$values])
        ->assertCanNotSeeTableRecords([$units, $contracts]);
});

it('counts the cancellation of absent installments as a result with critical changes', function () {
    $user = importHistoryUser();

    $cancelled = ImportRun::factory()->withCancelledAbsences()->create();
    $plain = ImportRun::factory()->create();

    expect($cancelled->result())->toBe(ImportRun::RESULT_CRITICAL)
        ->and($cancelled->madeChanges())->toBeTrue()
        ->and(ImportRun::factory()->unchanged()->withCancelledAbsences(1)->create()->madeChanges())->toBeTrue();

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->filterTable('result', ImportRun::RESULT_CRITICAL)
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$plain])
        ->filterTable('result', ImportRun::RESULT_COMPLETED)
        ->assertCanSeeTableRecords([$plain])
        ->assertCanNotSeeTableRecords([$cancelled]);
});

it('shows what the run created, the absences, the cancellation, the warnings and the archived file', function () {
    $user = importHistoryUser();

    [, $construction] = unitEmissionAndConstruction(status: 'active');

    $contract = Contract::factory()
        ->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())
        ->forClient(Client::factory()->create())
        ->create();

    $run = ImportRun::factory()->withCancelledAbsences(2, '2026-07-05', 'Renegociação com novo cronograma.')->create([
        'records_warned' => 4,
        'file_path' => 'imports/contract-installments/01k6abcdefghjkmnpqrstvwxyz.xlsx',
    ]);

    ContractInstallment::factory()->forContract($contract)->count(3)->create(['import_run_id' => $run->id]);

    Livewire::actingAs($user)
        ->test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertSuccessful()
        ->assertSee('Registros criados por esta importação')
        ->assertSee('Parcelas criadas')
        ->assertSee('Ausentes da planilha')
        ->assertSee('Parcelas canceladas por ausência')
        ->assertSee('05/07/2026')
        ->assertSee('Renegociação com novo cronograma.')
        ->assertSee('Com aviso')
        ->assertSee('imports/contract-installments/01k6abcdefghjkmnpqrstvwxyz.xlsx')
        ->assertSee('Concluída com críticas');

    expect($run->createdRecordsCount())->toBe(3);
});

it('keeps the absence, cancellation and warning columns available on the history table', function () {
    $user = importHistoryUser();

    $run = ImportRun::factory()->withCancelledAbsences(2)->create(['records_warned' => 4]);

    Livewire::actingAs($user)
        ->test(ListImportRuns::class)
        ->assertTableColumnExists('records_absent')
        ->assertTableColumnExists('records_cancelled')
        ->assertTableColumnExists('records_warned')
        ->assertTableColumnStateSet('records_cancelled', 2, $run)
        ->assertTableColumnStateSet('records_warned', 4, $run);
});

it('leads a units or values run back to the units listing', function () {
    $user = makeAdminUser();

    $run = ImportRun::factory()->unitValues()->create();

    Livewire::actingAs($user)
        ->test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertActionHasLabel('viewModule', 'Ver Unidades')
        ->assertActionHasUrl('viewModule', ConstructionUnitResource::getUrl());
});

it('filters contracts, installments and units by the run that created them', function (string $table) {
    $user = makeAdminUser();

    [, $construction] = unitEmissionAndConstruction(status: 'active');
    $run = ImportRun::factory()->create();

    $client = Client::factory()->create();
    $fromRun = ConstructionUnit::factory()->forConstruction($construction)->create(['import_run_id' => $run->id]);
    $manual = ConstructionUnit::factory()->forConstruction($construction)->create();

    $contractFromRun = Contract::factory()->forUnit($fromRun)->forClient($client)->create(['import_run_id' => $run->id]);
    $manualContract = Contract::factory()->forUnit($manual)->forClient($client)->create();

    $installmentFromRun = ContractInstallment::factory()->forContract($manualContract)->create(['number' => '001', 'import_run_id' => $run->id]);
    $manualInstallment = ContractInstallment::factory()->forContract($manualContract)->create(['number' => '002']);

    [$page, $wanted, $other] = match ($table) {
        'contratos' => [ListContracts::class, $contractFromRun, $manualContract],
        'parcelas' => [ListContractInstallments::class, $installmentFromRun, $manualInstallment],
        'unidades' => [ListConstructionUnits::class, $fromRun, $manual],
    };

    Livewire::actingAs($user)
        ->test($page)
        ->assertTableFilterExists('import_run')
        ->filterTable('import_run', ['import_run_id' => $run->id])
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['contratos', 'parcelas', 'unidades']);
