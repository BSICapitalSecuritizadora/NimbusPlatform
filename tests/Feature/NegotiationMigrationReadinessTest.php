<?php

use App\Enums\ContractStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\Negotiation;
use App\Services\Reports\NegotiationMigrationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {});

it('legacy emission with no contracts is incompleto', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    ConstructionUnit::factory()->forConstruction($construction)->count(3)->create();

    // Legacy history exists
    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-02-01',
        'sales' => 3,
        'cancellations' => 0,
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['contract_coverage']['total'])->toBe(0)
        ->and($analysis['legacy']['rows'])->toBe(1)
        ->and($analysis['readiness']['status'])->toBe('incompleto');
});

it('legacy emission with complete-looking contract dataset is pronto', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(5)->create();

    // 3 sales in Feb, 2 sales in Mar
    Contract::factory()->forUnit($units[0])->create(['sale_date' => '2026-02-05', 'status' => ContractStatus::Active]);
    Contract::factory()->forUnit($units[1])->create(['sale_date' => '2026-02-10', 'status' => ContractStatus::Active]);
    Contract::factory()->forUnit($units[2])->create(['sale_date' => '2026-02-15', 'status' => ContractStatus::Active]);
    Contract::factory()->forUnit($units[3])->create(['sale_date' => '2026-03-05', 'status' => ContractStatus::Active]);
    Contract::factory()->forUnit($units[4])->create(['sale_date' => '2026-03-10', 'status' => ContractStatus::Active]);

    // Matching legacy
    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-02-01',
        'sales' => 3,
        'cancellations' => 0,
    ]);
    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-03-01',
        'sales' => 2,
        'cancellations' => 0,
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['contract_coverage']['without_sale_date'])->toBe(0)
        ->and($analysis['contract_coverage']['cancelled_without_cancellation_date'])->toBe(0)
        ->and($analysis['readiness']['status'])->toBe('pronto')
        ->and($analysis['readiness']['label'])->toBe('Pronto para validação');
});

it('partial contract migration yields atencao due to divergence', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(5)->create();

    // Only 2 of 5 contracts migrated for Feb
    Contract::factory()->forUnit($units[0])->create(['sale_date' => '2026-02-05']);
    Contract::factory()->forUnit($units[1])->create(['sale_date' => '2026-02-10']);

    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-02-01',
        'sales' => 5,
        'cancellations' => 0,
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    // No hard incompleteness issues, but divergent month triggers atencao
    expect($analysis['monthly_reconciliation']->where('is_divergent', true)->count())->toBeGreaterThan(0)
        ->and($analysis['readiness']['status'])->toBe('atencao');
});

it('sale_date is a NOT NULL invariant — coverage remains zero and does not drive readiness failure', function () {
    // sale_date is NOT NULL per 2026_08_20_213519 and required by ContractForm.
    // A valid production DB cannot contain NULL sale_date; the diagnostic
    // tracks the count informationally but it must not make readiness incompleto
    // and must not require weakening the schema to test.
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create(['sale_date' => '2026-02-05']);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['contract_coverage']['without_sale_date'])->toBe(0)
        ->and($analysis['readiness']['status'])->not->toBe('incompleto');
});

it('cancelled contract without cancellation date makes incompleto', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    $contract = Contract::factory()->forUnit($unit)->create([
        'sale_date' => '2026-02-05',
        'status' => ContractStatus::Cancelled,
        'cancellation_date' => '2026-03-01',
    ]);
    DB::table('contracts')->where('id', $contract->id)->update(['cancellation_date' => null]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['contract_coverage']['cancelled_without_cancellation_date'])->toBe(1)
        ->and($analysis['readiness']['status'])->toBe('incompleto');
});

it('contract construction mismatch vs unit construction is flagged as incompleto (real drift without FK violation)', function () {
    // construction_unit_id has FK restrictOnDelete — referencing a non-existent unit
    // is impossible in a valid production DB (would require disabling FK checks).
    // The real integrity scenario is denormalized construction_id drifting from
    // the unit's construction_id, or pointing to another emission. That CAN occur
    // via raw DB updates bypassing Contract::saving and must make readiness incompleto.
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $constructionA = Construction::factory()->for($emission)->create();
    $constructionB = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($constructionA)->create();

    $contract = Contract::factory()->forUnit($unit)->create(['sale_date' => '2026-02-05']);
    // Drift: contract construction_id no longer matches unit's construction_id
    DB::table('contracts')->where('id', $contract->id)->update(['construction_id' => $constructionB->id]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['contract_coverage']['inconsistent'])->toBeGreaterThan(0)
        ->and($analysis['readiness']['status'])->toBe('incompleto');
});

