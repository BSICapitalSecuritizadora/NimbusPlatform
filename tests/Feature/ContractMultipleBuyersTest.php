<?php

use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Actions\Contracts\ImportContractsFromSpreadsheet;
use App\Enums\ContractStatus;
use App\Enums\ReconciliationOutcome;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\ImportRun;
use App\Support\ActivityLog\ActivityPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

/**
 * Part of the `parity` group: the buyer set is a many-to-many with a unique
 * index, filled by bulk inserts and compared as a set, and every one of those
 * behaves differently enough between SQLite and MySQL to be worth proving twice.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @return array{0: Emission, 1: Construction, 2: ConstructionUnit, 3: ConstructionUnit, 4: Client, 5: Client}
 */
function buyersScenario(): array
{
    [$emission, $construction] = unitEmissionAndConstruction();

    $unit305 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);
    $unit402 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);

    $joao = Client::factory()->create(['name' => 'João da Silva', 'document' => '52998224725']);
    $maria = Client::factory()->create(['name' => 'Maria da Silva', 'document' => '11144477735']);

    return [$emission, $construction, $unit305, $unit402, $joao, $maria];
}

/**
 * @return array<int, string|null>
 */
function buyerRow(array $overrides = []): array
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

/**
 * @param  list<array<int, string|null>>  $rows
 */
function analyzeBuyers(array $rows)
{
    $path = temporaryTestFilePath('buyers-import');
    $headers = ContractSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    return app(AnalyzeContractSpreadsheet::class)->handle($path);
}

// ── Contratos novos ───────────────────────────────────────────────────────

it('creates one contract with one buyer', function () {
    buyersScenario();

    $analysis = analyzeBuyers([buyerRow()]);
    $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    $contract = Contract::query()->sole();

    expect($result['created'])->toBe(1)
        ->and($contract->buyerIds())->toHaveCount(1);
});

it('creates one contract from two lines that differ only by the buyer', function () {
    [, , , , $joao, $maria] = buyersScenario();

    $analysis = analyzeBuyers([
        buyerRow(),
        buyerRow(['document' => '11144477735']),
    ]);

    expect($analysis->newCount())->toBe(1)
        ->and($analysis->canImport())->toBeTrue();

    $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    $contract = Contract::query()->sole();

    expect($result['created'])->toBe(1)
        ->and(Contract::query()->count())->toBe(1)
        ->and($contract->clients->pluck('name')->all())->toBe(['João da Silva', 'Maria da Silva'])
        ->and(collect($contract->buyerIds())->sort()->values()->all())
        ->toBe(collect([$joao->id, $maria->id])->sort()->values()->all());
});

it('creates one contract from three buyers', function () {
    buyersScenario();
    Client::factory()->create(['name' => 'Carlos Souza', 'document' => '15350946056']);

    $analysis = analyzeBuyers([
        buyerRow(),
        buyerRow(['document' => '11144477735']),
        buyerRow(['document' => '15350946056']),
    ]);

    app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    expect(Contract::query()->count())->toBe(1)
        ->and(Contract::query()->sole()->clients)->toHaveCount(3);
});

it('does not care in which order the buyers were listed', function () {
    buyersScenario();

    $first = analyzeBuyers([buyerRow(), buyerRow(['document' => '11144477735'])]);
    $second = analyzeBuyers([buyerRow(['document' => '11144477735']), buyerRow()]);

    expect($first->collect()->first()['client_ids'])
        ->toBe(array_reverse($second->collect()->first()['client_ids']));

    app(ImportContractsFromSpreadsheet::class)->handle($first);

    expect(Contract::query()->sole()->buyerIds())->toHaveCount(2);
});

it('counts a buyer listed twice only once, and says so', function () {
    buyersScenario();

    $analysis = analyzeBuyers([buyerRow(), buyerRow()]);

    $row = $analysis->collect()->first();

    expect($analysis->canImport())->toBeTrue()
        ->and($row['outcome'])->toBe(ReconciliationOutcome::New)
        ->and($row['client_ids'])->toHaveCount(1)
        ->and($row['message'])->toContain('Comprador repetido')
        ->and($row['message'])->toContain('João da Silva')
        ->and($row['message'])->toContain('linhas 2 e 3');

    app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    expect(DB::table('contract_clients')->count())->toBe(1);
});

