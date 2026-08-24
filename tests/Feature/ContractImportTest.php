<?php

use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetAnalysis;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Actions\Contracts\ContractSpreadsheetTemplate;
use App\Actions\Contracts\ImportContractsFromSpreadsheet;
use App\Enums\ContractStatus;
use App\Enums\ReconciliationOutcome;
use App\Exceptions\ContractImportConcurrencyException;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

uses(RefreshDatabase::class);

/**
 * Part of the `parity` group: everything here depends on how the database itself
 * behaves -- unique indexes, collation, generated columns, soft deletes against
 * uniqueness, and imports that resolve textual keys. The normal suite runs it on
 * SQLite; `composer test:parity` runs the same tests on MySQL, and both have to
 * agree. See scripts/parity-check.sh.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<array<int, string|null>>  $rows
 */
function contractSpreadsheet(array $rows, ?array $headers = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'contracts-import-').'.xlsx';
    $headers ??= ContractSpreadsheetColumns::headers();

    $writer = SimpleExcelWriter::create($path)->addHeader($headers);

    foreach ($rows as $row) {
        $writer->addRow(array_combine($headers, $row));
    }

    $writer->close();

    return $path;
}

function analyzeContractSpreadsheet(array $rows, ?array $headers = null)
{
    return app(AnalyzeContractSpreadsheet::class)->handle(contractSpreadsheet($rows, $headers));
}

/**
 * A development with two units and one registered buyer, which is the minimum
 * the importer needs to resolve a row.
 *
 * @return array{0: Emission, 1: Construction, 2: ConstructionUnit, 3: ConstructionUnit, 4: Client}
 */
function contractImportScenario(): array
{
    [$emission, $construction] = unitEmissionAndConstruction();

    $unit305 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);
    $unit402 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);

    $client = Client::factory()->create([
        'name' => 'João da Silva',
        'document' => '52998224725',
    ]);

    return [$emission, $construction, $unit305, $unit402, $client];
}

/**
 * @return array<int, string|null>
 */
function contractRow(array $overrides = []): array
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

it('builds a template whose data sheet carries only the headers', function () {
    $path = app(ContractSpreadsheetTemplate::class)->build();

    $dataRows = SimpleExcelReader::create($path)->getRows()->all();
    $exampleRows = SimpleExcelReader::create($path)->fromSheetName(ContractSpreadsheetTemplate::EXAMPLE_SHEET)->getRows()->all();

    expect($dataRows)->toBe([])
        ->and($exampleRows)->toHaveCount(2)
        ->and(array_keys($exampleRows[0]))->toBe(ContractSpreadsheetColumns::headers())
        ->and($exampleRows[0]['Contrato'])->toBe('CVC-00123')
        ->and($exampleRows[1]['Status'])->toBe('Distratado');

    // The example sheet is never read by the importer.
    expect(app(AnalyzeContractSpreadsheet::class)->handle($path)->fileErrors)
        ->toBe(['A planilha está vazia.']);
});

it('serves the template through the download route', function () {
    $this->actingAs(makeAdminUser());

    $this->get(route('admin.contracts.template.download'))
        ->assertSuccessful()
        ->assertDownload(ContractSpreadsheetTemplate::DOWNLOAD_NAME);
});

it('rejects a spreadsheet without the required columns', function () {
    $analysis = analyzeContractSpreadsheet([['CRI', 'Conviva']], ['Emissão', 'Empreendimento']);

    expect($analysis->fileErrors)->toBe([
        'A planilha não possui as colunas obrigatórias: Bloco, Unidade, CPF/CNPJ, Contrato, Data Venda, Valor Venda, Status.',
    ])->and($analysis->canImport())->toBeFalse();
});

it('accepts a spreadsheet without the optional distrato column', function () {
    contractImportScenario();

    $headers = array_values(array_diff(ContractSpreadsheetColumns::headers(), [ContractSpreadsheetColumns::CANCELLATION_DATE]));
    $row = contractRow();
    array_pop($row);

    $analysis = analyzeContractSpreadsheet([$row], $headers);

    expect($analysis->fileErrors)->toBe([])
        ->and($analysis->newCount())->toBe(1);
});

it('accepts a valid spreadsheet and resolves every relationship into ids', function () {
    [, $construction, $unit305, , $client] = contractImportScenario();

    $analysis = analyzeContractSpreadsheet([contractRow()]);

    expect($analysis->canImport())->toBeTrue()
        ->and($analysis->newCount())->toBe(1)
        ->and($analysis->blockingCount())->toBe(0);

    $row = $analysis->rowsToCreate()->first();

    expect($row['client_id'])->toBe($client->id)
        ->and($row['construction_unit_id'])->toBe($unit305->id)
        ->and($row['construction_id'])->toBe($construction->id)
        ->and($row['sale_date'])->toBe('2024-03-10')
        ->and($row['sale_value'])->toBe(850000.00)
        ->and($row['contract_status'])->toBe(ContractStatus::Active);
});

