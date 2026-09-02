<?php

use App\Enums\ContractStatus;
use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Filament\Resources\Negotiations\Pages\ListNegotiations;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\Negotiation;
use App\Models\User;
use App\Services\Reports\ContractNegotiationAggregates;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function derivedAdmin(): User
{
    $u = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $u->assignRole('admin');

    return $u;
}

function contractsEmissionWithConstruction(string $emissionName = 'CRI Contracts', string $development = 'Residencial Aurora'): array
{
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => $emissionName]);
    $construction = Construction::factory()->for($emission)->create(['development_name' => $development]);

    return [$emission, $construction];
}

function legacyEmissionWithConstruction(string $emissionName = 'CRI Legacy', string $development = 'Residencial Legacy'): array
{
    $emission = Emission::factory()->withLegacyNegotiations()->create(['name' => $emissionName]);
    $construction = Construction::factory()->for($emission)->create(['development_name' => $development]);

    return [$emission, $construction];
}

// 1 - sale appears automatically via contract-derived aggregation
it('1 sale appears automatically via aggregated query', function () {
    [$emission, $construction] = contractsEmissionWithConstruction('Emissao 1', 'Emp 1');
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '101']);

    Contract::factory()->forUnit($unit)->create(['code' => 'SALE-001', 'sale_date' => '2026-07-15']);

    $aggregates = app(ContractNegotiationAggregates::class);
    $rows = $aggregates->aggregatedQuery(['emission_id' => $emission->id])->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->sales)->toBe(1)
        ->and($rows[0]->cancellations)->toBe(0)
        ->and($rows[0]->reference_month)->toBe('2026-07-01');
});

// 2 - no manual Negotiation required
it('2 no manual Negotiation record is required for contract-derived listing', function () {
    [$emission, $construction] = contractsEmissionWithConstruction('Emissao 2', 'Emp 2');
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    // No Negotiation row at all
    expect(Negotiation::query()->count())->toBe(0);

    Contract::factory()->forUnit($unit)->create(['code' => 'AUTO-001', 'sale_date' => '2026-06-10']);

    $rows = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emission->id])->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->sales)->toBe(1);
    // ensure negotiation table is still empty — sale is derived
    expect(Negotiation::query()->count())->toBe(0);
});

// 3 - sale grouped to correct month
it('3 sale grouped to correct month (YYYY-MM-01)', function () {
    [$emission, $construction] = contractsEmissionWithConstruction();
    $unit1 = ConstructionUnit::factory()->forConstruction($construction)->create();
    $unit2 = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit1)->create(['code' => 'JUL-001', 'sale_date' => '2026-07-01']);
    Contract::factory()->forUnit($unit2)->create(['code' => 'JUL-002', 'sale_date' => '2026-07-31']);

    $rows = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emission->id])->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->reference_month)->toBe('2026-07-01')
        ->and((int) $rows[0]->sales)->toBe(2);

    // Filter by reference_month using m/Y format
    $filtered = app(ContractNegotiationAggregates::class)->aggregatedQuery([
        'emission_id' => $emission->id,
        'reference_month' => '07/2026',
    ])->get();
    expect($filtered)->toHaveCount(1);

    $outside = app(ContractNegotiationAggregates::class)->aggregatedQuery([
        'emission_id' => $emission->id,
        'reference_month' => '06/2026',
    ])->get();
    expect($outside)->toHaveCount(0);
});

// 4 - cancellation correct month
it('4 cancellation grouped to correct month', function () {
    [$emission, $construction] = contractsEmissionWithConstruction();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'CANC-001',
        'sale_date' => '2026-03-10',
        'cancellation_date' => '2026-07-20',
        'status' => ContractStatus::Cancelled,
    ]);

    $rows = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emission->id])->get();

    // Two months: March sale, July cancellation
    expect($rows)->toHaveCount(2);
    $july = collect($rows)->firstWhere('reference_month', '2026-07-01');
    expect($july)->not->toBeNull()
        ->and((int) $july->cancellations)->toBe(1);

    $march = collect($rows)->firstWhere('reference_month', '2026-03-01');
    expect($march)->not->toBeNull()
        ->and((int) $march->sales)->toBe(1);
});

