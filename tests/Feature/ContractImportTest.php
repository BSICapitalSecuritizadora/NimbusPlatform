<?php

use App\Actions\Contracts\AnalyzeContractSpreadsheet;
use App\Actions\Contracts\ContractSpreadsheetColumns;
use App\Actions\Contracts\ContractSpreadsheetTemplate;
use App\Actions\Contracts\ImportContractsFromSpreadsheet;
use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use Database\Factories\ClientFactory;
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

    expect($analysis->duplicatedInFileCount())->toBe(1)
        ->and($analysis->rows[1]['message'])->toBe('Esta unidade já recebe um contrato ativo na linha 2 da planilha.');
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