it('persists the approved rows inside a single transaction', function () {
    [, , $unit305, $unit402, $client] = contractImportScenario();
    $buyer = Client::factory()->create(['document' => '11144477735']);

    $analysis = analyzeContractSpreadsheet([
        contractRow(),
        contractRow([
            'unit' => '402',
            'document' => '11144477735',
            'code' => 'CVC-00124',
            'sale_date' => '05/02/2024',
            'sale_value' => '700000.00',
            'status' => 'Distratado',
            'cancellation_date' => '15/06/2025',
        ]),
    ]);

    $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    expect($result['created'])->toBe(2)
        ->and(Contract::query()->count())->toBe(2);

    $active = Contract::query()->where('code', 'CVC-00123')->sole();
    $cancelled = Contract::query()->where('code', 'CVC-00124')->sole();

    expect($active->client_id)->toBe($client->id)
        ->and($active->construction_unit_id)->toBe($unit305->id)
        ->and($active->status)->toBe(ContractStatus::Active)
        ->and($active->cancellation_date)->toBeNull()
        ->and((float) $active->sale_value)->toBe(850000.00)
        ->and($cancelled->client_id)->toBe($buyer->id)
        ->and($cancelled->construction_unit_id)->toBe($unit402->id)
        ->and($cancelled->status)->toBe(ContractStatus::Cancelled)
        ->and($cancelled->cancellation_date->toDateString())->toBe('2025-06-15');
});

it('reads a permuta in any of the spellings the sheet may bring', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([
        contractRow(['status' => 'Permutado']),
        contractRow(['unit' => '402', 'code' => 'CVC-00124', 'status' => 'permuta']),
    ]);

    expect($analysis->errorCount())->toBe(0)
        ->and($analysis->rowsToCreate()->pluck('contract_status')->all())
        ->toBe([ContractStatus::Exchanged, ContractStatus::Exchanged]);
});

it('accepts a value written in the brazilian format', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([contractRow(['sale_value' => '850.000,00'])]);

    expect($analysis->rowsToCreate()->first()['sale_value'])->toBe(850000.00);
});

it('never creates a client that does not exist', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([contractRow(['document' => '11144477735'])]);

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->rows[0]['message'])->toBe('Cliente com o CPF/CNPJ 111.444.777-35 não encontrado.')
        ->and(Client::query()->count())->toBe(1);
});

it('never creates a unit that does not exist', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([contractRow(['unit' => '999'])]);

    expect($analysis->canImport())->toBeFalse()
        ->and($analysis->rows[0]['message'])->toBe('Unidade 999 do bloco 01 não encontrada no empreendimento Conviva Camboinhas.')
        ->and(ConstructionUnit::query()->count())->toBe(2);
});

it('never creates an emission or a development that does not exist', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([
        contractRow(['emission' => 'CRI Inexistente']),
        contractRow(['construction' => 'Empreendimento Inexistente', 'code' => 'CVC-00124']),
    ]);

    expect($analysis->errorCount())->toBe(2)
        ->and($analysis->rows[0]['message'])->toBe('Emissão não encontrada.')
        ->and($analysis->rows[1]['message'])->toBe('Empreendimento não encontrado.')
        ->and(Emission::query()->count())->toBe(1)
        ->and(Construction::query()->count())->toBe(1);
});

it('rejects a development that belongs to another emission', function () {
    contractImportScenario();
    $otherEmission = Emission::factory()->create(['name' => 'CRA Outro']);
    Construction::factory()->create([
        'emission_id' => $otherEmission->id,
        'development_name' => 'Empreendimento XYZ',
    ]);

    $analysis = analyzeContractSpreadsheet([contractRow(['construction' => 'Empreendimento XYZ'])]);

    expect($analysis->rows[0]['message'])->toBe('O empreendimento informado não pertence à emissão selecionada.');
});

/**
 * The code identifies a contract inside its development, so a row carrying it
 * describes *that* contract -- and describing it as belonging to another unit
 * and another buyer is not an update, it is a mismatch a person has to look at.
 */
it('refuses to move an existing contract to another buyer or unit', function () {
    [, , , $unit402] = contractImportScenario();

    Contract::factory()->forUnit($unit402)->create(['code' => 'CVC-00123']);

    $analysis = analyzeContractSpreadsheet([contractRow()]);

    expect($analysis->conflictCount())->toBe(1)
        ->and($analysis->newCount())->toBe(0)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->rows[0]['message'])->toContain('Cliente')
        ->and($analysis->rows[0]['message'])->toContain('Unidade');
});

it('rejects a unit that already has a live contract', function () {
    [, , $unit305] = contractImportScenario();

    Contract::factory()->forUnit($unit305)->create(['code' => 'CVC-00001']);

    $analysis = analyzeContractSpreadsheet([contractRow()]);

    expect($analysis->conflictCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->rows[0]['message'])->toContain('Esta unidade já possui um contrato ativo (CVC-00001).');
});

it('imports a resale when the previous contract is distratado', function () {
    [, , $unit305] = contractImportScenario();

    Contract::factory()->forUnit($unit305)->cancelled()->create([
        'code' => 'CVC-00001',
        'sale_date' => '2022-01-10',
        'cancellation_date' => '2023-05-10',
    ]);

    $analysis = analyzeContractSpreadsheet([contractRow()]);

    expect($analysis->canImport())->toBeTrue();

    app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    expect($unit305->contracts()->count())->toBe(2)
        ->and($unit305->activeContract()->first()->code)->toBe('CVC-00123');
});