// 5 - same contract generates two months (sale + cancellation)
it('5 same contract generates two distinct monthly rows', function () {
    [$emission, $construction] = contractsEmissionWithConstruction();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'MIX-001',
        'sale_date' => '2026-04-15',
        'cancellation_date' => '2026-08-10',
        'status' => ContractStatus::Cancelled,
    ]);

    $rows = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emission->id])->orderBy('reference_month')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->reference_month)->toBe('2026-04-01')
        ->and((int) $rows[0]->sales)->toBe(1)
        ->and((int) $rows[0]->cancellations)->toBe(0)
        ->and($rows[1]->reference_month)->toBe('2026-08-01')
        ->and((int) $rows[1]->sales)->toBe(0)
        ->and((int) $rows[1]->cancellations)->toBe(1);
});

// 6 - multiple contracts aggregate in same month
it('6 multiple contracts aggregate into summed sales and cancellations', function () {
    [$emission, $construction] = contractsEmissionWithConstruction();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(3)->create();

    Contract::factory()->forUnit($units[0])->create(['code' => 'AGG-1', 'sale_date' => '2026-07-05']);
    Contract::factory()->forUnit($units[1])->create(['code' => 'AGG-2', 'sale_date' => '2026-07-10', 'cancellation_date' => '2026-07-20', 'status' => ContractStatus::Cancelled]);
    $extra = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($extra)->create(['code' => 'AGG-3', 'sale_date' => '2026-05-01', 'cancellation_date' => '2026-07-15', 'status' => ContractStatus::Cancelled]);

    $rows = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emission->id, 'reference_month' => '2026-07-01'])->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->sales)->toBe(2) // AGG-1 + AGG-2 sales
        ->and((int) $rows[0]->cancellations)->toBe(2); // AGG-2 + AGG-3 cancellations
});

// 7 - different developments separated (construction_id grouping)
it('7 different developments are separated by construction_id', function () {
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'Emissao Multi Dev']);
    $constructionA = Construction::factory()->for($emission)->create(['development_name' => 'Dev A']);
    $constructionB = Construction::factory()->for($emission)->create(['development_name' => 'Dev B']);
    $unitA = ConstructionUnit::factory()->forConstruction($constructionA)->create();
    $unitB = ConstructionUnit::factory()->forConstruction($constructionB)->create();

    Contract::factory()->forUnit($unitA)->create(['code' => 'DEV-A-001', 'sale_date' => '2026-07-10']);
    Contract::factory()->forUnit($unitB)->create(['code' => 'DEV-B-001', 'sale_date' => '2026-07-12']);

    $rows = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emission->id])->get();

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('construction_id')->sort()->values()->all())
        ->toBe(collect([$constructionA->id, $constructionB->id])->sort()->values()->all());

    // Filtering by construction narrows correctly
    $onlyA = app(ContractNegotiationAggregates::class)->aggregatedQuery([
        'emission_id' => $emission->id,
        'construction_id' => $constructionA->id,
    ])->get();
    expect($onlyA)->toHaveCount(1)
        ->and((int) $onlyA[0]->construction_id)->toBe($constructionA->id);
});

// 8 - different emissions separated (emission_id grouping)
it('8 different emissions are strictly separated', function () {
    $emissionA = Emission::factory()->withContractNegotiations()->create(['name' => 'Emissao A']);
    $emissionB = Emission::factory()->withContractNegotiations()->create(['name' => 'Emissao B']);
    $constructionA = Construction::factory()->for($emissionA)->create();
    $unitA = ConstructionUnit::factory()->forConstruction($constructionA)->create();
    $constructionB = Construction::factory()->for($emissionB)->create();
    $unitB = ConstructionUnit::factory()->forConstruction($constructionB)->create();

    Contract::factory()->forUnit($unitA)->create(['code' => 'EM-A-001', 'sale_date' => '2026-07-10']);
    Contract::factory()->forUnit($unitB)->create(['code' => 'EM-B-001', 'sale_date' => '2026-07-10']);

    $rowsA = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emissionA->id])->get();
    $rowsB = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emissionB->id])->get();

    expect($rowsA)->toHaveCount(1)
        ->and($rowsA[0]->emission_id)->toBe($emissionA->id)
        ->and((int) $rowsA[0]->sales)->toBe(1);
    expect($rowsB)->toHaveCount(1)
        ->and($rowsB[0]->emission_id)->toBe($emissionB->id);
    // Ensure A does not leak B
    expect(collect($rowsA)->pluck('emission_id')->contains($emissionB->id))->toBeFalse();
});

