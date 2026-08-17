<?php

use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
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

function guaranteesRelationManager(Emission $emission)
{
    return Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ]);
}

it('shows the guarantees tab on the emission edit page', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $this->get(EmissionResource::getUrl('edit', ['record' => $emission], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Garantias');
});

it('renders the guarantees relation manager with the operational columns', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::RealEstateFiduciaryAlienation)
        ->create(['emission_id' => $emission->id]);

    guaranteesRelationManager($emission)
        ->assertCanSeeTableRecords([$guarantee])
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('identification')
        ->assertTableColumnExists('requirement_basis')
        ->assertTableColumnExists('contracted_value')
        ->assertTableColumnExists('current_value')
        ->assertTableColumnExists('eligible_value')
        ->assertTableColumnExists('coverage')
        ->assertTableColumnExists('validity_end_date')
        ->assertTableColumnExists('value_source')
        ->assertTableColumnExists('legal_status');
});

it('creates a guarantee with its contractual rule from the relation manager', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    guaranteesRelationManager($emission)
        ->callTableAction('create', data: [
            'type' => GuaranteeType::ReceivablesFiduciaryAssignment->value,
            'name' => 'Cessão Fiduciária de Recebíveis',
            'legal_status' => GuaranteeLegalStatus::Active->value,
            'requirement_basis' => GuaranteeRequirementBasis::Percentage->value,
            'requirement_percentage' => 1.2,
            'requirement_base' => 'outstanding_balance',
            'validity_start_date' => '2026-01-15',
            'validity_end_date' => '2027-01-15',
            'description' => 'Garantia principal da operacao.',
            'evaluation_frequency' => 'Mensal',
        ])
        ->assertHasNoTableActionErrors();

    $guarantee = Guarantee::query()->sole();

    expect($guarantee->emission_id)->toBe($emission->id)
        ->and($guarantee->type)->toBe(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->and($guarantee->name)->toBe('Cessão Fiduciária de Recebíveis')
        ->and($guarantee->legal_status)->toBe(GuaranteeLegalStatus::Active)
        ->and($guarantee->requirement_basis)->toBe(GuaranteeRequirementBasis::Percentage)
        ->and((float) $guarantee->requirement_percentage)->toBe(1.2)
        ->and($guarantee->validity_start_date?->toDateString())->toBe('2026-01-15')
        ->and($guarantee->validity_end_date?->toDateString())->toBe('2027-01-15')
        ->and($guarantee->description)->toBe('Garantia principal da operacao.')
        ->and($guarantee->evaluation_frequency)->toBe('Mensal');
});

it('updates and deletes guarantees from the emission relation manager', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Surety)
        ->create(['emission_id' => $emission->id]);

    guaranteesRelationManager($emission)
        ->callTableAction('edit', $guarantee, data: [
            'type' => GuaranteeType::ReceivablesFiduciaryAssignment->value,
            'name' => 'Cessao fiduciaria revisada',
            'legal_status' => GuaranteeLegalStatus::Active->value,
            'requirement_basis' => GuaranteeRequirementBasis::Absolute->value,
            'requirement_value' => '750.000,25',
            'validity_start_date' => '2026-02-10',
            'validity_end_date' => '2027-03-10',
            'description' => 'Cobertura revisada.',
            'evaluation_frequency' => 'Semestral',
        ])
        ->assertHasNoTableActionErrors();

    $guarantee->refresh();

    expect($guarantee->type)->toBe(GuaranteeType::ReceivablesFiduciaryAssignment)
        ->and($guarantee->name)->toBe('Cessao fiduciaria revisada')
        ->and((float) $guarantee->requirement_value)->toBe(750000.25)
        ->and($guarantee->validity_start_date?->toDateString())->toBe('2026-02-10')
        ->and($guarantee->validity_end_date?->toDateString())->toBe('2027-03-10')
        ->and($guarantee->description)->toBe('Cobertura revisada.')
        ->and($guarantee->evaluation_frequency)->toBe('Semestral');

    guaranteesRelationManager($emission)->callTableAction('delete', $guarantee);

    expect(Guarantee::query()->count())->toBe(0);
});

