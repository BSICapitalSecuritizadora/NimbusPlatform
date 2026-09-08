<?php

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesBoard;
use App\Models\SalesDiscountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

it('prints the derived composition of a construction', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', '500000.00');

    $financedUnit = DerivationFixture::unit($construction, '102');
    $contract = DerivationFixture::contract($financedUnit, '2026-01-10', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-12-10', '600000.00');

    $this->artisan('sales-boards:derive', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
    ])
        ->expectsOutputToContain('Estoque')
        ->expectsOutputToContain('Financiado')
        ->expectsOutputToContain('READY')
        ->assertSuccessful();
});

it('reports blocked readiness with the blocking codes', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', null);

    $this->artisan('sales-boards:derive', [
        '--construction' => $construction->id,
        '--reference-month' => '2026-07',
    ])
        ->expectsOutputToContain('BLOCKED')
        ->expectsOutputToContain('UNIT_VALUE_MISSING')
        ->assertSuccessful();
});

it('derives every construction of an emission', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101');

    $sibling = Construction::factory()->create(['emission_id' => $construction->emission_id]);
    DerivationFixture::unit($sibling, '201');

    $this->artisan('sales-boards:derive', [
        '--emission' => $construction->emission_id,
        '--reference-month' => '07/2026',
    ])
        ->expectsOutputToContain($construction->development_name)
        ->expectsOutputToContain($sibling->development_name)
        ->assertSuccessful();
});

it('emits json when asked', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', '500000.00');

    $this->artisan('sales-boards:derive', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
        '--json' => true,
        '--details' => true,
    ])->assertSuccessful();
});

it('refuses an invalid competence and a missing target', function () {
    $construction = DerivationFixture::construction();

    $this->artisan('sales-boards:derive', ['--construction' => $construction->id, '--reference-month' => '13/2026'])
        ->expectsOutputToContain('Competência inválida')
        ->assertFailed();

    $this->artisan('sales-boards:derive')
        ->expectsOutputToContain('Informe --construction ou --emission')
        ->assertFailed();

    $this->artisan('sales-boards:derive', ['--construction' => 999999])
        ->expectsOutputToContain('não encontrado')
        ->assertFailed();
});

it('writes absolutely nothing', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '500000.00');
    $contract = DerivationFixture::contract($unit, '2026-07-10', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-12-10', '600000.00');
    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->create();
    ConstructionUnitExchange::factory()->forUnit(DerivationFixture::unit($construction, '102'))->create();
    SalesBoard::factory()->forEmissionAndConstruction($construction->emission, $construction)->create(['reference_month' => '2026-07-01']);

    $before = [
        'sales_boards' => SalesBoard::count(),
        'contracts' => Contract::count(),
        'installments' => ContractInstallment::count(),
        'units' => ConstructionUnit::count(),
        'unit_values' => ConstructionUnitValue::count(),
        'policies' => SalesDiscountPolicy::count(),
        'exchanges' => ConstructionUnitExchange::count(),
    ];

    $contractTouchedAt = $contract->updated_at->toDateTimeString();

    $this->artisan('sales-boards:derive', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
        '--details' => true,
    ])->assertSuccessful();

    expect([
        'sales_boards' => SalesBoard::count(),
        'contracts' => Contract::count(),
        'installments' => ContractInstallment::count(),
        'units' => ConstructionUnit::count(),
        'unit_values' => ConstructionUnitValue::count(),
        'policies' => SalesDiscountPolicy::count(),
        'exchanges' => ConstructionUnitExchange::count(),
    ])->toBe($before)
        ->and($contract->fresh()->updated_at->toDateTimeString())->toBe($contractTouchedAt);
});

it('shows the legacy comparison verdict', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', '500000.00');

    SalesBoard::factory()->forEmissionAndConstruction($construction->emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 9,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '500000.00',
        'financed_value' => '0.00',
        'paid_value' => '0.00',
        'exchanged_value' => '0.00',
    ]);

    $this->artisan('sales-boards:derive', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
    ])
        ->expectsOutputToContain('divergente')
        ->expectsOutputToContain('stock_units')
        ->assertSuccessful();
});