// 9 - code / block / unit in details drill-down
it('9 detail drill-down exposes code block unit display date and ordering', function () {
    [$emission, $construction] = contractsEmissionWithConstruction('Emissao Detail', 'Torre A');
    $unit101 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '101']);
    $unit102 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '102']);
    $unit201 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'B', 'unit' => '201']);

    Contract::factory()->forUnit($unit102)->create(['code' => 'CODE-102', 'sale_date' => '2026-07-20']);
    Contract::factory()->forUnit($unit101)->create(['code' => 'CODE-101', 'sale_date' => '2026-07-05']);
    Contract::factory()->forUnit($unit201)->create(['code' => 'CODE-201', 'sale_date' => '2026-06-01', 'cancellation_date' => '2026-07-15', 'status' => ContractStatus::Cancelled]);

    $service = app(ContractNegotiationAggregates::class);

    $vendas = $service->detailEvents($emission->id, $construction->id, '2026-07-01', 'Venda');
    expect($vendas)->toHaveCount(2)
        ->and($vendas[0]['code'])->toBe('CODE-101')
        ->and($vendas[1]['code'])->toBe('CODE-102')
        ->and($vendas[0]['block'])->toBe('A')
        ->and($vendas[0]['unit'])->toBe('101')
        ->and($vendas[0]['display'])->toBe('Bloco A — Unidade 101')
        ->and($vendas[0]['date_formatted'])->toBe('05/07/2026');

    $distratos = $service->detailEvents($emission->id, $construction->id, '2026-07-01', 'Distrato');
    expect($distratos)->toHaveCount(1)
        ->and($distratos[0]['code'])->toBe('CODE-201')
        ->and($distratos[0]['block'])->toBe('B')
        ->and($distratos[0]['unit'])->toBe('201')
        ->and($distratos[0]['date_formatted'])->toBe('15/07/2026');
});

// 10 - contracts mode hides create action
it('10 contracts mode hides create negotiation action', function () {
    $this->actingAs(derivedAdmin());

    // When all emissions are contracts-mode, header create must be hidden even without filter
    [$emission] = contractsEmissionWithConstruction('Only Contracts', 'Emp Contracts');

    // Filament List page should not show create
    Livewire::test(ListNegotiations::class)
        ->assertActionHidden('create');

    // Resource canCreate must be false when no legacy exists
    expect(NegotiationResource::canCreate())->toBeFalse();

    // Mixed scenario: add a legacy emission so filters exist, then filter to contracts emission
    $legacyEmission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'Legacy Keep']);
    $legacyConstruction = Construction::factory()->for($legacyEmission)->create();
    Negotiation::factory()->forEmissionAndConstruction($legacyEmission, $legacyConstruction)->create([
        'reference_month' => '2026-05-01', 'sales' => 1, 'cancellations' => 0,
    ]);

    Livewire::test(ListNegotiations::class)
        ->filterTable('emission_id', $emission->id)
        ->assertActionHidden('create');
});

// 11 - empty state messaging differs
it('11 empty state messaging is contracts-aware', function () {
    $this->actingAs(derivedAdmin());

    // Legacy empty state (no negotiations at all)
    Livewire::test(ListNegotiations::class)
        ->assertSee('Nenhuma negociação cadastrada')
        ->assertSee('Cadastrar negociação');

    // Contracts mode empty state requires filters to exist (legacy negotiation seeds filters)
    $legacyEmission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'Legacy Seed']);
    $legacyConstruction = Construction::factory()->for($legacyEmission)->create();
    Negotiation::factory()->forEmissionAndConstruction($legacyEmission, $legacyConstruction)->create([
        'reference_month' => '2026-05-01', 'sales' => 1, 'cancellations' => 0,
    ]);

    $contractsEmission = Emission::factory()->withContractNegotiations()->create(['name' => 'Empty Contracts Mode']);
    Construction::factory()->for($contractsEmission)->create();

    // When filtered to contracts emission with no derived rows, contracts-aware empty messaging appears
    Livewire::test(ListNegotiations::class)
        ->filterTable('emission_id', $contractsEmission->id)
        ->assertSee('Nenhuma negociação derivada de contratos corresponde aos filtros selecionados');

    // Derived query itself is empty correctly
    $aggregatesEmpty = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $contractsEmission->id])->get();
    expect($aggregatesEmpty)->toHaveCount(0);

    // Filtered with active filters shows "Limpar filtros" action
    Livewire::test(ListNegotiations::class)
        ->filterTable('emission_id', $contractsEmission->id)
        ->assertSee('Limpar filtros');
});