it('matches a code differing only by case against the same contract', function () {
    [, $construction, $unit305, $unit402] = contractImportScenario();

    $existing = Contract::factory()->forUnit($unit305)->create(['code' => 'A606']);

    // " a606 " resolves to the very same contract, so the row is compared to it
    // instead of opening a second one.
    $analysis = analyzeContractSpreadsheet([contractRow(['unit' => '402', 'code' => ' a606 '])]);

    expect($analysis->newCount())->toBe(0)
        ->and($analysis->conflictCount())->toBe(1)
        ->and($analysis->collect()->first()['contract_id'])->toBe($existing->id)
        ->and($construction->id)->toBeGreaterThan(0)
        ->and($unit402->id)->toBeGreaterThan(0);
});

it('never opens a new contract over a code held by a deleted one', function () {
    [, , $unit305] = contractImportScenario();

    Contract::factory()->forUnit($unit305)->create(['code' => 'A606'])->delete();

    $analysis = analyzeContractSpreadsheet([contractRow(['unit' => '402', 'code' => 'a606'])]);

    expect($analysis->conflictCount())->toBe(1)
        ->and($analysis->newCount())->toBe(0)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->collect()->first()['message'])->toContain('excluído')
        ->and(Contract::query()->count())->toBe(0);
});

it('detects two codes differing only by case inside the spreadsheet', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([
        contractRow(['code' => 'A606']),
        contractRow(['unit' => '402', 'document' => '52998224725', 'code' => 'a606']),
    ]);

    expect($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->rows[1]['message'])->toStartWith('Contrato repetido na planilha (linha 2).');
});

it('keeps the same code in two developments as two different contracts', function () {
    [$emission, , $unit305] = contractImportScenario();

    $otherConstruction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Conviva Piratininga',
    ]);
    ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => '02', 'unit' => '101']);

    $analysis = analyzeContractSpreadsheet([
        contractRow(['code' => 'A606']),
        contractRow(['construction' => 'Conviva Piratininga', 'block' => '02', 'unit' => '101', 'code' => 'a606']),
    ]);

    expect($analysis->newCount())->toBe(2)
        ->and($analysis->duplicatedInFileCount())->toBe(0)
        ->and($analysis->canImport())->toBeTrue()
        ->and($unit305->id)->toBeGreaterThan(0);
});

it('persists the identity alongside the code the spreadsheet carried', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([contractRow(['code' => ' Ctr-001 '])]);

    app(ImportContractsFromSpreadsheet::class)->handle($analysis);

    $contract = Contract::query()->sole();

    expect($contract->code)->toBe('Ctr-001')
        ->and($contract->code_normalized)->toBe('CTR-001');
});

it('rejects a line repeated inside the spreadsheet', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([
        contractRow(),
        contractRow(['unit' => '402']),
    ]);

    expect($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->rows[1]['message'])->toStartWith('Contrato repetido na planilha (linha 2).');
});

it('rejects two live contracts for the same unit inside the spreadsheet', function () {
    contractImportScenario();
    Client::factory()->create(['document' => '11144477735']);

    $analysis = analyzeContractSpreadsheet([
        contractRow(),
        contractRow(['document' => '11144477735', 'code' => 'CVC-00124']),
    ]);

    // Both rows are refused, not just the second one: which of them came first
    // is an accident of how the file was typed, and neither is more wrong than
    // the other.
    expect($analysis->conflictCount())->toBe(2)
        ->and($analysis->newCount())->toBe(0)
        ->and($analysis->canImport())->toBeFalse()
        ->and($analysis->rows[0]['message'])->toBe('A unidade 01 - 305 ficaria vinculada a mais de um contrato ocupante após esta importação: CVC-00123 e CVC-00124.')
        ->and($analysis->rows[1]['message'])->toBe($analysis->rows[0]['message']);
});

it('validates the dates and the status of every row', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([
        contractRow(['status' => 'Suspenso']),
        contractRow(['code' => 'CVC-2', 'unit' => '402', 'sale_date' => '31/02/2024']),
        contractRow(['code' => 'CVC-3', 'status' => 'Distratado', 'cancellation_date' => '']),
        contractRow(['code' => 'CVC-4', 'status' => 'Distratado', 'cancellation_date' => '02/01/2024']),
        contractRow(['code' => 'CVC-5', 'cancellation_date' => '15/06/2025']),
        contractRow(['code' => 'CVC-6', 'sale_value' => '0']),
    ]);

    expect($analysis->errorCount())->toBe(6)
        ->and($analysis->rows[0]['message'])->toContain('Status "Suspenso" não reconhecido.')
        ->and($analysis->rows[1]['message'])->toBe('Data da venda inválida. Utilize o formato dd/mm/aaaa.')
        ->and($analysis->rows[2]['message'])->toBe('Contratos distratados exigem a data do distrato.')
        ->and($analysis->rows[3]['message'])->toBe('A data do distrato não pode ser anterior à data da venda.')
        ->and($analysis->rows[4]['message'])->toBe('Contratos com status Ativo não podem ter data de distrato.')
        ->and($analysis->rows[5]['message'])->toBe('Valor da venda inválido: informe um valor maior que zero.');
});

it('rejects a document that is neither a cpf nor a cnpj', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([contractRow(['document' => '12345'])]);

    expect($analysis->rows[0]['message'])->toBe('CPF/CNPJ inválido: informe 11 dígitos para CPF ou 14 para CNPJ.');
});