it('refuses a buyer that is not registered', function () {
    buyersScenario();

    $analysis = analyzeBuyers([buyerRow(), buyerRow(['document' => '39053344705'])]);

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->collect()->last()['message'])->toContain('não encontrado');

    expect(Contract::query()->count())->toBe(0);
});

it('refuses an archived buyer', function () {
    [, , , , , $maria] = buyersScenario();

    $maria->delete();

    $analysis = analyzeBuyers([buyerRow(), buyerRow(['document' => '11144477735'])]);

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->last()['message'])->toContain('excluído');
});

it('refuses lines of one contract that disagree about the sale value', function () {
    buyersScenario();

    $analysis = analyzeBuyers([
        buyerRow(),
        buyerRow(['document' => '11144477735', 'sale_value' => '600000.00']),
    ]);

    $row = $analysis->collect()->first();

    expect($analysis->canImport())->toBeFalse()
        ->and($row['outcome'])->toBe(ReconciliationOutcome::Conflict)
        ->and($row['message'])->toContain('valores divergentes')
        ->and($row['message'])->toContain('Valor da venda')
        ->and($row['message'])->toContain('linha 2')
        ->and($row['message'])->toContain('linha 3');

    expect(Contract::query()->count())->toBe(0);
});

// ── Contratos existentes ──────────────────────────────────────────────────

it('leaves a contract alone when the file brings the same buyers', function () {
    [, , $unit305, , $joao, $maria] = buyersScenario();

    Contract::factory()->forUnit($unit305)->withBuyers($joao, $maria)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 850000.00,
        'status' => ContractStatus::Active,
    ]);

    // Ordem invertida no arquivo: um conjunto não tem ordem.
    $analysis = analyzeBuyers([
        buyerRow(['document' => '11144477735']),
        buyerRow(),
    ]);

    expect($analysis->unchangedCount())->toBe(1)
        ->and($analysis->writeCount())->toBe(0);

    $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    expect($result['updated'])->toBe(0)
        ->and(Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->count())->toBe(0);
});

it('treats an added buyer as a critical update', function () {
    [, , $unit305, , $joao, $maria] = buyersScenario();

    $contract = Contract::factory()->forUnit($unit305)->forClient($joao)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 850000.00,
        'status' => ContractStatus::Active,
    ]);

    $analysis = analyzeBuyers([buyerRow(), buyerRow(['document' => '11144477735'])]);

    $row = $analysis->collect()->first();

    expect($row['outcome'])->toBe(ReconciliationOutcome::CriticalUpdate)
        ->and($analysis->canImport())->toBeTrue()
        ->and($row['message'])->toContain('+ Maria da Silva');

    $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    expect($result['updated'])->toBe(1)
        ->and(collect($contract->fresh()->buyerIds())->sort()->values()->all())
        ->toBe(collect([$joao->id, $maria->id])->sort()->values()->all());
});

it('refuses to remove a buyer through the monthly file', function () {
    [, , $unit305, , $joao, $maria] = buyersScenario();

    $contract = Contract::factory()->forUnit($unit305)->withBuyers($joao, $maria)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 850000.00,
        'status' => ContractStatus::Active,
    ]);

    $analysis = analyzeBuyers([buyerRow()]);

    $row = $analysis->collect()->first();

    expect($row['outcome'])->toBe(ReconciliationOutcome::Conflict)
        ->and($analysis->canImport())->toBeFalse()
        ->and($row['message'])->toContain('- Maria da Silva')
        ->and($contract->fresh()->buyerIds())->toHaveCount(2);
});

it('refuses to swap one buyer for another through the monthly file', function () {
    [, , $unit305, , $joao] = buyersScenario();

    Contract::factory()->forUnit($unit305)->forClient($joao)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 850000.00,
        'status' => ContractStatus::Active,
    ]);

    $analysis = analyzeBuyers([buyerRow(['document' => '11144477735'])]);

    $row = $analysis->collect()->first();

    expect($row['outcome'])->toBe(ReconciliationOutcome::Conflict)
        ->and($analysis->canImport())->toBeFalse()
        ->and($row['message'])->toContain('+ Maria da Silva')
        ->and($row['message'])->toContain('- João da Silva');
});

