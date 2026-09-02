<?php

use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Filament\Resources\Negotiations\Pages\ListNegotiations;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\Negotiation;
use App\Models\User;
use App\Services\Reports\ContractNegotiationAggregates;
use App\Services\Reports\NegotiationGlobalProjection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function globalAdmin(): User
{
    $u = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $u->assignRole('admin');

    return $u;
}

// Regression: global page without filter must show contract-derived rows even though negotiations table is empty
it('regression: global listing shows contract-derived rows without selecting emission filter', function () {
    $this->actingAs(globalAdmin());

    expect(Negotiation::query()->count())->toBe(0);

    $emissionContracts = Emission::factory()->withContractNegotiations()->create(['name' => 'CRI Alto Bellevue Test']);
    $construction = Construction::factory()->for($emissionContracts)->create(['development_name' => 'Alto Bellevue']);
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(3)->create();
    Contract::factory()->forUnit($units[0])->create(['code' => '10070766', 'sale_date' => '2026-07-06']);
    Contract::factory()->forUnit($units[1])->create(['code' => '10070784', 'sale_date' => '2026-07-24']);
    Contract::factory()->forUnit($units[2])->create(['code' => '10070783', 'sale_date' => '2026-07-27']);

    // Another emission remains legacy (mixed sources)
    $legacyEmission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'CRI Legacy Mixed']);
    Construction::factory()->for($legacyEmission)->create(['development_name' => 'Legado']);

    // Global listing without filter must contain the derived aggregate (July 2026, sales=3)
    $projection = app(NegotiationGlobalProjection::class);
    $rows = $projection->unionQuery()->get();
    expect($rows->where('emission_id', $emissionContracts->id)->where('reference_month', '2026-07-01')->first())->not->toBeNull()
        ->and((int) $rows->where('emission_id', $emissionContracts->id)->where('reference_month', '2026-07-01')->first()->sales)->toBe(3);

    // Via Eloquent projection used by table
    $eloquentRows = $projection->eloquentQuery([])->get();
    $found = $eloquentRows->firstWhere(fn ($r) => (int) $r->emission_id === $emissionContracts->id && $r->reference_month->toDateString() === '2026-07-01');
    expect($found)->not->toBeNull()
        ->and((int) $found->sales)->toBe(3)
        ->and((int) $found->cancellations)->toBe(0);

    // Filament page must NOT show empty legacy state
    Livewire::test(ListNegotiations::class)
        ->assertDontSee('Nenhuma negociação cadastrada')
        ->assertDontSee('Cadastre a primeira negociação');
    // Should see the derived data via table records (employer: at least one record where emission_id matches)
    // Assert page has records by checking table can paginate
    expect($projection->hasAnyData())->toBeTrue();
    expect(Negotiation::query()->count())->toBe(0); // still no manual rows inserted
});

it('global listing with one contracts and one legacy emission shows both sources', function () {
    $this->actingAs(globalAdmin());

    $contractsEmission = Emission::factory()->withContractNegotiations()->create(['name' => 'Contracts Source']);
    $cConstruction = Construction::factory()->for($contractsEmission)->create(['development_name' => 'Dev Contracts']);
    $unit = ConstructionUnit::factory()->forConstruction($cConstruction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'GC-001', 'sale_date' => '2026-07-10']);

    $legacyEmission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'Legacy Source']);
    $lConstruction = Construction::factory()->for($legacyEmission)->create(['development_name' => 'Dev Legacy']);
    Negotiation::factory()->forEmissionAndConstruction($legacyEmission, $lConstruction)->create([
        'reference_month' => '2026-07-01', 'sales' => 5, 'cancellations' => 1,
    ]);

    $projection = app(NegotiationGlobalProjection::class);
    $rows = $projection->eloquentQuery([])->get();

    expect($rows->where('emission_id', $contractsEmission->id)->count())->toBeGreaterThan(0)
        ->and($rows->where('emission_id', $legacyEmission->id)->count())->toBeGreaterThan(0);

    // Ensure sources never mix within same emission (contracts emission has no legacy rows, legacy has no contract aggregates)
    $contractsRows = $rows->where('emission_id', $contractsEmission->id);
    $legacyRows = $rows->where('emission_id', $legacyEmission->id);
    foreach ($contractsRows as $r) {
        expect($r->source)->toBe('contracts');
    }
    foreach ($legacyRows as $r) {
        expect($r->source)->toBe('legacy');
    }
});