it('points out the missing required fields', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([contractRow(['code' => '', 'sale_value' => ''])]);

    expect($analysis->rows[0]['message'])->toBe('Campos obrigatórios não preenchidos: Contrato, Valor Venda.');
});

it('refuses to import a spreadsheet with any inconsistency', function () {
    contractImportScenario();

    $analysis = analyzeContractSpreadsheet([
        contractRow(),
        contractRow(['unit' => '402', 'code' => 'CVC-00124', 'document' => '11144477735']),
    ]);

    expect($analysis->newCount())->toBe(1)
        ->and($analysis->errorCount())->toBe(1)
        ->and($analysis->canImport())->toBeFalse();

    expect(fn () => app(ImportContractsFromSpreadsheet::class)->handle($analysis))
        ->toThrow(RuntimeException::class);

    expect(Contract::query()->count())->toBe(0);
});

it('imports contracts through the listing wizard', function () {
    $this->actingAs(makeAdminUser());

    contractImportScenario();

    $path = contractSpreadsheet([contractRow()]);
    $storedPath = 'imports/contracts/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContracts::class)
        ->assertActionExists('downloadTemplate')
        ->assertActionHasLabel('downloadTemplate', 'Baixar Modelo')
        ->assertActionExists('importContracts')
        ->assertActionHasLabel('importContracts', 'Importar Contratos')
        ->callAction(TestAction::make('importContracts'), ['file' => ['upload' => $storedPath]])
        ->assertHasNoActionErrors();

    expect(Contract::query()->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'importacao-contratos')->exists())->toBeTrue();
});

it('imports nothing when the wizard receives an inconsistent spreadsheet', function () {
    $this->actingAs(makeAdminUser());

    contractImportScenario();

    $path = contractSpreadsheet([contractRow(['document' => ClientFactory::validCnpj()])]);
    $storedPath = 'imports/contracts/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    Livewire::test(ListContracts::class)
        ->callAction(TestAction::make('importContracts'), ['file' => ['upload' => $storedPath]]);

    expect(Contract::query()->count())->toBe(0);
});

describe('reconciliação mensal', function () {
    it('is idempotent: re-importing the same file writes nothing', function () {
        contractImportScenario();

        $rows = [contractRow()];

        $first = app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet($rows));

        expect($first['created'])->toBe(1)
            ->and($first['updated'])->toBe(0);

        $touchedAt = Contract::query()->pluck('updated_at', 'id');
        $activityBefore = Activity::query()->where('subject_type', Contract::class)->count();

        $second = analyzeContractSpreadsheet($rows);

        expect($second->unchangedCount())->toBe(1)
            ->and($second->newCount())->toBe(0)
            ->and($second->writeCount())->toBe(0)
            ->and($second->canImport())->toBeTrue();

        $result = app(ImportContractsFromSpreadsheet::class)->handle($second);

        expect($result['created'])->toBe(0)
            ->and($result['updated'])->toBe(0)
            ->and($result['unchanged'])->toBe(1)
            ->and(Contract::query()->count())->toBe(1)
            ->and(Contract::query()->pluck('updated_at', 'id')->toArray())->toEqual($touchedAt->toArray())
            ->and(Activity::query()->where('subject_type', Contract::class)->count())->toBe($activityBefore);
    });

    it('reads a distrato arriving on a live contract as a normal update', function () {
        contractImportScenario();

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet([contractRow()]));

        $analysis = analyzeContractSpreadsheet([contractRow([
            'status' => 'Distratado',
            'cancellation_date' => '15/09/2026',
        ])]);

        expect($analysis->updateCount())->toBe(1)
            ->and($analysis->criticalUpdateCount())->toBe(0);

        $changes = collect($analysis->collect()->first()['comparison']->changes);

        expect($changes->pluck('field')->all())->toBe(['status', 'cancellation_date'])
            ->and($changes->first()->currentForDisplay())->toBe('Ativo')
            ->and($changes->first()->newForDisplay())->toBe('Distratado');

        app(ImportContractsFromSpreadsheet::class)->handle($analysis);

        $contract = Contract::query()->sole();

        expect($contract->status)->toBe(ContractStatus::Cancelled)
            ->and($contract->cancellation_date->toDateString())->toBe('2026-09-15');
    });

    it('flags a distratado contract going back to ativo as a critical update', function () {
        contractImportScenario();

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet([
            contractRow(['status' => 'Distratado', 'cancellation_date' => '15/09/2026']),
        ]));

        $analysis = analyzeContractSpreadsheet([contractRow()]);

        expect($analysis->criticalUpdateCount())->toBe(1)
            ->and($analysis->updateCount())->toBe(0);
    });

    it('flags the sale value and the sale date moving as critical updates', function () {
        contractImportScenario();

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet([contractRow()]));

        $valueMoved = analyzeContractSpreadsheet([contractRow(['sale_value' => '900000.00'])]);
        $dateMoved = analyzeContractSpreadsheet([contractRow(['sale_date' => '11/03/2024'])]);

        expect($valueMoved->criticalUpdateCount())->toBe(1)
            ->and($valueMoved->collect()->first()['message'])->toBe('Valor da venda: R$ 850.000,00 → R$ 900.000,00')
            ->and($dateMoved->criticalUpdateCount())->toBe(1);
    });

    it('reads the same values written differently as sem alteração', function () {
        contractImportScenario();

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet([contractRow()]));

        // Brazilian money formatting and a masked document against stored values.
        $analysis = analyzeContractSpreadsheet([contractRow([
            'sale_value' => '850.000,00',
            'document' => '529.982.247-25',
        ])]);

        expect($analysis->unchangedCount())->toBe(1)
            ->and($analysis->writeCount())->toBe(0);
    });

    it('audits a contract update the way a manual edit is audited', function () {
        contractImportScenario();

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet([contractRow()]));

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet([
            contractRow(['status' => 'Distratado', 'cancellation_date' => '15/09/2026']),
        ]));

        $activity = Activity::query()
            ->where('subject_type', Contract::class)
            ->where('event', 'updated')
            ->sole();

        expect($activity->properties['old']['status'])->toBe('ativo')
            ->and($activity->properties['attributes']['status'])->toBe('distratado')
            ->and($activity->properties['attributes']['cancellation_date'])->toStartWith('2026-09-15');
    });

    it('leaves a contract absent from the file completely alone', function () {
        [, , , $unit402] = contractImportScenario();

        $absent = Contract::factory()->forUnit($unit402)->create(['code' => 'OUTRO-001']);
        $before = $absent->updated_at;

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet([contractRow()]));

        $absent->refresh();

        expect($absent->exists)->toBeTrue()
            ->and($absent->trashed())->toBeFalse()
            ->and($absent->status)->toBe(ContractStatus::Active)
            ->and($absent->updated_at->eq($before))->toBeTrue();
    });
});

