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

function navAdmin(): User
{
    $u = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $u->assignRole('admin');

    return $u;
}

// 1. contracts-derived row must NOT generate /admin/negotiations/{syntheticId}
it('contracts-derived row does not generate synthetic view URL and has no recordUrl', function () {
    $this->actingAs(navAdmin());

    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'CRI Alto Bellevue Nav']);
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Alto Bellevue']);
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'C', 'unit' => '7']);
    Contract::factory()->forUnit($unit)->create(['code' => '10070766', 'sale_date' => '2026-07-06']);

    $projection = app(NegotiationGlobalProjection::class);
    $synthetic = $projection->eloquentQuery([])->first();
    expect($synthetic)->not->toBeNull()
        ->and($synthetic->getAttribute('source'))->toBe('contracts');

    $syntheticId = $synthetic->getKey();
    // The synthetic ID must not resolve to a real Negotiation
    expect(Negotiation::find($syntheticId))->toBeNull();

    // Table must not generate a synthetic URL — Livewire page should not contain it
    Livewire::test(ListNegotiations::class)
        ->assertDontSee("negotiations/{$syntheticId}")
        ->assertDontSee("/admin/negotiations/{$syntheticId}");

    // Also ensure eloquent query does not create a Negotiation row
    expect(Negotiation::query()->where('emission_id', $emission->id)->count())->toBe(0);
});

// 2. contracts rows have working drill-down via verDetalhes slideOver
it('contracts rows expose Ver detalhes drill-down with correct contract data', function () {
    $this->actingAs(navAdmin());

    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'CRI Drill Nav']);
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Alto Bellevue']);
    $u1 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'C', 'unit' => '7']);
    $u2 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'A', 'unit' => '17']);
    $u3 = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'D', 'unit' => '20']);
    Contract::factory()->forUnit($u1)->create(['code' => '10070766', 'sale_date' => '2026-07-06']);
    Contract::factory()->forUnit($u2)->create(['code' => '10070784', 'sale_date' => '2026-07-24']);
    Contract::factory()->forUnit($u3)->create(['code' => '10070783', 'sale_date' => '2026-07-27', 'cancellation_date' => '2026-08-15', 'status' => ContractStatus::Cancelled]);

    $projection = app(NegotiationGlobalProjection::class);
    $july = $projection->eloquentQuery(['emission_id' => $emission->id])->where('reference_month', '2026-07-01')->first();
    expect($july)->not->toBeNull()
        ->and((int) $july->sales)->toBe(3);

    // Drill-down must use emission_id, construction_id, reference_month via ContractNegotiationAggregates
    $events = app(ContractNegotiationAggregates::class)->detailEvents($emission->id, $construction->id, '2026-07-01');
    expect($events)->toHaveCount(3)
        ->and(collect($events)->pluck('code')->sort()->values()->all())->toBe(['10070766', '10070783', '10070784'])
        ->and($events[0]['block'])->toBe('C')
        ->and($events[0]['unit'])->toBe('7')
        ->and($events[0]['date_formatted'])->toBe('06/07/2026')
        ->and($events[0]['type'])->toBe('Venda');

    // Verify Filament table exposes the custom action and hides default View/Edit for contracts
    $component = Livewire::test(ListNegotiations::class);
    // The table should contain the contracts row and the custom action label
    $component->assertSee('Ver detalhes');
    // Default View/Edit labels should still exist for legacy but not as the primary for contracts — we check the custom action exists
    expect($july->getAttribute('source'))->toBe('contracts');
});

// 3. legacy rows still open normally via NegotiationResource view
it('legacy rows still open normally via view', function () {
    $this->actingAs(navAdmin());

    $emission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'Legacy Nav']);
    $construction = Construction::factory()->for($emission)->create(['development_name' => 'Legacy Dev']);
    $negotiation = Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01', 'sales' => 4, 'cancellations' => 1,
    ]);

    // Legacy record must have a real view URL
    $url = NegotiationResource::getUrl('view', ['record' => $negotiation]);
    expect($url)->toContain("/admin/negotiations/{$negotiation->id}");

    // Livewire view page should resolve
    $this->actingAs(navAdmin())->get($url)->assertSuccessful();

    expect($negotiation->getAttribute('source'))->toBeNull(); // real rows have no source column, treated as legacy
});

// 4. no synthetic Negotiation row is inserted
it('no synthetic Negotiation row is inserted for contract aggregates', function () {
    $emission = Emission::factory()->withContractNegotiations()->create(['name' => 'No Synthetic Nav']);
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'SYN-001', 'sale_date' => '2026-09-10']);

    $before = Negotiation::query()->count();
    app(NegotiationGlobalProjection::class)->eloquentQuery([])->get();
    $after = Negotiation::query()->count();

    expect($before)->toBe(0)->and($after)->toBe(0);

    // Also ensure no row with synthetic id exists in negotiations table
    $projection = app(NegotiationGlobalProjection::class);
    $synthetic = $projection->eloquentQuery([])->first();
    expect(Negotiation::where('id', $synthetic->getKey())->exists())->toBeFalse();
});

// 5. mixed legacy/contracts listing remains functional without 404
it('mixed listing remains functional with correct actions per source', function () {
    $this->actingAs(navAdmin());

    $contractsEmission = Emission::factory()->withContractNegotiations()->create(['name' => 'Mixed Contracts']);
    $cConstruction = Construction::factory()->for($contractsEmission)->create(['development_name' => 'Dev Contracts']);
    $unit = ConstructionUnit::factory()->forConstruction($cConstruction)->create();
    Contract::factory()->forUnit($unit)->create(['code' => 'MIX-C-001', 'sale_date' => '2026-07-10']);

    $legacyEmission = Emission::factory()->withLegacyNegotiations()->create(['name' => 'Mixed Legacy']);
    $lConstruction = Construction::factory()->for($legacyEmission)->create(['development_name' => 'Dev Legacy']);
    Negotiation::factory()->forEmissionAndConstruction($legacyEmission, $lConstruction)->create([
        'reference_month' => '2026-07-01', 'sales' => 5, 'cancellations' => 1,
    ]);

    $projection = app(NegotiationGlobalProjection::class);
    $rows = $projection->eloquentQuery([])->get();
    expect($rows)->toHaveCount(2);
    expect($rows->where('source', 'contracts')->count())->toBe(1)
        ->and($rows->where('source', 'legacy')->count())->toBe(1);

    // Filament page must render both without 404
    Livewire::test(ListNegotiations::class)
        ->assertSee('Mixed Contracts')
        ->assertSee('Mixed Legacy')
        ->assertSee('Ver detalhes'); // contracts action

    // Legacy view URL still works
    $legacyRecord = Negotiation::first();
    $this->get(NegotiationResource::getUrl('view', ['record' => $legacyRecord]))->assertSuccessful();

    // Synthetic contracts URL must 404 if forced (proof that we correctly hide it)
    $synthetic = $rows->firstWhere('source', 'contracts');
    $this->get("/admin/negotiations/{$synthetic->getKey()}")->assertNotFound();
    $this->get("/admin/negotiations/{$synthetic->getKey()}/edit")->assertNotFound();
});
