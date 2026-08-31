<?php

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\Negotiation;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('does not silently switch to contracts when emission is legacy even if contracts exist (partial migration guard)', function () {
    // Emission is legacy (default), but has some contracts already imported (partial)
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    // Partial: only one contract, but historically there should be more via manual
    Contract::factory()->forUnit($unit)->create([
        'code' => 'PARTIAL-001',
        'sale_date' => '2026-07-10',
    ]);

    // Manual negotiations that would be the correct source for this legacy emission
    Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 5,
        'cancellations' => 2,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    // Must use legacy, not contracts: should show 5 sales from manual, not 1 from contract
    expect($data['negotiations']['source'])->toBe('legacy')
        ->and($data['negotiations']['has_data'])->toBeTrue()
        ->and($data['negotiations']['rows'])->toContain(['label' => 'Vendas (mês)', 'value' => '5'])
        ->and($data['negotiations']['rows'])->toContain(['label' => 'Distratos (mês)', 'value' => '2']);
});

it('legacy emission continues using manual negotiation data', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();

    Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-05-01',
        'sales' => 3,
        'cancellations' => 1,
    ]);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['negotiations']['source'])->toBe('legacy')
        ->and($data['negotiations']['has_data'])->toBeTrue();
});

it('migrated emission uses contract events', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create([
        'code' => 'MIGRATED-001',
        'sale_date' => '2026-07-15',
    ]);

    // Create manual before migration, then migrate - manual must be ignored after
    Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 99,
        'cancellations' => 99,
    ]);

    $emission->update(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS]);
    $emission->refresh();

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    expect($data['negotiations']['source'])->toBe('contracts')
        ->and($data['negotiations']['sales_count'])->toBe(1)
        ->and($data['negotiations']['vendas'][0]['code'])->toBe('MIGRATED-001');
});

it('does not double count manual and contract data', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create();

    Contract::factory()->forUnit($unit)->create(['code' => 'C-001', 'sale_date' => '2026-07-10']);
    Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 10,
        'cancellations' => 5,
    ]);

    $emission->update(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS]);
    $emission->refresh();

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    // Must be 1 from contract, not 11 (1+10)
    expect($data['negotiations']['sales_count'])->toBe(1)
        ->and($data['negotiations']['cancellations_count'])->toBe(0);
});

it('prevents manual negotiation creation for contract-driven emission', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();

    expect(fn () => Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 1,
        'cancellations' => 0,
    ]))->toThrow(ValidationException::class);
});

it('allows manual negotiation for legacy emission', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();

    $negotiation = Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 2,
        'cancellations' => 1,
    ]);

    expect($negotiation->exists)->toBeTrue();
});

it('historical timeline includes zero-movement months for contract-driven emission', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $units = ConstructionUnit::factory()->forConstruction($construction)->count(5)->create();

    // Feb 3 sales, Mar 0, Apr 2, May 0, Jun 1, Jul 4
    foreach (['2026-02-05', '2026-02-10', '2026-02-15'] as $date) {
        Contract::factory()->forUnit($units->pop())->create(['code' => 'FEB-'.uniqid(), 'sale_date' => $date]);
    }
    // Mar: nothing
    foreach (['2026-04-05', '2026-04-15'] as $date) {
        Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'APR-'.uniqid(), 'sale_date' => $date]);
    }
    // May: nothing
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'JUN-001', 'sale_date' => '2026-06-10']);
    foreach (['2026-07-01', '2026-07-05', '2026-07-10', '2026-07-20'] as $date) {
        Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'JUL-'.uniqid(), 'sale_date' => $date]);
    }

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));

    $history = $data['negotiations_history'];

    expect($history['has_data'])->toBeTrue()
        ->and($history['rows'])->toHaveCount(6)
        ->and($history['rows'][0]['competencia'])->toBe('02/2026')
        ->and($history['rows'][0]['sales'])->toBe('3')
        ->and($history['rows'][1]['competencia'])->toBe('03/2026')
        ->and($history['rows'][1]['sales'])->toBe('0')
        ->and($history['rows'][2]['competencia'])->toBe('04/2026')
        ->and($history['rows'][2]['sales'])->toBe('2')
        ->and($history['rows'][3]['competencia'])->toBe('05/2026')
        ->and($history['rows'][3]['sales'])->toBe('0')
        ->and($history['rows'][4]['competencia'])->toBe('06/2026')
        ->and($history['rows'][4]['sales'])->toBe('1')
        ->and($history['rows'][5]['competencia'])->toBe('07/2026')
        ->and($history['rows'][5]['sales'])->toBe('4');
});

it('historical window remains correctly bounded to last 6 months', function () {
    $emission = Emission::factory()->withContractNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();

    // Create a sale in Jan (outside 6-month window Feb-Jul) - should not appear
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'JAN-001', 'sale_date' => '2026-01-10']);
    Contract::factory()->forUnit(ConstructionUnit::factory()->forConstruction($construction)->create())->create(['code' => 'JUL-001', 'sale_date' => '2026-07-10']);

    $data = app(EmissionMonthlyReportService::class)->build($emission, CarbonImmutable::parse('2026-07-01'));
    $history = $data['negotiations_history'];

    // Window is Feb-Jul (6 months), Jan should not be included, so first row is Feb
    expect($history['rows'][0]['competencia'])->toBe('02/2026')
        ->and(collect($history['rows'])->pluck('competencia')->contains('01/2026'))->toBeFalse();
});

it('prevents editing legacy data for contract-driven emission via model', function () {
    $emission = Emission::factory()->withLegacyNegotiations()->create();
    $construction = Construction::factory()->for($emission)->create();
    $negotiation = Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-07-01',
        'sales' => 1,
        'cancellations' => 0,
    ]);

    // Migrate emission to contracts
    $emission->update(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS]);

    expect(fn () => $negotiation->update(['sales' => 5]))->toThrow(ValidationException::class);
});
