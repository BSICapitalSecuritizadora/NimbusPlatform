<?php

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Filament\Resources\IndexProjectionSeriesResources\Pages\ListIndexProjectionSeries;
use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Models\IndexProjectionSeries;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the index rates list page for an admin with import actions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListIndexRates::class)
        ->assertOk()
        ->assertActionVisible('importPublished')
        ->assertActionVisible('importProjectedSeries');
});

it('approves a projected series through the Filament table action enforcing maker-checker', function () {
    $importer = User::factory()->create();
    $checker = User::factory()->create();
    $checker->assignRole('admin');
    $this->actingAs($checker);

    $series = IndexProjectionSeries::factory()->create([
        'status' => IndexProjectionSeriesStatus::Imported->value,
        'imported_by' => $importer->id,
    ]);

    Livewire::test(ListIndexProjectionSeries::class)
        ->callTableAction('approve', $series);

    expect($series->fresh()->status)->toBe(IndexProjectionSeriesStatus::Approved)
        ->and($series->fresh()->approved_by)->toBe($checker->id);
});
