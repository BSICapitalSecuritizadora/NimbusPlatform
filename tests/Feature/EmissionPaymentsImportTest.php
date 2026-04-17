<?php

use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\Payment;
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

it('registers the spreadsheet import action on the payments relation manager', function () {
    $user = makeAdminUser();
    $emission = Emission::factory()->create();

    $this->actingAs($user);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['import', 'create'])
        ->assertTableActionExists('import')
        ->assertTableActionHasLabel('import', 'Importar Planilha');
});

it('imports spreadsheet payments into the interest field', function () {
    $emission = Emission::factory()->create();
    $spreadsheetPath = storePaymentSpreadsheet([
        ['Data', 'Pgto. Juros Total'],
        ['2021-08-20', '350670.709'],
        ['20/09/2021', '424760,578'],
    ]);

    $importedPayments = app(ImportPaymentsFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $payments = Payment::query()
        ->where('emission_id', $emission->id)
        ->orderBy('payment_date')
        ->get();

    expect($importedPayments)->toBe(2)
        ->and($payments)->toHaveCount(2)
        ->and($payments[0]->payment_date?->toDateString())->toBe('2021-08-20')
        ->and($payments[0]->interest_value)->toBe('350670.71')
        ->and($payments[0]->premium_value)->toBe('0.00')
        ->and($payments[0]->amortization_value)->toBe('0.00')
        ->and($payments[0]->extra_amortization_value)->toBe('0.00')
        ->and($payments[1]->payment_date?->toDateString())->toBe('2021-09-20')
        ->and($payments[1]->interest_value)->toBe('424760.58');
});

it('preserves non interest fields when reimporting an existing payment date', function () {
    $emission = Emission::factory()->create();
    $existingPayment = $emission->payments()->create([
        'payment_date' => '2021-08-20',
        'premium_value' => 1500,
        'interest_value' => 100,
        'amortization_value' => 2500,
        'extra_amortization_value' => 3500,
    ]);
    $spreadsheetPath = storePaymentSpreadsheet([
        ['Data', 'Pgto. Juros Total'],
        ['2021-08-20', '350670.709'],
    ]);

    $importedPayments = app(ImportPaymentsFromSpreadsheet::class)->handle(
        Storage::disk('local')->path($spreadsheetPath),
        $emission,
    );

    $existingPayment->refresh();

    expect($importedPayments)->toBe(1)
        ->and($existingPayment->interest_value)->toBe('350670.71')
        ->and($existingPayment->premium_value)->toBe('1500.00')
        ->and($existingPayment->amortization_value)->toBe('2500.00')
        ->and($existingPayment->extra_amortization_value)->toBe('3500.00')
        ->and(Payment::query()->where('emission_id', $emission->id)->count())->toBe(1);
});

/**
 * @param  array<int, array<int, string>>  $rows
 */
function storePaymentSpreadsheet(array $rows): string
{
    Storage::disk('local')->makeDirectory('imports/testing');

    $relativePath = 'imports/testing/'.uniqid('pagamentos-', true).'.xlsx';
    $absolutePath = Storage::disk('local')->path($relativePath);

    $writer = SimpleExcelWriter::create($absolutePath);
    $writer->noHeaderRow()->addRows($rows);
    $writer->close();

    return $relativePath;
}
