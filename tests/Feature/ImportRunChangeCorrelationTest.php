<?php

use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetColumns;
use App\Actions\Contracts\ContractSpreadsheetAnalysis;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Actions\Contracts\ImportContractsFromSpreadsheet;
use App\Enums\AccessPermission;
use App\Enums\ContractStatus;
use App\Exceptions\ContractImportConcurrencyException;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\ImportRunChangesRelationManager;
use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\ActivityLog\ActivityChange;
use App\Support\ActivityLog\ActivityPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\LogBatch;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * One development, one unit, one buyer and one contract -- the minimum both
 * importers need to resolve a row.
 *
 * @return array{0: Contract, 1: ConstructionUnit}
 */
function correlationScenario(): array
{
    [, $construction] = unitEmissionAndConstruction();

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);

    $contract = Contract::factory()
        ->forUnit($unit)
        ->forClient(Client::factory()->create(['name' => 'João da Silva', 'document' => '52998224725']))
        ->create([
            'code' => 'CVC-00123',
            'sale_date' => '2024-03-10',
            'sale_value' => 850000.00,
            'status' => ContractStatus::Active,
        ]);

    return [$contract, $unit];
}

/**
 * @param  list<array<int, string|null>>  $rows
 */
function correlationInstallmentImport(array $rows): void
{
    $path = temporaryTestFilePath('correlation-installments');
    $headers = ContractInstallmentSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    $storedPath = 'imports/contract-installments/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContractInstallments::class)
        ->callAction(TestAction::make('importContractInstallments'), ['file' => ['upload' => $storedPath]])
        ->assertHasNoActionErrors();
}

/**
 * @return array<int, string|null>
 */
function correlationInstallmentRow(array $overrides = []): array
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

/**
 * @param  list<array<int, string|null>>  $rows
 */
function correlationContractImport(array $rows): void
{
    $path = temporaryTestFilePath('correlation-contracts');
    $headers = ContractSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    $storedPath = 'imports/contracts/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContracts::class)
        ->callAction(TestAction::make('importContracts'), ['file' => ['upload' => $storedPath]])
        ->assertHasNoActionErrors();
}

/**
 * @return array<int, string|null>
 */
function correlationContractRow(array $overrides = []): array
{
    return array_values([
        'emission' => 'CRI Conviva',
        'construction' => 'Conviva Camboinhas',
        'block' => '01',
        'unit' => '305',
        'document' => '52998224725',
        'code' => 'CVC-00123',
        'sale_date' => '10/03/2024',
        'sale_value' => '850000.00',
        'status' => 'Ativo',
        'cancellation_date' => '',
        ...$overrides,
    ]);
}

function correlationHistoryUser(): User
{
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo(AccessPermission::AuditImportRunsView->value);

    return $user;
}

// ── Batch criado e correlacionado ─────────────────────────────────────────

it('stamps the batch of the execution on the import run and on every installment it changed', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['payment_date' => '25/08/2026', 'paid_value' => '2500.00']),
    ]);

    $run = ImportRun::query()->sole();

    expect($run->activity_batch_uuid)->not->toBeNull()
        ->and($run->hasChangeCorrelation())->toBeTrue()
        ->and($run->records_updated)->toBe(1);

    // The fixture itself logged a `created`; only the update belongs to the run.
    $activities = Activity::query()
        ->where('subject_type', ContractInstallment::class)
        ->where('event', 'updated')
        ->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->batch_uuid)->toBe($run->activity_batch_uuid)
        ->and(Activity::query()->where('event', 'created')->whereNotNull('batch_uuid')->count())->toBe(0);
});

it('stamps the batch on every contract it changed', function () {
    $this->actingAs(makeAdminUser());

    correlationScenario();

    correlationContractImport([
        correlationContractRow(['status' => 'Distratado', 'cancellation_date' => '20/08/2026']),
    ]);

    $run = ImportRun::query()->sole();

    $activity = Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->sole();

    expect($run->activity_batch_uuid)->not->toBeNull()
        ->and($activity->batch_uuid)->toBe($run->activity_batch_uuid)
        ->and($run->records_updated)->toBe(1);
});