it('lifts a routine field change to critical when a buyer arrives with it', function () {
    [, , $unit305, , $joao] = buyersScenario();

    Contract::factory()->forUnit($unit305)->forClient($joao)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 700000.00,
        'status' => ContractStatus::Active,
    ]);

    $analysis = analyzeBuyers([buyerRow(), buyerRow(['document' => '11144477735'])]);

    expect($analysis->collect()->first()['outcome'])->toBe(ReconciliationOutcome::CriticalUpdate);

    app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    $contract = Contract::query()->sole();

    expect((float) $contract->sale_value)->toBe(850000.00)
        ->and($contract->buyerIds())->toHaveCount(2);
});

it('writes nothing at all when a buyer removal blocks the file', function () {
    [, , $unit305, $unit402, $joao, $maria] = buyersScenario();

    $contract = Contract::factory()->forUnit($unit305)->withBuyers($joao, $maria)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 850000.00,
        'status' => ContractStatus::Active,
    ]);

    $analysis = analyzeBuyers([
        buyerRow(['sale_value' => '900000.00']),
        buyerRow(['unit' => '402', 'code' => 'CVC-00500', 'document' => '11144477735']),
    ]);

    expect($analysis->canImport())->toBeFalse();

    expect(fn () => app(ImportContractsFromSpreadsheet::class)->handle($analysis))
        ->toThrow(RuntimeException::class);

    expect((float) $contract->fresh()->sale_value)->toBe(850000.00)
        ->and(Contract::query()->count())->toBe(1)
        ->and($contract->fresh()->buyerIds())->toHaveCount(2);
});

// ── Formulário ────────────────────────────────────────────────────────────

it('creates a contract with two buyers through the form', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit305, , $joao, $maria] = buyersScenario();

    Livewire::test(CreateContract::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'construction_unit_id' => $unit305->id,
            'client_ids' => [$joao->id, $maria->id],
            'code' => 'CVC-00777',
            'sale_date' => '2024-03-10',
            'sale_value' => '850.000,00',
            'status' => ContractStatus::Active->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contract = Contract::query()->sole();

    expect($contract->clients->pluck('name')->all())->toBe(['João da Silva', 'Maria da Silva']);
});

it('refuses a contract with no buyer at all', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit305] = buyersScenario();

    Livewire::test(CreateContract::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'construction_unit_id' => $unit305->id,
            'client_ids' => [],
            'code' => 'CVC-00777',
            'sale_date' => '2024-03-10',
            'sale_value' => '850.000,00',
            'status' => ContractStatus::Active->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['client_ids']);

    expect(Contract::query()->count())->toBe(0);
});

