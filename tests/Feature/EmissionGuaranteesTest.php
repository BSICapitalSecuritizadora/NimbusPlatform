<?php

use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\GuaranteesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\SalesBoard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
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

it('uses the latest sales board from each construction up to the guarantee month', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    GuaranteeSnapshot::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-04-01',
        'quota_value' => 100000,
        'outstanding_balance' => 200000,
    ]);

    $firstConstruction = Construction::factory()->create([
        'emission_id' => $emission->id,
    ]);
    $secondConstruction = Construction::factory()->create([
        'emission_id' => $emission->id,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $firstConstruction)->create([
        'reference_month' => '2026-03-01',
        'stock_value' => 13523000,
    ]);
    SalesBoard::factory()->forEmissionAndConstruction($emission, $secondConstruction)->create([
        'reference_month' => '2024-03-01',
        'stock_value' => 2299300,
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSee('R$ 15.822.300,00');
});

it('calculates outstanding balance from the latest pu in the month and cumulative integralized quantity', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    GuaranteeSnapshot::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-04-01',
        'quota_value' => 100000,
        'outstanding_balance' => 0,
    ]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-03-10',
        'quantity' => 100,
        'unit_value' => 10,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);
    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-04-15',
        'quantity' => 50,
        'unit_value' => 10,
        'financial_value' => 500,
        'investor_fund' => 'Fundo B',
    ]);

    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-04-10',
        'unit_value' => 20,
    ]);
    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-04-30',
        'unit_value' => 25,
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSee('R$ 3.750,00')
        ->assertSee('2.667%');
});

it('stores monthly guarantee indicators without requiring a manual outstanding balance', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-05-05',
        'quantity' => 1000,
        'unit_value' => 10,
        'financial_value' => 10000,
        'investor_fund' => 'Fundo A',
    ]);

    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-05-31',
        'unit_value' => 100,
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->callTableAction('update_monthly_snapshot', data: [
            'reference_month' => '05/2026',
            'quota_value' => '125.000,00',
        ])
        ->assertHasNoTableActionErrors();

    $snapshot = GuaranteeSnapshot::query()
        ->where('emission_id', $emission->id)
        ->whereDate('reference_month', '2026-05-01')
        ->sole();

    expect($snapshot->quota_value)->toBe('125000.00')
        ->and($snapshot->outstanding_balance)->toBe('100000.00');
});

it('colors the coverage index card according to the configured thresholds', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $greenEmission = Emission::factory()->create();
    GuaranteeSnapshot::factory()->create([
        'emission_id' => $greenEmission->id,
        'reference_month' => '2026-04-01',
        'quota_value' => 131000,
        'outstanding_balance' => 0,
    ]);
    IntegralizationHistory::query()->create([
        'emission_id' => $greenEmission->id,
        'date' => '2026-04-10',
        'quantity' => 1000,
        'unit_value' => 10,
        'financial_value' => 10000,
        'investor_fund' => 'Fundo A',
    ]);
    PuHistory::query()->create([
        'emission_id' => $greenEmission->id,
        'date' => '2026-04-30',
        'unit_value' => 100,
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $greenEmission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSeeHtml('border-emerald-400/20 bg-emerald-500/10')
        ->assertSee('131%');

    $yellowEmission = Emission::factory()->create();
    GuaranteeSnapshot::factory()->create([
        'emission_id' => $yellowEmission->id,
        'reference_month' => '2026-04-01',
        'quota_value' => 125000,
        'outstanding_balance' => 0,
    ]);
    IntegralizationHistory::query()->create([
        'emission_id' => $yellowEmission->id,
        'date' => '2026-04-10',
        'quantity' => 1000,
        'unit_value' => 10,
        'financial_value' => 10000,
        'investor_fund' => 'Fundo A',
    ]);
    PuHistory::query()->create([
        'emission_id' => $yellowEmission->id,
        'date' => '2026-04-30',
        'unit_value' => 100,
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $yellowEmission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSeeHtml('border-amber-400/20 bg-amber-500/10')
        ->assertSee('125%');

    $redEmission = Emission::factory()->create();
    GuaranteeSnapshot::factory()->create([
        'emission_id' => $redEmission->id,
        'reference_month' => '2026-04-01',
        'quota_value' => 119000,
        'outstanding_balance' => 0,
    ]);
    IntegralizationHistory::query()->create([
        'emission_id' => $redEmission->id,
        'date' => '2026-04-10',
        'quantity' => 1000,
        'unit_value' => 10,
        'financial_value' => 10000,
        'investor_fund' => 'Fundo A',
    ]);
    PuHistory::query()->create([
        'emission_id' => $redEmission->id,
        'date' => '2026-04-30',
        'unit_value' => 100,
    ]);

    Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $redEmission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSeeHtml('border-rose-400/20 bg-rose-500/10')
        ->assertSee('119%');
});