it('emission filter only filters existing global results', function () {
    $this->actingAs(globalAdmin());

    $contractsEmission = Emission::factory()->withContractNegotiations()->create(['name' => 'Filter Test Contracts']);
    $cConstruction = Construction::factory()->for($contractsEmission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($cConstruction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'FT-001', 'sale_date' => '2026-08-10']);

    $legacyEmission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'Filter Test Legacy']);
    $lConstruction = Construction::factory()->for($legacyEmission)->create();
    Negotiation::factory()->forEmissionAndConstruction($legacyEmission, $lConstruction)->create([
        'reference_month' => '2026-08-01', 'sales' => 2, 'cancellations' => 0,
    ]);

    $projection = app(NegotiationGlobalProjection::class);

    $all = $projection->eloquentQuery([])->get();
    expect($all)->toHaveCount(2);

    $onlyContracts = $projection->eloquentQuery(['emission_id' => $contractsEmission->id])->get();
    expect($onlyContracts)->toHaveCount(1)
        ->and((int) $onlyContracts->first()->emission_id)->toBe($contractsEmission->id);

    $onlyLegacy = $projection->eloquentQuery(['emission_id' => $legacyEmission->id])->get();
    expect($onlyLegacy)->toHaveCount(1)
        ->and((int) $onlyLegacy->first()->emission_id)->toBe($legacyEmission->id);

    // Via Filament table records: filtered projection should contain only one source
    $contractsRecords = $projection->eloquentQuery(['emission_id' => $contractsEmission->id])->get();
    Livewire::test(ListNegotiations::class)
        ->filterTable('emission_id', $contractsEmission->id)
        ->assertCanSeeTableRecords($contractsRecords)
        ->assertCanNotSeeTableRecords($projection->eloquentQuery(['emission_id' => $legacyEmission->id])->get()->all());
});

it('contract aggregate drill-down shows underlying contracts', function () {
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'Drill Test']);
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Drill Dev']);
    $u1 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'C', 'unit' => '7']);
    $u2 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '17']);
    Contract::factory()->forUnit($u1)->create(['code' => '10070766', 'sale_date' => '2026-07-06']);
    Contract::factory()->forUnit($u2)->create(['code' => '10070784', 'sale_date' => '2026-07-24']);

    $service = app(ContractNegotiationAggregates::class);
    $events = $service->detailEvents($emission->id, $construction->id, '2026-07-01', 'Venda');

    expect($events)->toHaveCount(2)
        ->and(collect($events)->pluck('code')->sort()->values()->all())->toBe(['10070766', '10070784'])
        ->and($events[0]['block'])->toBe('C')
        ->and($events[0]['unit'])->toBe('7')
        ->and($events[0]['date_formatted'])->toBe('06/07/2026');
});

it('manual creation only permits legacy emissions', function () {
    $this->actingAs(globalAdmin());

    $contractsEmission = Emission::factory()->withContractNegotiations()->create(['name' => 'Contracts Block']);
    Construction::factory()->for($contractsEmission)->create();
    $legacyEmission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'Legacy Allow']);
    Construction::factory()->for($legacyEmission)->create();

    // Resource guard: contracts emission cannot be selected in manual creation
    // Simulate request with data.emission_id = contractsEmission
    request()->merge(['data' => ['emission_id' => $contractsEmission->id]]);
    expect(NegotiationResource::canCreate())->toBeFalse();
    request()->replace([]);

    request()->merge(['data' => ['emission_id' => $legacyEmission->id]]);
    expect(NegotiationResource::canCreate())->toBeTrue();
    request()->replace([]);

    // Global header action visibility: should be visible because legacy exists (mixed)
    Livewire::test(ListNegotiations::class)->assertActionExists('create');
});

it('global empty state considers both sources', function () {
    $this->actingAs(globalAdmin());

    // Initially no data at all
    Livewire::test(ListNegotiations::class)
        ->assertSee('Nenhuma negociação cadastrada');

    // Add only contract data, no legacy rows — global empty must NOT show legacy empty
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'Empty Both Test']);
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'EMPTY-001', 'sale_date' => '2026-09-10']);

    $projection = app(NegotiationGlobalProjection::class);
    expect($projection->hasAnyData())->toBeTrue();
    expect(Negotiation::query()->count())->toBe(0);

    // Global listing now has data via projection, so legacy empty message must not appear
    Livewire::test(ListNegotiations::class)
        ->assertDontSee('Nenhuma negociação cadastrada')
        ->assertDontSee('Cadastre a primeira negociação');
});

it('no manual negotiation is inserted for contract events', function () {
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'No Insert']);
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'NOINS-001', 'sale_date' => '2026-07-10']);

    $before = Negotiation::query()->count();
    $rows = app(NegotiationGlobalProjection::class)->eloquentQuery([])->get();
    $after = Negotiation::query()->count();

    expect($rows)->toHaveCount(1)
        ->and($before)->toBe(0)
        ->and($after)->toBe(0);
});

it('CRI Alto Bellevue-like dataset produces expected monthly aggregate', function () {
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'CRI Alto Bellevue']);
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Alto Bellevue']);
    $u1 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'C', 'unit' => '7']);
    $u2 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '17']);
    $u3 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'D', 'unit' => '20']);
    Contract::factory()->forUnit($u1)->create(['code' => '10070766', 'sale_date' => '2026-07-06']);
    Contract::factory()->forUnit($u2)->create(['code' => '10070784', 'sale_date' => '2026-07-24']);
    Contract::factory()->forUnit($u3)->create(['code' => '10070783', 'sale_date' => '2026-07-27']);

    $projection = app(NegotiationGlobalProjection::class);
    $rows = $projection->eloquentQuery(['emission_id' => $emission->id])->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->reference_month->toDateString())->toBe('2026-07-01')
        ->and((int) $rows->first()->sales)->toBe(3)
        ->and((int) $rows->first()->cancellations)->toBe(0);

    $events = app(ContractNegotiationAggregates::class)->detailEvents($emission->id, $construction->id, '2026-07-01');
    expect($events)->toHaveCount(3)
        ->and(collect($events)->pluck('code')->sort()->values()->all())->toBe(['10070766', '10070783', '10070784']);
});