/**
 * A resale is a distrato plus a new contract, and a monthly file reports both at
 * once. What follows is the batch reading of that file: the spreadsheet is a
 * position, not a sequence of instructions, so what the unit ends up holding is
 * decided from the whole set of rows and never from the order they were typed
 * in.
 *
 * The protections are unchanged. Only an explicit, valid distrato frees a unit;
 * quitado and permutado keep holding it; a contract the file does not mention
 * keeps holding it; and two contracts on one unit is refused whether they come
 * from the database, from the file, or from both.
 */
describe('distrato e revenda no mesmo lote', function () {
    /**
     * The state every resale test starts from: unit 305 sold to João under
     * CVC-00001, and Maria registered as the next buyer.
     *
     * @return array{0: ConstructionUnit, 1: Contract, 2: Client}
     */
    function resaleScenario(ContractStatus $status = ContractStatus::Active): array
    {
        [, , $unit305, , $seller] = contractImportScenario();

        $existing = Contract::factory()->forUnit($unit305)->forClient($seller)->create([
            'code' => 'CVC-00001',
            'sale_date' => '2024-03-10',
            'sale_value' => '850000.00',
            'status' => $status,
            'cancellation_date' => $status->requiresCancellationDate() ? '2026-08-15' : null,
        ]);

        Client::factory()->create(['name' => 'Maria Oliveira', 'document' => '11144477735']);

        return [$unit305, $existing, $seller];
    }

    /**
     * The row that distrata CVC-00001.
     *
     * @return array<int, string|null>
     */
    function distratoRow(array $overrides = []): array
    {
        return contractRow([
            'code' => 'CVC-00001',
            'sale_date' => '10/03/2024',
            'status' => 'Distratado',
            'cancellation_date' => '15/08/2026',
            ...$overrides,
        ]);
    }

    /**
     * The row that opens CVC-00002 on the same unit, for the new buyer.
     *
     * @return array<int, string|null>
     */
    function revendaRow(array $overrides = []): array
    {
        return contractRow([
            'document' => '11144477735',
            'code' => 'CVC-00002',
            'sale_date' => '20/08/2026',
            'sale_value' => '900000.00',
            ...$overrides,
        ]);
    }

    it('imports a distrato and the resale that follows it in one file', function () {
        [$unit305, $existing] = resaleScenario();

        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        expect($analysis->canImport())->toBeTrue()
            ->and($analysis->conflictCount())->toBe(0)
            ->and($analysis->updateCount())->toBe(1)
            ->and($analysis->newCount())->toBe(1)
            ->and($analysis->resaleCount())->toBe(1);

        $result = app(ImportContractsFromSpreadsheet::class)->handle($analysis);

        expect($result['created'])->toBe(1)
            ->and($result['updated'])->toBe(1);

        $existing->refresh();
        $resale = Contract::query()->where('code', 'CVC-00002')->sole();

        expect($existing->status)->toBe(ContractStatus::Cancelled)
            ->and($existing->cancellation_date->toDateString())->toBe('2026-08-15')
            ->and($resale->status)->toBe(ContractStatus::Active)
            ->and($resale->sale_date->toDateString())->toBe('2026-08-20')
            ->and($resale->client->name)->toBe('Maria Oliveira')
            ->and($unit305->contracts()->count())->toBe(2)
            ->and($unit305->activeContract()->first()->code)->toBe('CVC-00002');
    });

    it('reaches the same verdict whichever row comes first', function () {
        [$unit305] = resaleScenario();

        // Two files describing the same position, differing only in typing
        // order. Analysing writes nothing, so both run against one database.
        $inOrder = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);
        $reversed = analyzeContractSpreadsheet([revendaRow(), distratoRow()]);

        $verdictPerContract = fn (ContractSpreadsheetAnalysis $analysis): array => $analysis
            ->collect()
            ->mapWithKeys(fn (array $row): array => [$row['code'] => $row['outcome']->value])
            ->sortKeys()
            ->all();

        expect($inOrder->canImport())->toBeTrue()
            ->and($reversed->canImport())->toBeTrue()
            ->and($reversed->resaleCount())->toBe($inOrder->resaleCount())
            ->and($verdictPerContract($reversed))->toBe($verdictPerContract($inOrder));

        app(ImportContractsFromSpreadsheet::class)->handle($reversed);

        expect($unit305->activeContract()->first()->code)->toBe('CVC-00002');
    });

    it('explains on the preview why the new contract is not a conflict', function () {
        resaleScenario();

        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        $resale = $analysis->collect()->firstWhere('code', 'CVC-00002');

        expect($resale['message'])->toBe('Revenda: o contrato CVC-00001 (João da Silva) é distratado nesta mesma planilha em 15/08/2026.');
    });

    it('reports a sale predating the distrato without refusing it', function () {
        [$unit305] = resaleScenario();

        // A distrato is routinely signed after the commercial fact, so the dates
        // overlapping is worth seeing and not worth refusing.
        $analysis = analyzeContractSpreadsheet([
            distratoRow(['cancellation_date' => '20/08/2026']),
            revendaRow(['sale_date' => '10/08/2026']),
        ]);

        $resale = $analysis->collect()->firstWhere('code', 'CVC-00002');

        expect($analysis->canImport())->toBeTrue()
            ->and($resale['outcome'])->toBe(ReconciliationOutcome::New)
            ->and($resale['message'])->toContain('Atenção: a venda em 10/08/2026 é anterior ao distrato em 20/08/2026.');

        app(ImportContractsFromSpreadsheet::class)->handle($analysis);

        expect($unit305->activeContract()->first()->code)->toBe('CVC-00002');
    });

    it('refuses a resale when the previous contract only becomes quitado', function () {
        resaleScenario();

        // Quitado still ties the unit to its buyer, so it frees nothing.
        $analysis = analyzeContractSpreadsheet([
            distratoRow(['status' => 'Quitado', 'cancellation_date' => '']),
            revendaRow(),
        ]);

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->conflictCount())->toBe(2)
            ->and($analysis->collect()->firstWhere('code', 'CVC-00002')['message'])
            ->toBe('A unidade 01 - 305 ficaria vinculada a mais de um contrato ocupante após esta importação: CVC-00001 e CVC-00002.');
    });

    it('refuses a resale when the file never distrata the previous contract', function () {
        resaleScenario();

        $analysis = analyzeContractSpreadsheet([revendaRow()]);

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->conflictCount())->toBe(1)
            ->and($analysis->collect()->sole()['message'])
            ->toBe('Esta unidade já possui um contrato ativo (CVC-00001). Registre o distrato antes de importar um novo contrato.');
    });

    it('never lets an invalid distrato free the unit', function () {
        resaleScenario();

        // The distrato has no date, so it cannot be applied -- and a change that
        // cannot be applied may not make room for anybody.
        $analysis = analyzeContractSpreadsheet([
            distratoRow(['cancellation_date' => '']),
            revendaRow(),
        ]);

        $distrato = $analysis->collect()->firstWhere('code', 'CVC-00001');
        $resale = $analysis->collect()->firstWhere('code', 'CVC-00002');

        expect($analysis->canImport())->toBeFalse()
            ->and($distrato['outcome'])->toBe(ReconciliationOutcome::Error)
            ->and($distrato['message'])->toBe('Contratos distratados exigem a data do distrato.')
            ->and($resale['outcome'])->toBe(ReconciliationOutcome::Conflict)
            ->and($resale['message'])->toContain('já possui um contrato ativo (CVC-00001)');
    });

    it('refuses two new contracts fighting over the freed unit', function () {
        resaleScenario();
        Client::factory()->create(['document' => '19131243201']);

        $analysis = analyzeContractSpreadsheet([
            distratoRow(),
            revendaRow(),
            revendaRow(['document' => '19131243201', 'code' => 'CVC-00003']),
        ]);

        $message = 'A unidade 01 - 305 ficaria vinculada a mais de um contrato ocupante após esta importação: CVC-00002 e CVC-00003.';

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->conflictCount())->toBe(2)
            ->and($analysis->collect()->firstWhere('code', 'CVC-00002')['message'])->toBe($message)
            ->and($analysis->collect()->firstWhere('code', 'CVC-00003')['message'])->toBe($message)
            // The distrato itself is not the problem and is not blamed for it.
            ->and($analysis->collect()->firstWhere('code', 'CVC-00001')['outcome'])->toBe(ReconciliationOutcome::Update);

        expect(fn () => app(ImportContractsFromSpreadsheet::class)->handle($analysis))
            ->toThrow(RuntimeException::class);

        expect(Contract::query()->count())->toBe(1);
    });

    it('ignores contracts distratados long ago when judging the unit', function () {
        [$unit305, $existing] = resaleScenario();

        // Two distratos already on the unit's history. Occupancy is about who
        // holds it, never about how many contracts it has had.
        Contract::factory()->forUnit($unit305)->cancelled()->create(['code' => 'CVC-90001']);
        Contract::factory()->forUnit($unit305)->cancelled()->create(['code' => 'CVC-90002']);

        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        expect($analysis->canImport())->toBeTrue();

        app(ImportContractsFromSpreadsheet::class)->handle($analysis);

        $existing->refresh();

        expect($unit305->contracts()->count())->toBe(4)
            ->and($existing->status)->toBe(ContractStatus::Cancelled)
            ->and($unit305->activeContract()->first()->code)->toBe('CVC-00002');
    });

    it('refuses a new contract over a unit held by a quitado contract', function () {
        resaleScenario(ContractStatus::Settled);

        $analysis = analyzeContractSpreadsheet([revendaRow()]);

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->collect()->sole()['message'])
            ->toBe('Esta unidade já possui um contrato quitado (CVC-00001). Registre o distrato antes de importar um novo contrato.');
    });

    it('refuses a new contract over a unit held by a permutado contract', function () {
        resaleScenario(ContractStatus::Exchanged);

        $analysis = analyzeContractSpreadsheet([revendaRow()]);

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->collect()->sole()['message'])
            ->toBe('Esta unidade já possui um contrato permutado (CVC-00001). Registre o distrato antes de importar um novo contrato.');
    });

    it('lets a new distratado contract share the unit with a new live one', function () {
        [, , $unit305, , $client] = contractImportScenario();
        Client::factory()->create(['name' => 'Maria Oliveira', 'document' => '11144477735']);

        // Neither contract exists yet and only one of them holds the unit, so
        // the other one being on the same unit is history, not a conflict.
        $analysis = analyzeContractSpreadsheet([
            contractRow(['code' => 'CVC-00010', 'status' => 'Distratado', 'cancellation_date' => '15/08/2026']),
            contractRow(['code' => 'CVC-00011', 'document' => '11144477735', 'sale_date' => '20/08/2026']),
        ]);

        expect($analysis->canImport())->toBeTrue()
            ->and($analysis->newCount())->toBe(2)
            // Nothing was freed, so this is not reported as a resale either.
            ->and($analysis->resaleCount())->toBe(0);

        app(ImportContractsFromSpreadsheet::class)->handle($analysis);

        expect($unit305->contracts()->count())->toBe(2)
            ->and($unit305->activeContract()->first()->code)->toBe('CVC-00011')
            ->and($client->id)->toBeGreaterThan(0);
    });

    it('counts a critical reactivation as an occupant of the unit', function () {
        [$unit305] = resaleScenario(ContractStatus::Cancelled);

        // CVC-00001 comes back to ativo -- a critical update, and one that takes
        // the unit back. The new contract can no longer have it.
        $analysis = analyzeContractSpreadsheet([
            distratoRow(['status' => 'Ativo', 'cancellation_date' => '']),
            revendaRow(),
        ]);

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->conflictCount())->toBe(2)
            ->and($analysis->criticalUpdateCount())->toBe(0)
            ->and($analysis->collect()->firstWhere('code', 'CVC-00002')['message'])
            ->toBe('A unidade 01 - 305 ficaria vinculada a mais de um contrato ocupante após esta importação: CVC-00001 e CVC-00002.')
            ->and($unit305->activeContract()->first())->toBeNull();
    });

    it('is idempotent: re-importing the same resale writes nothing', function () {
        resaleScenario();

        $rows = [distratoRow(), revendaRow()];

        app(ImportContractsFromSpreadsheet::class)->handle(analyzeContractSpreadsheet($rows));

        $touchedAt = Contract::query()->pluck('updated_at', 'id');
        $activityBefore = Activity::query()->where('subject_type', Contract::class)->count();

        $second = analyzeContractSpreadsheet($rows);

        expect($second->unchangedCount())->toBe(2)
            ->and($second->writeCount())->toBe(0)
            ->and($second->conflictCount())->toBe(0)
            ->and($second->canImport())->toBeTrue();

        $result = app(ImportContractsFromSpreadsheet::class)->handle($second);

        expect($result['created'])->toBe(0)
            ->and($result['updated'])->toBe(0)
            ->and(Contract::query()->count())->toBe(2)
            ->and(Contract::query()->pluck('updated_at', 'id')->toArray())->toEqual($touchedAt->toArray())
            ->and(Activity::query()->where('subject_type', Contract::class)->count())->toBe($activityBefore);
    });

    it('audits the distrato and the new contract only once confirmed', function () {
        [, $existing] = resaleScenario();

        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        expect(Activity::query()->where('subject_type', Contract::class)->where('event', 'updated')->count())->toBe(0);

        app(ImportContractsFromSpreadsheet::class)->handle($analysis);

        $activity = Activity::query()
            ->where('subject_type', Contract::class)
            ->where('subject_id', $existing->id)
            ->where('event', 'updated')
            ->sole();

        expect($activity->properties['old']['status'])->toBe('ativo')
            ->and($activity->properties['attributes']['status'])->toBe('distratado')
            ->and($activity->properties['attributes']['cancellation_date'])->toStartWith('2026-08-15');
    });

    it('rolls the whole resale back when creating the new contract fails', function () {
        [$unit305, $existing] = resaleScenario();

        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        // The unit disappears between the analysis and the write, so the insert
        // of the new contract fails on its foreign key.
        $rows = array_map(function (array $row) use ($unit305): array {
            return ($row['code'] === 'CVC-00002')
                ? [...$row, 'construction_unit_id' => $unit305->id + 9999]
                : $row;
        }, $analysis->rows);

        $sabotaged = new ContractSpreadsheetAnalysis($rows, unitOccupancies: $analysis->unitOccupancies);

        expect(fn () => app(ImportContractsFromSpreadsheet::class)->handle($sabotaged))
            ->toThrow(QueryException::class);

        $existing->refresh();

        // No half resale: the unit is neither empty nor doubly sold.
        expect($existing->status)->toBe(ContractStatus::Active)
            ->and($existing->cancellation_date)->toBeNull()
            ->and(Contract::query()->count())->toBe(1)
            ->and($unit305->activeContract()->first()->code)->toBe('CVC-00001');
    });

    it('refuses to confirm when the unit moved after the analysis', function () {
        [$unit305, $existing] = resaleScenario();

        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        expect($analysis->canImport())->toBeTrue();

        // Someone else settles the position while the conference screen is open.
        $existing->forceFill(['status' => ContractStatus::Cancelled, 'cancellation_date' => '2026-08-15'])->save();
        $intruder = Contract::factory()->forUnit($unit305)->create(['code' => 'CVC-99999']);

        expect(fn () => app(ImportContractsFromSpreadsheet::class)->handle($analysis))
            ->toThrow(
                ContractImportConcurrencyException::class,
                'A posição da unidade foi alterada após a análise. Revise novamente a importação antes de confirmar. (unidade 01 - 305)',
            );

        $intruder->refresh();

        expect(Contract::query()->count())->toBe(2)
            ->and($intruder->status)->toBe(ContractStatus::Active)
            ->and(Contract::query()->where('code', 'CVC-00002')->exists())->toBeFalse();
    });

    it('frees the unit before the contract taking it back is written', function () {
        [$unit305, $existing] = resaleScenario();

        // An older contract of the same unit, distratado, that the file brings
        // back to ativo. Writing it before CVC-00001 lets go of the unit would
        // put two holders on it for the length of one statement, which is
        // exactly what the unique index refuses.
        $returning = Contract::factory()->forUnit($unit305)->create([
            'code' => 'CVC-00050',
            'client_id' => Client::query()->where('document', '11144477735')->value('id'),
            'sale_date' => '2023-01-20',
            'sale_value' => '700000.00',
            'status' => ContractStatus::Cancelled,
            'cancellation_date' => '2024-03-01',
        ]);

        $analysis = analyzeContractSpreadsheet([
            // Deliberately first: the order of the file must not decide the
            // order of the statements.
            contractRow([
                'document' => '11144477735',
                'code' => 'CVC-00050',
                'sale_date' => '20/01/2023',
                'sale_value' => '700000.00',
                'status' => 'Ativo',
            ]),
            distratoRow(),
        ]);

        expect($analysis->canImport())->toBeTrue()
            ->and($analysis->updateCount())->toBe(1)
            ->and($analysis->criticalUpdateCount())->toBe(1)
            ->and($analysis->rowsToUpdate()->first()['code'])->toBe('CVC-00001');

        app(ImportContractsFromSpreadsheet::class)->handle($analysis);

        $existing->refresh();
        $returning->refresh();

        expect($existing->status)->toBe(ContractStatus::Cancelled)
            ->and($returning->status)->toBe(ContractStatus::Active)
            ->and($returning->cancellation_date)->toBeNull()
            ->and($unit305->activeContract()->first()->code)->toBe('CVC-00050');
    });

    it('turns a lost race with the unique index into a readable message', function () {
        [$unit305, $existing] = resaleScenario();

        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        // The projection stripped away, which is the blind spot a contract
        // committed after the guard would open: the unique index is the only
        // thing left, and it must not reach the operator as SQL.
        $unguarded = new ContractSpreadsheetAnalysis($analysis->rows);

        $existing->forceFill([
            'status' => ContractStatus::Cancelled,
            'cancellation_date' => '2026-08-15',
        ])->save();

        Contract::factory()->forUnit($unit305)->create(['code' => 'CVC-99999']);

        expect(fn () => app(ImportContractsFromSpreadsheet::class)->handle($unguarded))
            ->toThrow(
                ContractImportConcurrencyException::class,
                'A posição da unidade foi alterada após a análise. Revise novamente a importação antes de confirmar.',
            );

        expect(Contract::query()->where('code', 'CVC-00002')->exists())->toBeFalse()
            ->and($unit305->activeContract()->first()->code)->toBe('CVC-99999');
    });

    it('keeps a soft deleted code reserved even when the unit is freed', function () {
        [$unit305] = resaleScenario();

        Contract::factory()->forUnit($unit305)->cancelled()->create(['code' => 'CVC-00002'])->delete();

        // The unit is free in the projection, but the code is not: identity and
        // occupancy are different rules and the distrato answers only one.
        $analysis = analyzeContractSpreadsheet([distratoRow(), revendaRow()]);

        expect($analysis->canImport())->toBeFalse()
            ->and($analysis->collect()->firstWhere('code', 'CVC-00002')['message'])
            ->toContain('Existe um contrato excluído com este código');
    });
});
