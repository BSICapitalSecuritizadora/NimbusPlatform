<?php

use App\Actions\Emissions\ImportIntegralizationHistoriesFromSpreadsheet;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\IntegralizationHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\IntegralizationHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
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

it('registers the spreadsheet import action on the integralization histories relation manager', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create();

    $this->actingAs($user);

    Livewire::test(IntegralizationHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['download_template', 'manage_template', 'import', 'create'])
        ->assertTableActionExists('download_template')
        ->assertTableActionHasLabel('download_template', 'Baixar Template')
        ->assertTableActionExists('manage_template')
        ->assertTableActionHasLabel('manage_template', 'Configurar Template')
        ->assertTableActionExists('import')
        ->assertTableActionHasLabel('import', 'Importar Planilha');
});

it('imports integralization history spreadsheets with quantity pu financial and investor fund headers', function () {
    $emission = Emission::factory()->create();
    $spreadsheetPath = storeIntegralizationHistorySpreadsheet([
        ['Data', 'Quantidade', 'PU', 'Financeiro', 'Fundo (Investidor)'],
        ['2025-06-04', '7500', '1000', '7500000', 'Head Invest'],
        ['05/06/2025', '1250', '1000,50', '1.250.625,00', 'Troupe FIM'],
    ]);

    $importedIntegralizationHistories = app(ImportIntegralizationHistoriesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $integralizationHistories = IntegralizationHistory::query()
        ->where('emission_id', $emission->id)
        ->orderBy('date')
        ->get();

    expect($importedIntegralizationHistories)->toBe(2)
        ->and($integralizationHistories)->toHaveCount(2)
        ->and($integralizationHistories[0]->date?->toDateString())->toBe('2025-06-04')
        ->and($integralizationHistories[0]->quantity)->toBe('7500.0000')
        ->and($integralizationHistories[0]->unit_value)->toBe('1000.000000')
        ->and($integralizationHistories[0]->financial_value)->toBe('7500000.00')
        ->and($integralizationHistories[0]->investor_fund)->toBe('Head Invest')
        ->and($integralizationHistories[1]->date?->toDateString())->toBe('2025-06-05')
        ->and($integralizationHistories[1]->quantity)->toBe('1250.0000')
        ->and($integralizationHistories[1]->unit_value)->toBe('1000.500000')
        ->and($integralizationHistories[1]->financial_value)->toBe('1250625.00')
        ->and($integralizationHistories[1]->investor_fund)->toBe('Troupe FIM');
});

it('updates an existing integralization history when reimporting the same date', function () {
    $emission = Emission::factory()->create();
    $integralizationHistory = $emission->integralizationHistories()->create([
        'date' => '2025-06-04',
        'quantity' => 1000,
        'unit_value' => 999.9,
        'financial_value' => 999900,
        'investor_fund' => 'Legacy Fund',
    ]);
    $spreadsheetPath = storeIntegralizationHistorySpreadsheet([
        ['Data', 'Quantidade', 'PU', 'Financeiro', 'Fundo (Investidor)'],
        ['04/06/2025', '7500', '1000,50', '', 'Head Invest Atualizado'],
    ]);

    $importedIntegralizationHistories = app(ImportIntegralizationHistoriesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $integralizationHistory->refresh();

    expect($importedIntegralizationHistories)->toBe(1)
        ->and($integralizationHistory->quantity)->toBe('7500.0000')
        ->and($integralizationHistory->unit_value)->toBe('1000.500000')
        ->and($integralizationHistory->financial_value)->toBe('7503750.00')
        ->and($integralizationHistory->investor_fund)->toBe('Head Invest Atualizado')
        ->and(IntegralizationHistory::query()->where('emission_id', $emission->id)->count())->toBe(1);
});

/**
 * @param  array<int, array<int, mixed>>  $rows
 */
function storeIntegralizationHistorySpreadsheet(array $rows): string
{
    Storage::disk('local')->makeDirectory('imports/testing');

    $relativePath = 'imports/testing/'.uniqid('integralizacoes-', true).'.xlsx';
    $absolutePath = Storage::disk('local')->path($relativePath);

    $writer = SimpleExcelWriter::create($absolutePath);
    $writer->noHeaderRow()->addRows($rows);
    $writer->close();

    return $relativePath;
}
