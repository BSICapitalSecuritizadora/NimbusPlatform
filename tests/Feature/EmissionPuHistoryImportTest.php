<?php

use App\Actions\Emissions\ImportPuHistoriesFromSpreadsheet;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\PuHistory;
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

it('registers the spreadsheet import action on the pu histories relation manager', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create();

    $this->actingAs($user);

    Livewire::test(PuHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['download_template', 'manage_template', 'import', 'create'])
        ->assertTableActionHasLabel('download_template', 'Baixar Template')
        ->assertTableActionHasLabel('manage_template', 'Configurar Template')
        ->assertTableActionExists('import')
        ->assertTableActionHasLabel('import', 'Importar Planilha');
});

it('imports pu history spreadsheets with data and pu headers', function () {
    $emission = Emission::factory()->create();
    $spreadsheetPath = storePuHistorySpreadsheet([
        ['Data', 'PU'],
        ['2025-06-04', '1000'],
        ['2025-06-05', '1000,792729'],
        ['06/06/2025', '1001.586087'],
    ]);

    $importedPuHistories = app(ImportPuHistoriesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $puHistories = PuHistory::query()
        ->where('emission_id', $emission->id)
        ->orderBy('date')
        ->get();

    expect($importedPuHistories)->toBe(3)
        ->and($puHistories)->toHaveCount(3)
        ->and($puHistories[0]->date?->toDateString())->toBe('2025-06-04')
        ->and($puHistories[0]->unit_value)->toBe('1000.000000')
        ->and($puHistories[1]->date?->toDateString())->toBe('2025-06-05')
        ->and($puHistories[1]->unit_value)->toBe('1000.792729')
        ->and($puHistories[2]->date?->toDateString())->toBe('2025-06-06')
        ->and($puHistories[2]->unit_value)->toBe('1001.586087')
        ->and((float) $emission->fresh()->getRawOriginal('current_pu'))->toEqualWithDelta(1001.586087, 0.000001);
});

it('imports legacy pu history spreadsheets that store pu in the twelfth column', function () {
    $emission = Emission::factory()->create();
    $spreadsheetPath = storePuHistorySpreadsheet([
        array_replace(array_pad([], 12, ''), [0 => '2025-06-04', 11 => '1000.000001']),
        array_replace(array_pad([], 12, ''), [0 => '05/06/2025', 11 => '1001,123456']),
    ]);

    $importedPuHistories = app(ImportPuHistoriesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $puHistories = PuHistory::query()
        ->where('emission_id', $emission->id)
        ->orderBy('date')
        ->get();

    expect($importedPuHistories)->toBe(2)
        ->and($puHistories)->toHaveCount(2)
        ->and($puHistories[0]->unit_value)->toBe('1000.000001')
        ->and($puHistories[1]->unit_value)->toBe('1001.123456')
        ->and((float) $emission->fresh()->getRawOriginal('current_pu'))->toEqualWithDelta(1001.123456, 0.000001);
});

it('updates an existing pu history when reimporting the same date', function () {
    $emission = Emission::factory()->create();
    $existingPuHistory = $emission->puHistories()->create([
        'date' => '2025-06-04',
        'unit_value' => 999.111111,
    ]);
    $spreadsheetPath = storePuHistorySpreadsheet([
        ['Data', 'PU'],
        ['04/06/2025', '1000,792729'],
    ]);

    $importedPuHistories = app(ImportPuHistoriesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $existingPuHistory->refresh();

    expect($importedPuHistories)->toBe(1)
        ->and($existingPuHistory->unit_value)->toBe('1000.792729')
        ->and(PuHistory::query()->where('emission_id', $emission->id)->count())->toBe(1)
        ->and((float) $emission->fresh()->getRawOriginal('current_pu'))->toEqualWithDelta(1000.792729, 0.000001);
});

/**
 * @param  array<int, array<int, mixed>>  $rows
 */
function storePuHistorySpreadsheet(array $rows): string
{
    Storage::disk('local')->makeDirectory('imports/testing');

    $relativePath = 'imports/testing/'.uniqid('pu-historico-', true).'.xlsx';
    $absolutePath = Storage::disk('local')->path($relativePath);

    $writer = SimpleExcelWriter::create($absolutePath);
    $writer->noHeaderRow()->addRows($rows);
    $writer->close();

    return $relativePath;
}