it('adds and removes buyers by hand, and refuses to leave none', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit305, , $joao, $maria] = buyersScenario();

    $contract = Contract::factory()->forUnit($unit305)->forClient($joao)->create();

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->assertFormSet(['client_ids' => [$joao->id]])
        ->fillForm(['client_ids' => [$joao->id, $maria->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contract->fresh()->buyerIds())->toHaveCount(2);

    // Remover pela mão é permitido: é um ato deliberado, não uma omissão de arquivo.
    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->fillForm(['client_ids' => [$maria->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contract->fresh()->buyerIds())->toBe([$maria->id]);

    // Mas não até sobrar nenhum.
    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->fillForm(['client_ids' => []])
        ->call('save')
        ->assertHasFormErrors(['client_ids']);

    expect($contract->fresh()->buyerIds())->toBe([$maria->id]);
});

// ── Activity Log ──────────────────────────────────────────────────────────

it('records a buyer change made by hand, without a batch and without documents', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit305, , $joao, $maria] = buyersScenario();

    $contract = Contract::factory()->forUnit($unit305)->forClient($joao)->create();

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->fillForm(['client_ids' => [$joao->id, $maria->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()
        ->where('subject_type', Contract::class)
        ->where('event', 'updated')
        ->latest('id')
        ->sole();

    $properties = $activity->properties;

    expect($activity->batch_uuid)->toBeNull()
        ->and($properties->get('old')['client_ids'])->toBe([$joao->id])
        ->and(collect($properties->get('attributes')['client_ids'])->sort()->values()->all())
        ->toBe(collect([$joao->id, $maria->id])->sort()->values()->all())
        // Nenhum documento pessoal entra no log.
        ->and(json_encode($properties->toArray()))->not->toContain($joao->document)
        ->and(json_encode($properties->toArray()))->not->toContain($maria->document);
});

it('does not record anything when the buyer set only changes order', function () {
    [, , $unit305, , $joao, $maria] = buyersScenario();

    $contract = Contract::factory()->forUnit($unit305)->withBuyers($joao, $maria)->create();

    $before = Activity::query()->count();

    expect($contract->syncBuyers([$maria->id, $joao->id]))->toBeFalse()
        ->and(Activity::query()->count())->toBe($before);
});

it('reads a buyer change back as names', function () {
    [, , $unit305, , $joao, $maria] = buyersScenario();

    $contract = Contract::factory()->forUnit($unit305)->forClient($joao)->create();
    $contract->syncBuyers([$joao->id, $maria->id]);

    $activity = Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->sole();

    $change = collect(ActivityPresenter::changesFor($activity))->firstWhere('key', 'client_ids');

    expect($change->label)->toBe('Compradores')
        ->and($change->old)->toBe('João da Silva')
        ->and($change->new)->toContain('João da Silva')
        ->and($change->new)->toContain('Maria da Silva');
});

// ── Busca e filtro ────────────────────────────────────────────────────────

it('finds a contract by any of its buyers', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit305, $unit402, $joao, $maria] = buyersScenario();

    $shared = Contract::factory()->forUnit($unit305)->withBuyers($joao, $maria)->create(['code' => 'CVC-00123']);
    $other = Contract::factory()->forUnit($unit402)->forClient(
        Client::factory()->create(['name' => 'Outra Pessoa', 'document' => '39053344705'])
    )->create(['code' => 'CVC-00999']);

    foreach (['João da Silva', 'Maria da Silva', '52998224725', '11144477735'] as $term) {
        Livewire::test(ListContracts::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

it('filters contracts by any of their buyers', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit305, $unit402, $joao, $maria] = buyersScenario();

    $shared = Contract::factory()->forUnit($unit305)->withBuyers($joao, $maria)->create();
    $other = Contract::factory()->forUnit($unit402)->forClient(
        Client::factory()->create(['document' => '39053344705'])
    )->create();

    Livewire::test(ListContracts::class)
        ->filterTable('buyer', $maria->id)
        ->assertCanSeeTableRecords([$shared])
        ->assertCanNotSeeTableRecords([$other]);
});

// ── Correlação com o Histórico de Importações ─────────────────────────────

it('puts a buyer change confirmed by an import inside the run batch', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit305, , $joao, $maria] = buyersScenario();

    Contract::factory()->forUnit($unit305)->forClient($joao)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 850000.00,
        'status' => ContractStatus::Active,
    ]);

    $path = temporaryTestFilePath('buyers-run');
    $headers = ContractSpreadsheetColumns::headers();
    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ([buyerRow(), buyerRow(['document' => '11144477735'])] as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    $storedPath = 'imports/contracts/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContracts::class)
        ->callAction(TestAction::make('importContracts'), ['file' => ['upload' => $storedPath]])
        ->assertHasNoActionErrors();

    $run = ImportRun::query()->sole();

    $activity = Activity::query()
        ->where('subject_type', Contract::class)
        ->where('event', 'updated')
        ->sole();

    expect($run->batch_uuid)->not->toBeNull()
        ->and($activity->batch_uuid)->toBe($run->batch_uuid)
        ->and($run->activities()->where('subject_type', Contract::class)->count())->toBe(1);

    // Uma edição manual depois não pode entrar naquela importação.
    Contract::query()->sole()->syncBuyers([$maria->id]);

    expect($run->activities()->where('subject_type', Contract::class)->count())->toBe(1)
        ->and(Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->count())->toBe(2);
});

