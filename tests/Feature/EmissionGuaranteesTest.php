<?php

use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\GuaranteesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\Guarantee;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the guarantees tab on the emission edit page', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $this->get(EmissionResource::getUrl('edit', ['record' => $emission], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Garantias');
});

it('renders the guarantees relation manager with the requested columns', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $olderGuarantee = Guarantee::factory()->create([
        'emission_id' => $emission->id,
        'validity_start_date' => '2026-01-10',
    ]);
    $latestGuarantee = Guarantee::factory()->create([
        'emission_id' => $emission->id,
        'validity_start_date' => '2026-03-15',
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableHeaderActionsExistInOrder(['create'])
        ->assertCanSeeTableRecords([$latestGuarantee, $olderGuarantee], inOrder: true)
        ->assertTableColumnExists('guarantee_type')
        ->assertTableColumnExists('minimum_value')
        ->assertTableColumnExists('validity_start_date')
        ->assertTableColumnExists('validity_end_date')
        ->assertTableColumnExists('description')
        ->assertTableColumnExists('evaluation_frequency');
});

it('creates a guarantee from the emission relation manager', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->callTableAction('create', data: [
            'guarantee_type' => 'Alienacao fiduciaria',
            'minimum_value' => 1500000.50,
            'validity_start_date' => '2026-01-15',
            'validity_end_date' => '2027-01-15',
            'description' => 'Garantia principal da operacao.',
            'evaluation_frequency' => 'Mensal',
        ])
        ->assertHasNoTableActionErrors();

    $guarantee = Guarantee::query()->sole();

    expect($guarantee->emission_id)->toBe($emission->id)
        ->and($guarantee->guarantee_type)->toBe('Alienacao fiduciaria')
        ->and($guarantee->minimum_value)->toBe('1500000.50')
        ->and($guarantee->validity_start_date?->toDateString())->toBe('2026-01-15')
        ->and($guarantee->validity_end_date?->toDateString())->toBe('2027-01-15')
        ->and($guarantee->description)->toBe('Garantia principal da operacao.')
        ->and($guarantee->evaluation_frequency)->toBe('Mensal');
});

it('updates and deletes guarantees from the emission relation manager', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $guarantee = Guarantee::factory()->create([
        'emission_id' => $emission->id,
        'guarantee_type' => 'Fianca',
        'minimum_value' => 500000,
        'validity_start_date' => '2026-02-01',
        'validity_end_date' => '2027-02-01',
        'description' => 'Cobertura inicial.',
        'evaluation_frequency' => 'Trimestral',
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->callTableAction('edit', $guarantee, data: [
            'guarantee_type' => 'Cessao fiduciaria',
            'minimum_value' => 750000.25,
            'validity_start_date' => '2026-02-10',
            'validity_end_date' => '2027-03-10',
            'description' => 'Cobertura revisada.',
            'evaluation_frequency' => 'Semestral',
        ])
        ->assertHasNoTableActionErrors();

    expect($guarantee->refresh()->guarantee_type)->toBe('Cessao fiduciaria')
        ->and($guarantee->minimum_value)->toBe('750000.25')
        ->and($guarantee->validity_start_date?->toDateString())->toBe('2026-02-10')
        ->and($guarantee->validity_end_date?->toDateString())->toBe('2027-03-10')
        ->and($guarantee->description)->toBe('Cobertura revisada.')
        ->and($guarantee->evaluation_frequency)->toBe('Semestral');

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->callTableAction('delete', $guarantee);

    expect(Guarantee::query()->count())->toBe(0);
});