it('correlates one activity per record the execution changed', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    foreach (['001', '002', '003'] as $index => $number) {
        ContractInstallment::factory()->for($contract)->create([
            'number' => $number,
            'due_date' => '2026-0'.($index + 1).'-10',
            'expected_value' => 10000.00,
        ]);
    }

    correlationInstallmentImport([
        correlationInstallmentRow(['number' => '001', 'due_date' => '10/01/2026', 'paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
        correlationInstallmentRow(['number' => '002', 'due_date' => '10/02/2026', 'paid_value' => '3000.00', 'payment_date' => '25/08/2026']),
        correlationInstallmentRow(['number' => '003', 'due_date' => '10/03/2026']),
    ]);

    $run = ImportRun::query()->sole();

    expect($run->records_updated)->toBe(2)
        ->and($run->activities()->where('subject_type', ContractInstallment::class)->count())->toBe(2);
});

it('keeps the execution activity inside the batch but out of the record listing', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    $run = ImportRun::query()->sole();

    $execution = $run->activities()->whereNull('subject_type')->get();

    expect($execution)->toHaveCount(1)
        ->and($execution->first()->log_name)->toBe('importacao-parcelas')
        ->and($execution->first()->getExtraProperty('importacao_id'))->toBe($run->getKey())
        ->and($run->activities()->count())->toBe(2);
});

// ── Isolamento entre execuções e edições manuais ──────────────────────────

it('excludes an edit made by hand after the import', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    $installment = ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    $run = ImportRun::query()->sole();

    // The very same record, edited by hand right afterwards.
    $installment->refresh()->update(['paid_value' => 2700.00]);

    expect(Activity::query()->where('subject_type', ContractInstallment::class)->where('event', 'updated')->count())->toBe(2)
        ->and($run->activities()->where('subject_type', ContractInstallment::class)->count())->toBe(1);

    $correlated = $run->activities()->where('subject_type', ContractInstallment::class)->sole();

    expect($correlated->properties->get('attributes')['paid_value'])->toBe('2500.00');
});

it('gives two imports of the same record two separate histories', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2000.00', 'payment_date' => '25/08/2026']),
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    $runs = ImportRun::query()->oldest('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->first()->activity_batch_uuid)->not->toBe($runs->last()->activity_batch_uuid);

    $first = $runs->first()->activities()->where('subject_type', ContractInstallment::class)->sole();
    $second = $runs->last()->activities()->where('subject_type', ContractInstallment::class)->sole();

    expect($first->properties->get('attributes')['paid_value'])->toBe('2000.00')
        ->and($second->properties->get('old')['paid_value'])->toBe('2000.00')
        ->and($second->properties->get('attributes')['paid_value'])->toBe('2500.00');
});

it('records a batch with no individual change for an idempotent re-run', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([correlationInstallmentRow()]);

    $run = ImportRun::query()->sole();

    expect($run->records_unchanged)->toBe(1)
        ->and($run->records_updated)->toBe(0)
        ->and($run->hasChangeCorrelation())->toBeTrue()
        ->and($run->activities()->where('subject_type', ContractInstallment::class)->count())->toBe(0);
});

// ── Rollback e vazamento de batch ─────────────────────────────────────────

it('closes the batch even when the execution throws', function () {
    $batch = app(LogBatch::class);

    try {
        $batch->withinBatch(function (): void {
            throw new RuntimeException('falha durante a importação');
        });
    } catch (RuntimeException) {
        // The failure is the point of the test.
    }

    expect($batch->isOpen())->toBeFalse()
        ->and($batch->getUuid())->toBeNull();
});