// ── A coluna legada realmente morreu ──────────────────────────────────────

/**
 * O teste decisivo da Fase C.
 *
 * Na Fase B esta prova era feita gravando um cliente divergente em
 * `contracts.client_id` e mostrando que nenhuma leitura o consultava. Agora a
 * coluna não existe, então a prova mudou de natureza e ficou mais forte: todos
 * os caminhos de leitura são exercitados contra uma tabela que não tem a coluna.
 * Qualquer consulta que ainda a mencionasse quebraria com erro de SQL, não com
 * uma asserção -- não há como esse teste passar por acidente.
 */
it('runs every read path with the single-buyer column physically absent', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit305, $unit402, $joao, $maria] = buyersScenario();

    expect(Schema::hasColumn('contracts', 'client_id'))->toBeFalse();

    $shared = Contract::factory()->forUnit($unit305)->withBuyers($joao, $maria)->create([
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => 850000.00,
        'status' => ContractStatus::Active,
    ]);

    $other = Contract::factory()->forUnit($unit402)->forClient(
        Client::factory()->create(['name' => 'Outra Pessoa', 'document' => '39053344705'])
    )->create(['code' => 'CVC-00999']);

    // Listagem e busca por qualquer comprador.
    foreach (['João da Silva', 'Maria da Silva', '52998224725', '11144477735'] as $term) {
        Livewire::test(ListContracts::class)
            ->assertSuccessful()
            ->searchTable($term)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$other]);
    }

    // Filtro por comprador.
    Livewire::test(ListContracts::class)
        ->filterTable('buyer', $maria->id)
        ->assertCanSeeTableRecords([$shared])
        ->assertCanNotSeeTableRecords([$other]);

    // Global search do Filament.
    expect(ContractResource::getGloballySearchableAttributes())
        ->toContain('clients.name', 'clients.document')
        ->and(ContractResource::getGlobalSearchResultDetails($shared))
        ->toHaveKey('Compradores');

    // Visualização detalhada.
    Livewire::test(ViewContract::class, ['record' => $shared->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('João da Silva')
        ->assertSee('Maria da Silva');

    // Conciliação: o conjunto do arquivo é igual ao do banco.
    $analysis = analyzeBuyers([buyerRow(), buyerRow(['document' => '11144477735'])]);

    expect($analysis->unchangedCount())->toBe(1)
        ->and($analysis->writeCount())->toBe(0);

    // Parcelas: Contract -> Clients, e o filtro por comprador atravessa os dois.
    $installment = ContractInstallment::factory()->for($shared)->create(['number' => '001']);

    Livewire::test(ListContractInstallments::class)
        ->assertSuccessful()
        ->filterTable('client', $joao->id)
        ->assertCanSeeTableRecords([$installment]);

    // Activity da relação, ainda legível.
    $shared->syncBuyers([$joao->id]);

    $activity = Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->sole();

    expect($activity->properties->get('attributes')['client_ids'])->toBe([$joao->id]);

    // Ocupação e timeline seguem decididas pelo contrato, não pelos compradores.
    expect(Contract::occupyingContract($unit305->id)?->getKey())->toBe($shared->getKey())
        ->and(Contract::occupancyPeriodsFor($unit305->id))->toHaveCount(1);
});

it('creates contracts through every path with no single-buyer column to write', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit305, $unit402, $joao, $maria] = buyersScenario();

    // Formulário.
    Livewire::test(CreateContract::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'construction_unit_id' => $unit402->id,
            'client_ids' => [$joao->id, $maria->id],
            'code' => 'CVC-00888',
            'sale_date' => '2024-03-10',
            'sale_value' => '850.000,00',
            'status' => ContractStatus::Active->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // Importação.
    app(ImportContractsFromSpreadsheet::class)->handle(analyzeBuyers([
        buyerRow(),
        buyerRow(['document' => '11144477735']),
    ]));

    expect(Contract::query()->count())->toBe(2)
        ->and(Schema::hasColumn('contracts', 'client_id'))->toBeFalse()
        ->and(DB::table('contract_clients')->count())->toBe(4);
});
