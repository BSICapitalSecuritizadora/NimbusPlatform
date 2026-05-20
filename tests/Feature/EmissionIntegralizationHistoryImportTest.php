<?php

use App\Actions\Emissions\ImportIntegralizationHistoriesFromSpreadsheet;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\IntegralizationHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\IntegralizationHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
    $emission = Emission::factory()->create([
        'issued_quantity' => 10000,
    ]);

    $this->actingAs($user);

    Livewire::test(IntegralizationHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['download_template', 'manage_template', 'import', 'create'])
        ->assertTableActionExists('download_template')
        ->assertTableActionHasLabel('download_template', 'Download do Template')
        ->assertTableActionExists('manage_template')
        ->assertTableActionHasLabel('manage_template', 'Configurar Template')
        ->assertTableActionExists('import')
        ->assertTableActionHasLabel('import', 'Importar Dados');
});

it('accepts pt-br financial masks when creating an integralization history manually', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create([
        'issued_quantity' => 10000,
    ]);

    $this->actingAs($user);

    Livewire::test(IntegralizationHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->mountTableAction('create')
        ->assertFormFieldExists('quantity', function (TextInput $field): bool {
            return $field->getInputMode() === 'numeric'
                && str_contains((string) $field->getMask(), '$money($input, \',\', \'.\', 0)');
        })
        ->assertFormFieldExists('unit_value', function (TextInput $field): bool {
            return $field->getInputMode() === 'decimal'
                && str_contains((string) $field->getMask(), '$money($input, \',\', \'.\', 8)');
        })
        ->assertFormFieldExists('financial_value', function (TextInput $field): bool {
            return $field->isReadOnly()
                && $field->getInputMode() === 'decimal'
                && str_contains((string) $field->getMask(), '$money($input, \',\', \'.\', 2)');
        })
        ->setTableActionData([
            'date' => '2026-06-04',
            'quantity' => '1.000',
            'unit_value' => '1.000,98765432',
            'investor_fund' => 'Head Invest',
        ])
        ->assertTableActionDataSet([
            'quantity' => '1.000',
        ])
        ->assertTableActionDataSet([
            'financial_value' => '1.000.987,65',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $integralizationHistory = IntegralizationHistory::query()->sole();

    expect($integralizationHistory->emission_id)->toBe($emission->id)
        ->and($integralizationHistory->date?->toDateString())->toBe('2026-06-04')
        ->and($integralizationHistory->quantity)->toBe('1000.0000')
        ->and($integralizationHistory->unit_value)->toBe('1000.98765432')
        ->and($integralizationHistory->financial_value)->toBe('1000987.65')
        ->and($integralizationHistory->investor_fund)->toBe('Head Invest')
        ->and($emission->refresh()->integralized_quantity)->toBe(1000);
});

it('imports integralization history spreadsheets with quantity pu financial and investor fund headers', function () {
    $emission = Emission::factory()->create([
        'issued_quantity' => 10000,
    ]);
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
        ->and($integralizationHistories[0]->unit_value)->toBe('1000.00000000')
        ->and($integralizationHistories[0]->financial_value)->toBe('7500000.00')
        ->and($integralizationHistories[0]->investor_fund)->toBe('Head Invest')
        ->and($integralizationHistories[1]->date?->toDateString())->toBe('2025-06-05')
        ->and($integralizationHistories[1]->quantity)->toBe('1250.0000')
        ->and($integralizationHistories[1]->unit_value)->toBe('1000.50000000')
        ->and($integralizationHistories[1]->financial_value)->toBe('1250625.00')
        ->and($integralizationHistories[1]->investor_fund)->toBe('Troupe FIM')
        ->and($emission->refresh()->integralized_quantity)->toBe(8750);
});

it('updates an existing integralization history when reimporting the same date', function () {
    $emission = Emission::factory()->create([
        'issued_quantity' => 10000,
    ]);
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
        ->and($integralizationHistory->unit_value)->toBe('1000.50000000')
        ->and($integralizationHistory->financial_value)->toBe('7503750.00')
        ->and($integralizationHistory->investor_fund)->toBe('Head Invest Atualizado')
        ->and(IntegralizationHistory::query()->where('emission_id', $emission->id)->count())->toBe(1)
        ->and($emission->refresh()->integralized_quantity)->toBe(7500);
});

it('syncs the emission integralized quantity when integralizations change', function () {
    $emission = Emission::factory()->create([
        'issued_quantity' => 10000,
        'integralized_quantity' => 999,
    ]);

    $firstIntegralization = $emission->integralizationHistories()->create([
        'date' => '2025-06-04',
        'quantity' => 1500,
    ]);

    $secondIntegralization = $emission->integralizationHistories()->create([
        'date' => '2025-06-05',
        'quantity' => 6000,
    ]);

    expect($emission->refresh()->integralized_quantity)->toBe(7500);

    $secondIntegralization->update([
        'quantity' => 7000,
    ]);

    expect($emission->refresh()->integralized_quantity)->toBe(8500);

    $firstIntegralization->delete();

    expect($emission->refresh()->integralized_quantity)->toBe(7000);
});

it('prevents integralizations from exceeding the issued quantity', function () {
    $emission = Emission::factory()->create([
        'issued_quantity' => 7500,
        'integralized_quantity' => 0,
    ]);

    $emission->integralizationHistories()->create([
        'date' => '2025-06-04',
        'quantity' => 7000,
    ]);

    expect(fn () => $emission->integralizationHistories()->create([
        'date' => '2025-06-05',
        'quantity' => 600,
    ]))
        ->toThrow(ValidationException::class, 'Restam 500 disponíveis para integralização.');

    expect($emission->refresh()->integralized_quantity)->toBe(7000)
        ->and(IntegralizationHistory::query()->where('emission_id', $emission->id)->count())->toBe(1);
});

it('shows the issued quantity validation message on the manual create modal', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create([
        'issued_quantity' => 7500,
        'integralized_quantity' => 0,
    ]);

    $emission->integralizationHistories()->create([
        'date' => '2025-06-04',
        'quantity' => 7000,
        'unit_value' => 1000,
        'financial_value' => 7000000,
        'investor_fund' => 'Head Invest',
    ]);

    $this->actingAs($user);

    Livewire::test(IntegralizationHistoriesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->mountTableAction('create')
        ->setTableActionData([
            'date' => '2026-06-04',
            'quantity' => 600,
            'unit_value' => '1.000,00000000',
            'financial_value' => '600.000,00',
            'investor_fund' => 'Teste',
        ])
        ->callMountedTableAction();

    Notification::assertNotified(
        Notification::make()
            ->title('Integralização não realizada')
            ->body('A quantidade informada excede a Quantidade Emitida. Restam 500 disponíveis para integralização.')
            ->danger()
            ->persistent(),
    );

    expect($emission->refresh()->integralized_quantity)->toBe(7000)
        ->and(IntegralizationHistory::query()->where('emission_id', $emission->id)->count())->toBe(1);
});

it('rolls back spreadsheet imports when the total quantity exceeds the issued quantity', function () {
    $emission = Emission::factory()->create([
        'issued_quantity' => 7500,
        'integralized_quantity' => 0,
    ]);
    $spreadsheetPath = storeIntegralizationHistorySpreadsheet([
        ['Data', 'Quantidade', 'PU', 'Financeiro', 'Fundo (Investidor)'],
        ['2025-06-04', '5000', '1000', '5000000', 'Head Invest'],
        ['2025-06-05', '3000', '1000', '3000000', 'Troupe FIM'],
    ]);

    expect(fn () => app(ImportIntegralizationHistoriesFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    ))
        ->toThrow(ValidationException::class, 'Restam 2.500 disponíveis para integralização.');

    expect(IntegralizationHistory::query()->where('emission_id', $emission->id)->count())->toBe(0)
        ->and($emission->refresh()->integralized_quantity)->toBe(0);
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