it('leaves no correlation behind when the import is rolled back', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    // A unit that moved between the conference and the confirmation aborts the
    // contract import inside the transaction, before anything is committed.
    app()->bind(
        ImportContractsFromSpreadsheet::class,
        fn (): object => new class extends ImportContractsFromSpreadsheet
        {
            public function handle(ContractSpreadsheetAnalysis $analysis): array
            {
                return DB::transaction(function (): array {
                    Contract::query()->first()?->update(['sale_value' => 1]);

                    throw ContractImportConcurrencyException::raced();
                });
            }
        },
    );

    correlationContractImport([correlationContractRow(['sale_value' => '900000.00'])]);

    expect(ImportRun::query()->count())->toBe(0)
        ->and(Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->count())->toBe(0)
        ->and(Activity::query()->whereNotNull('batch_uuid')->count())->toBe(0)
        ->and(Contract::query()->sole()->sale_value)->toBe('850000.00')
        ->and(app(LogBatch::class)->isOpen())->toBeFalse();
});

it('does not let a failed execution contaminate the next one', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    $leakedUuid = null;

    app()->bind(
        ImportContractsFromSpreadsheet::class,
        function () use (&$leakedUuid): object {
            $leakedUuid = app(LogBatch::class)->getUuid();

            return new class extends ImportContractsFromSpreadsheet
            {
                public function handle(ContractSpreadsheetAnalysis $analysis): array
                {
                    throw ContractImportConcurrencyException::raced();
                }
            };
        },
    );

    correlationContractImport([correlationContractRow(['sale_value' => '900000.00'])]);

    app()->forgetInstance(ImportContractsFromSpreadsheet::class);
    app()->bind(
        ImportContractsFromSpreadsheet::class,
        fn (): ImportContractsFromSpreadsheet => new ImportContractsFromSpreadsheet,
    );

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    $run = ImportRun::query()->sole();

    expect($leakedUuid)->not->toBeNull()
        ->and($run->activity_batch_uuid)->not->toBe($leakedUuid)
        ->and(Activity::query()->where('batch_uuid', $leakedUuid)->count())->toBe(0);
});

// ── Histórico legado ──────────────────────────────────────────────────────

it('opens a run recorded before the correlation existed', function () {
    $user = correlationHistoryUser();

    $run = ImportRun::factory()->create(['activity_batch_uuid' => null]);

    expect($run->hasChangeCorrelation())->toBeFalse()
        ->and($run->activities()->count())->toBe(0);

    Livewire::actingAs($user)
        ->test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertSuccessful();

    Livewire::actingAs($user)
        ->test(ImportRunChangesRelationManager::class, [
            'ownerRecord' => $run,
            'pageClass' => ViewImportRun::class,
        ])
        ->assertSuccessful()
        ->assertSee('Detalhamento individual indisponível');
});

it('does not sweep in the activities that have no batch at all', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    // A manual edit made before any import: no batch uuid whatsoever.
    $contract->update(['sale_value' => 900000.00]);

    $run = ImportRun::factory()->create(['activity_batch_uuid' => null]);

    expect(Activity::query()->whereNull('batch_uuid')->count())->toBeGreaterThan(0)
        ->and($run->activities()->count())->toBe(0);
});

// ── UI ────────────────────────────────────────────────────────────────────

it('lists the changes of the execution and nothing else', function () {
    $user = correlationHistoryUser();

    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    $installment = ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    $run = ImportRun::query()->sole();
    $mine = $run->activities()->where('subject_type', ContractInstallment::class)->sole();

    // Another execution, and a manual edit, both on the same installment.
    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2700.00', 'payment_date' => '25/08/2026']),
    ]);
    $installment->refresh()->update(['paid_value' => 3000.00]);

    $others = Activity::query()
        ->where('subject_type', ContractInstallment::class)
        ->where('event', 'updated')
        ->whereKeyNot($mine->getKey())
        ->get();

    // One from the second import, one from the edit made by hand.
    expect($others)->toHaveCount(2);

    Livewire::actingAs($user)
        ->test(ImportRunChangesRelationManager::class, [
            'ownerRecord' => $run,
            'pageClass' => ViewImportRun::class,
        ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords($others)
        ->assertSee('Contrato CVC-00123 · Parcela 001')
        ->assertSee('Valor pago');
});

