<?php

use App\DTOs\SalesBoards\ResolvedSalesDiscountPolicy;
use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\Enums\SalesPriceConformityStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesPriceConformityEvaluator;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function conformityEvaluator(): SalesPriceConformityEvaluator
{
    return app(SalesPriceConformityEvaluator::class);
}

/**
 * A unit worth R$ 1.000.000,00 since january in a development that authorises
 * the given discount since january.
 */
function unitSoldFor(string $saleValue, ?string $maximumDiscountPercent = '5.00', string $saleDate = '2026-07-25', ?string $baseValue = '1000000.00'): Contract
{
    $construction = Construction::factory()->create();

    $unit = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'base_value' => $baseValue,
        'base_value_reference_date' => $baseValue === null ? null : '2026-01-01',
    ]);

    if ($maximumDiscountPercent !== null) {
        SalesDiscountPolicy::factory()
            ->forConstruction($construction)
            ->effectiveFrom('2026-01-01')
            ->allowing($maximumDiscountPercent)
            ->create();
    }

    return Contract::factory()->create([
        'construction_unit_id' => $unit->id,
        'sale_date' => $saleDate,
        'sale_value' => $saleValue,
    ]);
}

it('accepts a sale exactly at the authorised floor', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('950000.00'));

    expect($result->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($result->minimumAuthorizedValueCents)->toBe(95_000_000)
        ->and($result->saleValueCents)->toBe(95_000_000)
        ->and($result->differenceCents)->toBe(0)
        ->and($result->effectiveDiscountBasisPoints)->toBe(500);
});

it('rejects a sale one cent below the authorised floor', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('949999.99'));

    expect($result->status)->toBe(SalesPriceConformityStatus::NonConform)
        ->and($result->minimumAuthorizedValueCents)->toBe(95_000_000)
        ->and($result->saleValueCents)->toBe(94_999_999)
        ->and($result->differenceCents)->toBe(-1);
});

it('accepts a sale at the full table price', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('1000000.00'));

    expect($result->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($result->effectiveDiscountBasisPoints)->toBe(0)
        ->and($result->effectiveDiscountLabel())->toBe('Sem desconto');
});

it('accepts a sale above the table price and reports it as a premium', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('1010000.00'));

    expect($result->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($result->hasPremium())->toBeTrue()
        ->and($result->effectiveDiscountBasisPoints)->toBe(-100)
        ->and($result->effectiveDiscountLabel())->toBe('Ágio de 1,00%');
});

it('is undetermined when the unit had no reference value on the sale date', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('950000.00', baseValue: null));

    expect($result->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($result->reasonWhenUndetermined)->toContain('valor de referência')
        ->and($result->minimumAuthorizedValueCents)->toBeNull();
});

it('is undetermined when the development had no policy on the sale date', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('950000.00', maximumDiscountPercent: null));

    expect($result->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($result->reasonWhenUndetermined)->toContain('política de desconto')
        ->and($result->minimumAuthorizedValueCents)->toBeNull();
});

it('never treats a missing policy as compliance even when the sale is at full price', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('1000000.00', maximumDiscountPercent: null));

    expect($result->isConform())->toBeFalse()
        ->and($result->isUndetermined())->toBeTrue();
});

it('judges the sale by the facts in force on the sale date, not by the current ones', function () {
    $construction = Construction::factory()->create();

    $unit = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'base_value' => '1000000.00',
        'base_value_reference_date' => '2026-01-01',
    ]);

    // The table was raised and the policy tightened AFTER the sale.
    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-08-01')->worth('1100000.00')->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-08-01')->allowing('1.00')->create();

    $contract = Contract::factory()->create([
        'construction_unit_id' => $unit->id,
        'sale_date' => '2026-07-25',
        'sale_value' => '950000.00',
    ]);

    $result = conformityEvaluator()->forContract($contract);

    expect($result->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($result->referenceValueCents)->toBe(100_000_000)
        ->and($result->authorizedDiscountBasisPoints)->toBe(500)
        ->and($result->minimumAuthorizedValueCents)->toBe(95_000_000);
});

