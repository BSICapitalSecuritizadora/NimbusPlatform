<?php

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PaymentsRelationManager;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\Operation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('identifies and links the persisted measurement without a legacy filename', function () {
    $operation = Operation::factory()->create();
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-04-01',
        'filename' => null,
        'storage_path' => null,
    ]);
    $payment = MeasurementPayment::factory()->create([
        'operation_id' => $operation->id,
        'measurement_id' => $measurement->id,
    ]);
    $label = "Medição #{$measurement->id} · 04/2026";
    $url = MeasurementResource::getUrl('view', ['record' => $measurement]);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$payment])
        ->assertTableColumnStateSet('measurement.reference_month', $label, $payment)
        ->assertTableColumnExists('measurement.reference_month', fn (TextColumn $column): bool => $column->getUrl() === $url, $payment)
        ->assertSee($label)
        ->assertSeeHtml('href="'.e($url).'"');

    expect($payment->fresh()->measurement_id)->toBe($measurement->id)
        ->and($payment->fresh()->measurement?->getKey())->toBe($measurement->id)
        ->and($measurement->fresh()->filename)->toBeNull();
});

it('presents missing methods without changing the stored value', function (?string $method, string $label) {
    $operation = Operation::factory()->create();
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-04-01',
        'filename' => null,
        'storage_path' => null,
    ]);
    $payment = MeasurementPayment::factory()->create([
        'operation_id' => $operation->id,
        'measurement_id' => $measurement->id,
        'method' => $method,
    ]);

    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $operation,
        'pageClass' => ViewOperation::class,
    ])
        ->assertSuccessful()
        ->assertSee($label);

    expect($payment->fresh()->method)->toBe($method);
})->with([
    'null' => [null, 'Não informado'],
    'empty' => ['', 'Não informado'],
    'provided' => ['PIX', 'PIX'],
]);