it('matching legacy vs contract month is compatible', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(3)->create();

    Contract::factory()->forUnit($units[0])->create(['sale_date' => '2026-02-05']);
    Contract::factory()->forUnit($units[1])->create(['sale_date' => '2026-02-10']);
    Contract::factory()->forUnit($units[2])->create(['sale_date' => '2026-02-15', 'cancellation_date' => '2026-02-20', 'status' => ContractStatus::Cancelled]);

    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-02-01',
        'sales' => 3,
        'cancellations' => 1,
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);
    $feb = $analysis['monthly_reconciliation']->firstWhere('competencia', '02/2026');

    expect($feb)->not->toBeNull()
        ->and($feb['situacao'])->toBe('Compatível')
        ->and($feb['is_divergent'])->toBeFalse();
});

it('divergent legacy vs contract month is highlighted', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(3)->create();

    Contract::factory()->forUnit($units[0])->create(['sale_date' => '2026-03-05']);
    Contract::factory()->forUnit($units[1])->create(['sale_date' => '2026-03-10']);

    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-03-01',
        'sales' => 5,
        'cancellations' => 0,
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);
    $mar = $analysis['monthly_reconciliation']->firstWhere('competencia', '03/2026');

    expect($mar['is_divergent'])->toBeTrue()
        ->and($mar['situacao'])->toBe('Divergência')
        ->and($analysis['readiness']['status'])->toBe('atencao');
});

it('no legacy history still provides contract diagnostics', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create(['sale_date' => '2026-04-05']);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['legacy']['rows'])->toBe(0)
        ->and($analysis['legacy']['earliest_label'])->toBe('—')
        ->and($analysis['contract_coverage']['total'])->toBe(1);
});

it('already migrated contracts emission shows diagnostic as contracts source', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create(['sale_date' => '2026-05-05']);

    // Legacy rows still exist but should be preserved
    // Need to create legacy negotiation via raw DB bypassing guard (since emission is contracts)
    DB::table('negotiations')->insert([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-05-01',
        'sales' => 1,
        'cancellations' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['is_contracts'])->toBeTrue()
        ->and($analysis['source'])->toBe(Emission::NEGOTIATIONS_SOURCE_CONTRACTS)
        ->and($analysis['legacy']['rows'])->toBe(1);
});

it('readiness analysis does not change negotiations_source', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $originalSource = $emission->negotiations_source;

    app(NegotiationMigrationReadinessService::class)->analyze($emission);
    app(NegotiationMigrationReadinessService::class)->analyze($emission->fresh());

    expect($emission->fresh()->negotiations_source)->toBe($originalSource);
});

it('switching source preserves legacy negotiation rows', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();

    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-02-01',
        'sales' => 3,
        'cancellations' => 1,
    ]);

    $beforeCount = Negotiation::where('emission_id', $emission->id)->count();

    $emission->update(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS]);

    expect($emission->fresh()->negotiations_source)->toBe(Emission::NEGOTIATIONS_SOURCE_CONTRACTS)
        ->and(Negotiation::where('emission_id', $emission->id)->count())->toBe($beforeCount);
});

it('contracts and legacy values are never merged', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create(['sale_date' => '2026-02-05']);
    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-02-01',
        'sales' => 3,
        'cancellations' => 0,
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);
    $feb = $analysis['monthly_reconciliation']->firstWhere('competencia', '02/2026');

    // Both values are exposed separately, not summed
    expect($feb['legacy_sales'])->toBe(3)
        ->and($feb['contract_sales'])->toBe(1)
        ->and($analysis['legacy']['total_sales'])->toBe(3)
        ->and($analysis['contract_coverage']['total'])->toBe(1);
});

it('handles units without contracts as informational not failure', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(10)->create();

    // Only 2 units have contracts, rest are estoque/permuta info - should not be incompleto solely due to this
    Contract::factory()->forUnit($units[0])->create(['sale_date' => '2026-02-05']);
    Contract::factory()->forUnit($units[1])->create(['sale_date' => '2026-02-10']);

    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-02-01',
        'sales' => 2,
        'cancellations' => 0,
    ]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['unit_coverage']['units_count'])->toBe(10)
        ->and($analysis['unit_coverage']['units_with_contracts'])->toBe(2)
        ->and($analysis['unit_coverage']['units_without_contracts'])->toBe(8)
        ->and($analysis['readiness']['status'])->toBe('pronto');
});

it('inconsistent construction relationship is detected and makes readiness incompleto', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $otherEmission = Emission::factory()->withLegacyNegotiations()->create();
    $otherConstruction = Construction::factory()->for($otherEmission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    $contract = Contract::factory()->forUnit($unit)->create(['sale_date' => '2026-02-05']);
    // Force mismatch: construction_id points to other emission's construction (emission drift)
    DB::table('contracts')->where('id', $contract->id)->update(['construction_id' => $otherConstruction->id]);

    $analysis = app(NegotiationMigrationReadinessService::class)->analyze($emission);

    expect($analysis['contract_coverage']['inconsistent'])->toBeGreaterThan(0)
        ->and($analysis['readiness']['status'])->toBe('incompleto');
});