it('shows the execution changes on the import view page', function () {
    $user = correlationHistoryUser();

    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    $run = ImportRun::query()->sole();

    $page = Livewire::actingAs($user)
        ->test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertSuccessful();

    expect($page->instance()->getRelationManagers())->not->toBeEmpty();

    $page->assertSeeLivewire(ImportRunChangesRelationManager::class);
});

it('keeps the label of a record deleted after the import', function () {
    $user = correlationHistoryUser();

    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    $installment = ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    $run = ImportRun::query()->sole();

    $installment->delete();

    Livewire::actingAs($user)
        ->test(ImportRunChangesRelationManager::class, [
            'ownerRecord' => $run,
            'pageClass' => ViewImportRun::class,
        ])
        ->assertSuccessful()
        ->assertSee('Contrato CVC-00123 · Parcela 001');
});

it('denies the change listing to a user without the history permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->assignRole('editor');

    $this->actingAs($user);

    $run = ImportRun::factory()->create();

    expect(ImportRunChangesRelationManager::canViewForRecord($run, ViewImportRun::class))->toBeFalse();
});

// ── Apresentação old → new ────────────────────────────────────────────────

it('presents money, dates, status and absent values the way the operator reads them', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    correlationInstallmentImport([
        correlationInstallmentRow(['paid_value' => '2500.00', 'payment_date' => '25/08/2026']),
    ]);

    correlationContractImport([
        correlationContractRow(['status' => 'Distratado', 'cancellation_date' => '20/08/2026']),
    ]);

    $installmentChange = collect(ActivityPresenter::changesFor(
        Activity::query()->where('subject_type', ContractInstallment::class)->where('event', 'updated')->sole(),
    ))->keyBy(fn (ActivityChange $change): string => $change->key);

    expect($installmentChange['paid_value']->label)->toBe('Valor pago')
        // Null antes, valor depois: o traço é a leitura de "não havia".
        ->and($installmentChange['paid_value']->old)->toBeNull()
        ->and($installmentChange['paid_value']->new)->toBe('R$ 2.500,00')
        ->and($installmentChange['payment_date']->label)->toBe('Data do pagamento')
        ->and($installmentChange['payment_date']->new)->toBe('25/08/2026');

    $contractChange = collect(ActivityPresenter::changesFor(
        Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->sole(),
    ))->keyBy(fn (ActivityChange $change): string => $change->key);

    expect($contractChange['status']->label)->toBe('Situação')
        ->and($contractChange['status']->old)->toBe('Ativo')
        ->and($contractChange['status']->new)->toBe('Distratado')
        ->and($contractChange['cancellation_date']->label)->toBe('Data do distrato')
        ->and($contractChange['cancellation_date']->new)->toBe('20/08/2026');
});

it('names the same column after the record it belongs to', function () {
    $this->actingAs(makeAdminUser());

    [$contract] = correlationScenario();

    $installment = ContractInstallment::factory()->for($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000.00,
    ]);

    $installment->update(['cancellation_date' => '2026-08-20']);
    $contract->update(['status' => ContractStatus::Cancelled, 'cancellation_date' => '2026-08-20']);

    $installmentLabels = array_map(
        fn (ActivityChange $change): string => $change->label,
        ActivityPresenter::changesFor(Activity::query()->where('subject_type', ContractInstallment::class)->where('event', 'updated')->sole()),
    );

    $contractLabels = array_map(
        fn (ActivityChange $change): string => $change->label,
        ActivityPresenter::changesFor(Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->sole()),
    );

    expect($installmentLabels)->toContain('Data de cancelamento')
        ->and($contractLabels)->toContain('Data do distrato');
});