it('rounds the minimum price up so truncation never grants extra discount', function () {
    // 1.000.000,01 with 5% authorised: the exact floor is 950.000,0095, and the
    // first cent that still respects the policy is 950.000,01.
    $referenceCents = 100_000_001;

    $minimum = IntegerMoney::minimumAfterDiscount($referenceCents, 500);

    expect($minimum)->toBe(95_000_001);

    $atFloor = conformityEvaluator()->forContract(unitSoldFor('950000.01', baseValue: '1000000.01'));
    $oneCentBelow = conformityEvaluator()->forContract(unitSoldFor('950000.00', baseValue: '1000000.01'));

    expect($atFloor->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($oneCentBelow->status)->toBe(SalesPriceConformityStatus::NonConform);
});

it('computes the minimum price without overflowing at the limit of decimal(15,2)', function () {
    // 9.999.999.999.999,99 -- the largest value the column can hold.
    $referenceCents = 999_999_999_999_999;

    $minimum = IntegerMoney::minimumAfterDiscount($referenceCents, 500);

    // 999.999.999.999.999 x 0,95 = 949.999.999.999.999,05, and the first cent
    // that still respects the 5% ceiling is the next one up.
    expect($minimum)->toBe(950_000_000_000_000)
        ->and($minimum)->toBeLessThan($referenceCents)
        ->and($minimum)->toBeGreaterThan(0)
        ->and(IntegerMoney::minimumAfterDiscount($referenceCents, 0))->toBe($referenceCents)
        ->and(IntegerMoney::minimumAfterDiscount($referenceCents, 10_000))->toBe(0);
});

it('keeps the exact floor for every basis point of a large reference value', function () {
    $referenceCents = 123_456_789_012_345;

    foreach ([1, 37, 250, 500, 1_234, 9_999] as $basisPoints) {
        $minimum = IntegerMoney::minimumAfterDiscount($referenceCents, $basisPoints);

        // The floor is the first cent at or above the exact quotient, proven
        // against BCMath: the integer decomposition must agree with arbitrary
        // precision arithmetic.
        $exact = bcdiv(
            bcmul((string) $referenceCents, (string) (10_000 - $basisPoints), 0),
            '10000',
            10,
        );

        expect(bccomp((string) $minimum, $exact, 10))->toBeGreaterThanOrEqual(0)
            ->and(bccomp((string) ($minimum - 1), $exact, 10))->toBeLessThan(0);
    }
});

it('is undetermined when the sale has no value to compare', function () {
    // `contracts.sale_value` is NOT NULL, so the branch is reached through the
    // resolved-facts entry point, which is what the batch sweep of the next
    // phase will call.
    $saleDate = CarbonImmutable::parse('2026-07-25');

    $result = conformityEvaluator()->evaluate(
        saleDate: $saleDate,
        saleValue: null,
        unitValue: ResolvedUnitValue::fromBase(1, $saleDate, 100_000_000, CarbonImmutable::parse('2026-01-01')),
        policy: ResolvedSalesDiscountPolicy::absent(1, $saleDate),
    );

    expect($result->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($result->reasonWhenUndetermined)->toContain('valor de venda');
});

it('is undetermined for a contract without a sale date or without a unit', function () {
    // Both columns are NOT NULL, so these guards are only reachable before the
    // contract is persisted. They exist so the evaluator can be handed a
    // half-built contract without inventing a verdict for it.
    $withoutSaleDate = new Contract(['sale_value' => '950000.00']);
    $withoutUnit = new Contract(['sale_date' => '2026-07-25', 'sale_value' => '950000.00']);

    $noDate = conformityEvaluator()->forContract($withoutSaleDate);
    $noUnit = conformityEvaluator()->forContract($withoutUnit);

    expect($noDate->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($noDate->reasonWhenUndetermined)->toContain('data de venda')
        ->and($noUnit->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($noUnit->reasonWhenUndetermined)->toContain('unidade');
});

it('is undetermined when the stored policy falls outside the zero to one hundred range', function () {
    // The UI refuses it, but the column holds up to 999,99. A row that got past
    // the form must not crash a portfolio sweep nor invent a floor.
    $contract = unitSoldFor('950000.00', maximumDiscountPercent: null);

    SalesDiscountPolicy::factory()
        ->forConstruction($contract->constructionUnit->construction)
        ->effectiveFrom('2026-01-01')
        ->allowing('150.00')
        ->create();

    $result = conformityEvaluator()->forContract($contract->fresh());

    expect($result->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($result->reasonWhenUndetermined)->toContain('fora da faixa')
        ->and($result->minimumAuthorizedValueCents)->toBeNull();
});

it('exposes the audit figures of an evaluated sale', function () {
    $result = conformityEvaluator()->forContract(unitSoldFor('960000.00'));

    expect($result->toDisplayArray())->toBe([
        'status' => 'Conforme',
        'reference_value' => '1.000.000,00',
        'sale_value' => '960.000,00',
        'authorized_discount_percent' => '5,00%',
        'minimum_authorized_value' => '950.000,00',
        'effective_discount' => 'Desconto de 4,00%',
        'difference_value' => '10.000,00',
        'undetermined_reason' => null,
    ]);
});
