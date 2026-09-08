<?php

use App\Enums\ConstructionUnitExchangeKind;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesDiscountPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * Um empreendimento com uma venda, congelado, e a fonte alterada depois.
 *
 * @return array{0: SalesBoardCycleBaseline, 1: Contract, 2: ConstructionUnit, 3: Construction}
 */
function frozenCycleWithSale(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $contract = DerivationFixture::contract($units[0], '2026-07-05', '950000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '950000.00');

    CycleFixture::generate($construction);

    return [CycleFixture::currentBaseline(SalesBoardCycle::sole()), $contract, $units[0], $construction];
}

it('keeps the frozen contract value after the live contract changes', function () {
    [$baseline, $contract] = frozenCycleWithSale();

    $contract->update(['sale_value' => '960000.00']);

    $line = $baseline->fresh()->lines->firstWhere('contract_id', $contract->id);
    $movement = $baseline->fresh()->movements->firstWhere('contract_id', $contract->id);

    expect($line->contract_sale_value)->toBe('950000.00')
        ->and($movement->sale_value)->toBe('950000.00')
        ->and($contract->fresh()->sale_value)->toBe('960000.00');
});

it('keeps the frozen contract code and sale date after the live contract changes', function () {
    [$baseline, $contract] = frozenCycleWithSale();

    $originalCode = $contract->code;
    $contract->update(['code' => 'OUTRO-CODIGO', 'sale_date' => '2026-07-09']);

    $line = $baseline->fresh()->lines->firstWhere('contract_id', $contract->id);

    expect($line->contract_code)->toBe($originalCode)
        ->and($line->contract_sale_date->toDateString())->toBe('2026-07-05');
});

it('keeps the frozen unit reference value after a new value history row lands', function () {
    [$baseline, , $unit] = frozenCycleWithSale();

    ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $unit->id,
        'value' => '777000.00',
        'effective_from' => '2026-07-01',
    ]);

    $movement = $baseline->fresh()->movements->firstWhere('construction_unit_id', $unit->id);

    expect($movement->unit_reference_value)->toBe('500000.00');
});

it('keeps the frozen policy verdict after a new policy lands', function () {
    [$baseline, $contract, , $construction] = frozenCycleWithSale();

    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2026-07-01')
        ->allowing('1.00')
        ->create();

    $movement = $baseline->fresh()->movements->firstWhere('contract_id', $contract->id);

    expect($movement->authorized_discount_basis_points)->toBe(1_000)
        ->and($movement->minimum_authorized_value)->toBe('450000.00');
});

it('keeps the frozen exchange after the live exchange changes', function () {
    [$construction, $units] = CycleFixture::readyConstruction(1);

    $exchange = ConstructionUnitExchange::factory()->create([
        'construction_unit_id' => $units[0]->id,
        'exchange_value' => '480000.00',
        'effective_from' => '2026-01-01',
        'kind' => ConstructionUnitExchangeKind::Baseline,
    ]);

    CycleFixture::generate($construction);
    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    $exchange->update(['exchange_value' => '999000.00']);

    $line = $baseline->fresh()->lines->firstWhere('construction_unit_id', $units[0]->id);

    expect($line->exchange_value)->toBe('480000.00')
        ->and($line->construction_unit_exchange_id)->toBe($exchange->id)
        ->and($line->exchange_effective_from->toDateString())->toBe('2026-01-01')
        ->and($line->exchange_kind)->toBe(ConstructionUnitExchangeKind::Baseline);
});

it('refuses to update an apurado field of a baseline', function () {
    [$baseline] = frozenCycleWithSale();

    $baseline->update(['stock_units' => 99]);
})->throws(LogicException::class, 'A persisted sales board baseline is immutable.');

it('allows only the stale metadata to change on a baseline', function () {
    [$baseline] = frozenCycleWithSale();

    $baseline->forceFill(['is_stale' => true, 'last_checked_at' => now()])->save();

    expect($baseline->fresh()->is_stale)->toBeTrue();
});

it('refuses to delete a baseline', function () {
    [$baseline] = frozenCycleWithSale();

    $baseline->delete();
})->throws(LogicException::class, 'Sales board baselines cannot be deleted.');

it('refuses to update a frozen line', function () {
    [$baseline] = frozenCycleWithSale();

    $baseline->lines->first()->update(['contract_sale_value' => '1.00']);
})->throws(LogicException::class, 'Sales board cycle lines are immutable.');

it('refuses to delete a frozen line', function () {
    [$baseline] = frozenCycleWithSale();

    $baseline->lines->first()->delete();
})->throws(LogicException::class, 'Sales board cycle lines are immutable.');

it('refuses to update a frozen movement', function () {
    [$baseline] = frozenCycleWithSale();

    $baseline->movements->first()->update(['sale_value' => '1.00']);
})->throws(LogicException::class, 'Sales board cycle movements are immutable.');

it('refuses to delete a frozen movement', function () {
    [$baseline] = frozenCycleWithSale();

    $baseline->movements->first()->delete();
})->throws(LogicException::class, 'Sales board cycle movements are immutable.');

it('refuses to delete a cycle', function () {
    frozenCycleWithSale();

    SalesBoardCycle::sole()->delete();
})->throws(LogicException::class, 'Sales board cycles cannot be deleted.');

it('refuses to rewrite the identity of a cycle', function () {
    frozenCycleWithSale();

    SalesBoardCycle::sole()->update(['reference_month' => '2026-08-01']);
})->throws(LogicException::class, 'A sales board cycle identity is immutable.');

it('protects the frozen sources from being deleted underneath the snapshot', function () {
    [, $contract, $unit] = frozenCycleWithSale();

    expect(fn () => $contract->forceDelete())->toThrow(QueryException::class)
        ->and(fn () => $unit->delete())->toThrow(QueryException::class)
        ->and(SalesBoardCycleLine::query()->count())->toBe(2)
        ->and(SalesBoardCycleMovement::query()->count())->toBe(1);
});