// 12 - legacy mode preserved
it('12 legacy mode preserves manual negotiations and does not mix with contracts', function () {
    $this->actingAs(derivedAdmin());

    [$legacyEmission, $legacyConstruction] = legacyEmissionWithConstruction('CRI Legacy Keep', 'Residencial Legacy');
    Negotiation::factory()->forEmissionAndConstruction($legacyEmission, $legacyConstruction)->create([
        'reference_month' => '2026-05-01',
        'sales' => 7,
        'cancellations' => 2,
    ]);

    // List page should still show create and list legacy rows
    Livewire::test(ListNegotiations::class)
        ->assertActionExists('create')
        ->assertSee($legacyEmission->name);

    // Manual negotiation persists
    expect(Negotiation::query()->where('emission_id', $legacyEmission->id)->count())->toBe(1);
    expect(NegotiationResource::canCreate())->toBeTrue();

    // Contract for same emission should NOT be used while legacy — report uses legacy source
    $unit = ConstructionUnit::factory()->forConstruction($legacyConstruction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'LEGACY-IGNORED', 'sale_date' => '2026-05-15']);

    $report = app(EmissionMonthlyReportService::class)->build($legacyEmission, CarbonImmutable::parse('2026-05-01'));
    expect($report['negotiations']['source'])->toBe('legacy')
        ->and($report['negotiations']['has_data'])->toBeTrue();
});

// 13 - no PDF required for negotiations listing
it('13 negotiations listing does not require or expose a PDF generation', function () {
    $this->actingAs(derivedAdmin());
    [$emission, $construction] = contractsEmissionWithConstruction();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'NO-PDF-001', 'sale_date' => '2026-07-15']);

    // Listing page should not expose a PDF header action
    Livewire::test(ListNegotiations::class)
        ->assertActionDoesNotExist('pdf')
        ->assertActionDoesNotExist('export')
        ->assertActionDoesNotExist('downloadPdf');

    // Contract aggregates exist without any PDF view involvement
    $rows = app(ContractNegotiationAggregates::class)->aggregatedQuery(['emission_id' => $emission->id])->get();
    expect($rows)->toHaveCount(1);

    // Monthly report PDF route still exists but is independent — listing does not depend on it
    expect(route('admin.emissions.monthly-report.pdf', ['emission' => $emission->id, 'reference_month' => '2026-07'], false))->toContain('relatorio-mensal');
});

// 14 - monthly report unchanged after contract migration
it('14 monthly report remains functional and consistent for both sources', function () {
    // Legacy report shape
    [$legacyEmission, $legacyConstruction] = legacyEmissionWithConstruction('Legacy Report', 'Dev Legacy');
    Negotiation::factory()->forEmissionAndConstruction($legacyEmission, $legacyConstruction)->create([
        'reference_month' => '2026-07-01', 'sales' => 5, 'cancellations' => 1,
    ]);
    $legacyData = app(EmissionMonthlyReportService::class)->build($legacyEmission, CarbonImmutable::parse('2026-07-01'));
    expect($legacyData['negotiations']['source'])->toBe('legacy')
        ->and($legacyData['negotiations']['has_data'])->toBeTrue();

    // Contracts report shape — same builder method, derived totals
    [$contractsEmission, $contractsConstruction] = contractsEmissionWithConstruction('Contracts Report', 'Dev Contracts');
    $unit = ConstructionUnit::factory()->forConstruction($contractsConstruction)->create(['block' => 'Torre A', 'unit' => '101']);
    Contract::factory()->forUnit($unit)->create(['code' => 'RPT-001', 'sale_date' => '2026-07-10']);
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($contractsConstruction)->create(['block' => 'B', 'unit' => '202']))->create([
        'code' => 'RPT-002', 'sale_date' => '2026-04-01', 'cancellation_date' => '2026-07-20', 'status' => ContractStatus::Cancelled,
    ]);
    $contractsData = app(EmissionMonthlyReportService::class)->build($contractsEmission, CarbonImmutable::parse('2026-07-01'));
    expect($contractsData['negotiations']['source'])->toBe('contracts')
        ->and($contractsData['negotiations']['has_data'])->toBeTrue()
        ->and($contractsData['negotiations']['sales_count'])->toBe(1)
        ->and($contractsData['negotiations']['cancellations_count'])->toBe(1)
        ->and($contractsData['negotiations']['vendas'][0]['display'])->toBe('Bloco Torre A — Unidade 101');

    // History remains correctly bounded for contracts emission
    $history = $contractsData['negotiations_history'];
    expect($history)->toHaveKey('rows')
        ->and($history['rows'])->toHaveCount(6);
});