it('uses the latest sales board from each construction up to the competence', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $firstConstruction = Construction::factory()->create(['emission_id' => $emission->id]);
    $secondConstruction = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $firstConstruction)->create([
        'reference_month' => '2026-03-01',
        'stock_value' => 13523000,
    ]);
    SalesBoard::factory()->forEmissionAndConstruction($emission, $secondConstruction)->create([
        'reference_month' => '2024-03-01',
        'stock_value' => 2299300,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    guaranteesRelationManager($emission)->assertSee('R$ 15.822.300,00');
});

it('calculates outstanding balance from the latest pu in the month and cumulative integralized quantity', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create(['issued_quantity' => 100000]);

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
        'date' => '2026-05-15',
        'quantity' => 50,
        'unit_value' => 10,
        'financial_value' => 500,
        'investor_fund' => 'Fundo B',
    ]);

    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-05-10',
        'unit_value' => 20,
    ]);
    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-05-30',
        'unit_value' => 25,
    ]);

    // 150 cotas integralizadas × PU 25 (o último do mês) = R$ 3.750,00.
    guaranteesRelationManager($emission)->assertSee('R$ 3.750,00');
});

it('consolidates the competence and stores the snapshot from the relation manager', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create(['issued_quantity' => 100000]);

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

    guaranteesRelationManager($emission)
        ->callTableAction('update_competence')
        ->assertHasNoTableActionErrors();

    $snapshot = GuaranteeSnapshot::query()
        ->where('emission_id', $emission->id)
        ->whereDate('reference_month', '2026-05-01')
        ->sole();

    expect((float) $snapshot->outstanding_balance)->toBe(100000.0)
        ->and($snapshot->computed_at)->not->toBeNull();
});

it('surfaces the coverage status instead of a bare index', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $buildEmission = function (float $quotaValue): Emission {
        $emission = Emission::factory()->create(['issued_quantity' => 100000]);

        IntegralizationHistory::query()->create([
            'emission_id' => $emission->id,
            'date' => '2026-05-10',
            'quantity' => 1000,
            'unit_value' => 10,
            'financial_value' => 10000,
            'investor_fund' => 'Fundo A',
        ]);
        PuHistory::query()->create([
            'emission_id' => $emission->id,
            'date' => '2026-05-30',
            'unit_value' => 100,
        ]);

        $guarantee = Guarantee::factory()
            ->effectiveBetween()
            ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
            ->requiringPercentage(1.2)
            ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

        $guarantee->monthlyPositions()->create([
            'emission_id' => $emission->id,
            'reference_month' => '2026-05-01',
            'current_value' => $quotaValue,
            'value_source' => 'manual',
            'value_status' => 'manual',
        ]);

        return $emission;
    };

    // Saldo devedor: 1000 × 100 = R$ 100.000. Mínimo contratual: 120%.
    guaranteesRelationManager($buildEmission(131000))
        ->assertSee(GuaranteeCoverageStatus::Compliant->label());

    guaranteesRelationManager($buildEmission(123000))
        ->assertSee(GuaranteeCoverageStatus::NearLimit->label());

    guaranteesRelationManager($buildEmission(119000))
        ->assertSee(GuaranteeCoverageStatus::NonCompliant->label());
});

it('reports a pending competence instead of a breach when a manual value is missing', function () {
    Carbon::setTestNow('2026-05-10 09:00:00');

    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create(['issued_quantity' => 100000]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-05-10',
        'quantity' => 1000,
        'unit_value' => 10,
        'financial_value' => 10000,
        'investor_fund' => 'Fundo A',
    ]);
    PuHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-05-30',
        'unit_value' => 100,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->requiringPercentage(1.2)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    guaranteesRelationManager($emission)
        ->assertSee(GuaranteeCoverageStatus::PendingUpdate->label())
        ->assertDontSee(GuaranteeCoverageStatus::NonCompliant->label());
});
