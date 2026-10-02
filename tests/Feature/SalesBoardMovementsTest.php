<?php

use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\DTOs\SalesBoards\SalesBoardSnapshotMovement;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardUnitClassification;
use App\Enums\SalesPriceConformityStatus;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\SalesDiscountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

it('lists a sale of the competence with its contract code', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-07-10', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    $position = DerivationFixture::derive($construction);

    expect($position->movements->salesCount())->toBe(1)
        ->and($position->movements->sales[0]->contractCode)->toBe($contract->code)
        ->and($position->movements->sales[0]->contractId)->toBe($contract->id)
        ->and($position->movements->sales[0]->saleDate->toDateString())->toBe('2026-07-10');
});

it('keeps a sale that was cancelled in the same month in both lists', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    $contract = DerivationFixture::contract($unit, '2026-07-05', '600000.00', cancellationDate: '2026-07-20', status: ContractStatus::Cancelled);
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    $position = DerivationFixture::derive($construction);

    expect($position->movements->salesCount())->toBe(1)
        ->and($position->movements->cancellationsCount())->toBe(1)
        ->and($position->movements->cancellations[0]->contractCode)->toBe($contract->code)
        ->and($position->movements->cancellations[0]->cancellationDate->toDateString())->toBe('2026-07-20')
        // The unit is back in stock at month end: a movement is not a position.
        ->and(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock);
});

it('derives a settlement of the competence from the transition, not from the status', function () {
    $construction = DerivationFixture::construction();

    $settledInJuly = DerivationFixture::unit($construction, '101');
    $contractA = DerivationFixture::contract($settledInJuly, '2026-01-10', '600000.00');
    DerivationFixture::installment($contractA, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($contractA, '002', '2026-07-15', '300000.00', '2026-07-15', '300000.00');

    $settledEarlier = DerivationFixture::unit($construction, '102');
    $contractB = DerivationFixture::contract($settledEarlier, '2026-01-10', '500000.00');
    DerivationFixture::installment($contractB, '001', '2026-02-10', '500000.00', '2026-02-10', '500000.00');

    $position = DerivationFixture::derive($construction);

    expect($position->movements->settlementsCount())->toBe(1)
        ->and($position->movements->settlements[0]->contractId)->toBe($contractA->id)
        ->and($position->movements->settlements[0]->contractCode)->toBe($contractA->code)
        ->and($position->movements->settlements[0]->installments)->toBe(2)
        // Both are settled at month end, but only one crossed over during the month.
        ->and($position->settledUnits)->toBe(2);
});

it('does not report a settlement in a month where nothing changed', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-02-10', '600000.00', '2026-02-10', '600000.00');

    $position = DerivationFixture::derive($construction, '2026-07-01');

    expect($position->movements->settlementsCount())->toBe(0)
        ->and($position->settledUnits)->toBe(1);
});

it('evaluates a sale of the competence against the policy in force on its sale date', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();

    $contract = DerivationFixture::contract($unit, '2026-07-10', '950000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '950000.00');

    $position = DerivationFixture::derive($construction);
    $sale = $position->movements->sales[0];

    expect($sale->conformity->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($sale->conformity->minimumAuthorizedValueCents)->toBe(95_000_000)
        ->and($position->movements->conformSalesCount())->toBe(1);
});

it('reports a sale one cent below the floor as non conform without blocking the derivation', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();

    $contract = DerivationFixture::contract($unit, '2026-07-10', '949999.99');
    DerivationFixture::installment($contract, '001', '2026-08-10', '949999.99');

    $position = DerivationFixture::derive($construction);

    expect($position->movements->nonConformSalesCount())->toBe(1)
        ->and(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Financed)
        ->and($position->financedUnits)->toBe(1)
        ->and(DerivationFixture::issueCodes($position))->toContain(SalesBoardIssueCode::SaleNonConform->value)
        // A detected irregularity is not missing data.
        ->and($position->hasBlockingIssue())->toBeFalse()
        ->and($position->isComplete())->toBeTrue();
});

it('judges the sale by the facts of its own date, not by later ones', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();
    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-07-20')->allowing('1.00')->create();
    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-20')->worth('1200000.00')->create();

    $contract = DerivationFixture::contract($unit, '2026-07-10', '950000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '950000.00');

    $sale = DerivationFixture::derive($construction)->movements->sales[0];

    expect($sale->conformity->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($sale->conformity->referenceValueCents)->toBe(100_000_000)
        ->and($sale->conformity->authorizedDiscountBasisPoints)->toBe(500);
});

it('blocks when a sale of the competence has no policy or no unit value', function (bool $withPolicy, bool $withValue, string $expectedIssue) {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', $withValue ? '1000000.00' : null);

    if ($withPolicy) {
        SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();
    }

    $contract = DerivationFixture::contract($unit, '2026-07-10', '950000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '950000.00');

    $position = DerivationFixture::derive($construction);

    expect($position->movements->undeterminedSalesCount())->toBe(1)
        ->and(DerivationFixture::issueCodes($position))->toContain($expectedIssue)
        ->and($position->hasBlockingIssue())->toBeTrue();
})->with([
    'no policy' => [false, true, 'SALE_DISCOUNT_POLICY_MISSING'],
    'no unit value on the sale date' => [true, false, 'SALE_UNIT_VALUE_MISSING'],
]);

it('accepts a sale above the table as conform', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();

    $contract = DerivationFixture::contract($unit, '2026-07-10', '1100000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '1100000.00');

    $sale = DerivationFixture::derive($construction)->movements->sales[0];

    expect($sale->conformity->status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($sale->conformity->hasPremium())->toBeTrue();
});

it('does not require a policy for an old contract that is merely still financed', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');

    $contract = DerivationFixture::contract($unit, '2024-03-10', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-12-10', '600000.00');

    $position = DerivationFixture::derive($construction);

    expect($position->financedUnits)->toBe(1)
        ->and($position->financedValueCents)->toBe(60_000_000)
        ->and($position->movements->salesCount())->toBe(0)
        ->and($position->hasBlockingIssue())->toBeFalse()
        ->and($position->isComplete())->toBeTrue();
});

it('closes the inventory across the four buckets when nothing is undetermined', function () {
    $construction = DerivationFixture::construction();

    $stock = DerivationFixture::unit($construction, '101');

    $financedUnit = DerivationFixture::unit($construction, '102');
    $financed = DerivationFixture::contract($financedUnit, '2026-01-10', '600000.00');
    DerivationFixture::installment($financed, '001', '2026-12-10', '600000.00');

    $settledUnit = DerivationFixture::unit($construction, '103');
    $settled = DerivationFixture::contract($settledUnit, '2026-01-10', '700000.00');
    DerivationFixture::installment($settled, '001', '2026-02-10', '700000.00', '2026-02-10', '700000.00');

    $exchangedUnit = DerivationFixture::unit($construction, '104');
    ConstructionUnitExchange::factory()->forUnit($exchangedUnit)->effectiveFrom('2026-01-01')->worth('450000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect($position->unitsTotal)->toBe(4)
        ->and($position->stockUnits)->toBe(1)
        ->and($position->financedUnits)->toBe(1)
        ->and($position->settledUnits)->toBe(1)
        ->and($position->exchangedUnits)->toBe(1)
        ->and($position->undeterminedUnits)->toBe(0)
        ->and($position->bucketsBalance())->toBeTrue()
        ->and($position->isComplete())->toBeTrue()
        ->and($position->stockValueCents)->toBe(50_000_000)
        ->and($position->financedValueCents)->toBe(60_000_000)
        ->and($position->settledValueCents)->toBe(70_000_000)
        ->and($position->exchangedValueCents)->toBe(45_000_000);
});

it('still closes the inventory when some units are undetermined', function () {
    $construction = DerivationFixture::construction();

    DerivationFixture::unit($construction, '101');

    $noSchedule = DerivationFixture::unit($construction, '102');
    DerivationFixture::contract($noSchedule, '2026-01-10');

    $position = DerivationFixture::derive($construction);

    expect($position->unitsTotal)->toBe(2)
        ->and($position->undeterminedUnits)->toBe(1)
        ->and($position->bucketsBalance())->toBeTrue()
        ->and($position->isComplete())->toBeFalse();
});

it('does not report a settlement for a contract cancelled in the month together with its open installments', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    // The distrato cancels the open installments on the same day: only the paid one is left.
    $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00', cancellationDate: '2026-07-10', status: ContractStatus::Cancelled);
    DerivationFixture::installment($contract, '001', '2026-02-10', '200000.00', '2026-02-10', '200000.00');
    DerivationFixture::installment($contract, '002', '2026-08-10', '200000.00', cancellationDate: '2026-07-10');
    DerivationFixture::installment($contract, '003', '2026-09-10', '200000.00', cancellationDate: '2026-07-10');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and($position->movements->cancellationsCount())->toBe(1)
        ->and($position->movements->settlementsCount())->toBe(0);
});

it('does not report in a later month the settlement of a contract cancelled earlier', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00', cancellationDate: '2026-06-20', status: ContractStatus::Cancelled);
    DerivationFixture::installment($contract, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($contract, '002', '2026-08-10', '300000.00', cancellationDate: '2026-07-05');

    $june = DerivationFixture::derive($construction, '2026-06-01');
    $july = DerivationFixture::derive($construction, '2026-07-01');

    expect($june->movements->cancellationsCount())->toBe(1)
        ->and($june->movements->settlementsCount())->toBe(0)
        ->and($july->movements->cancellationsCount())->toBe(0)
        ->and($july->movements->settlementsCount())->toBe(0);
});

it('keeps only the new contract when the unit is resold in the month of the distrato', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    $old = DerivationFixture::contract($unit, '2026-01-10', '600000.00', cancellationDate: '2026-07-10', status: ContractStatus::Cancelled);
    DerivationFixture::installment($old, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($old, '002', '2026-08-10', '300000.00', cancellationDate: '2026-07-10');

    $new = DerivationFixture::contract($unit, '2026-07-15', '650000.00');
    DerivationFixture::installment($new, '001', '2026-07-20', '650000.00', '2026-07-20', '650000.00');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->contractId)->toBe($new->id)
        ->and($position->movements->settlementsCount())->toBe(1)
        ->and($position->movements->settlements[0]->contractId)->toBe($new->id);
});

it('does not freeze a false settlement in the snapshot when the distrato cancels the open installments', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00', cancellationDate: '2026-07-10', status: ContractStatus::Cancelled);
    DerivationFixture::installment($contract, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($contract, '002', '2026-08-10', '300000.00', cancellationDate: '2026-07-10');

    $snapshot = SalesBoardSnapshot::fromDerivedPosition(DerivationFixture::derive($construction));

    $types = collect($snapshot->movements)
        ->filter(fn (SalesBoardSnapshotMovement $movement): bool => $movement->contractId === $contract->id)
        ->map(fn (SalesBoardSnapshotMovement $movement): SalesBoardMovementType => $movement->type)
        ->values()
        ->all();

    expect($types)->toBe([SalesBoardMovementType::Cancellation]);
});

it('does not report the settlement of the contract on an exchanged line', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    $contract = DerivationFixture::contract($unit, '2026-02-01', '450000.00', status: ContractStatus::Exchanged);
    DerivationFixture::installment($contract, '001', '2026-07-10', '450000.00', '2026-07-10', '450000.00');

    ConstructionUnitExchange::factory()->forUnit($unit)->forContract($contract)
        ->effectiveFrom('2026-02-01')->worth('450000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
        ->and($position->movements->settlementsCount())->toBe(0);
});

it('does not treat the contract named by the exchange in force on its date as a sale of the month', function (bool $withPolicy) {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '500000.00');

    if ($withPolicy) {
        SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('10.00')->create();
    }

    // Signed well below the table: as a sale it would be non conform (or blocked without a policy).
    $contract = DerivationFixture::contract($unit, '2026-07-05', '300000.00', status: ContractStatus::Exchanged);

    ConstructionUnitExchange::factory()->forUnit($unit)->forContract($contract)
        ->effectiveFrom('2026-07-05')->worth('300000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
        ->and($position->movements->salesCount())->toBe(0)
        ->and(DerivationFixture::issueCodes($position))->not->toContain(SalesBoardIssueCode::SaleNonConform->value)
        ->and(DerivationFixture::issueCodes($position))->not->toContain(SalesBoardIssueCode::SaleDiscountPolicyMissing->value)
        ->and($position->hasBlockingIssue())->toBeFalse()
        ->and($position->isComplete())->toBeTrue();
})->with([
    'with a policy in force' => [true],
    'without any policy' => [false],
]);

it('does not treat an exchanged contract on an exchanged line as a sale, even when the exchange names no contract', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '500000.00');

    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('450000.00')->create();

    DerivationFixture::contract($unit, '2026-07-05', '500000.00', status: ContractStatus::Exchanged);

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
        ->and($position->movements->salesCount())->toBe(0)
        ->and($position->isComplete())->toBeTrue();
});

it('still evaluates a sale of the month on a unit that is not exchanged', function () {
    $construction = DerivationFixture::construction();
    $exchangedUnit = DerivationFixture::unit($construction, '101', '500000.00');
    $soldUnit = DerivationFixture::unit($construction, '102', '500000.00');

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('10.00')->create();

    $exchangeContract = DerivationFixture::contract($exchangedUnit, '2026-07-05', '300000.00', status: ContractStatus::Exchanged);
    ConstructionUnitExchange::factory()->forUnit($exchangedUnit)->forContract($exchangeContract)
        ->effectiveFrom('2026-07-05')->worth('300000.00')->create();

    $sale = DerivationFixture::contract($soldUnit, '2026-07-10', '440000.00');
    DerivationFixture::installment($sale, '001', '2026-08-10', '440000.00');

    $position = DerivationFixture::derive($construction);

    expect($position->movements->salesCount())->toBe(1)
        ->and($position->movements->sales[0]->contractId)->toBe($sale->id)
        ->and($position->movements->nonConformSalesCount())->toBe(1);
});

describe('distrato e permuta nos movimentos', function () {
    it('does not report a settlement in the month before a distrato whose open installments were cancelled earlier', function () {
        $construction = DerivationFixture::activeConstruction();
        $unit = DerivationFixture::unit($construction, '101');
        $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00', cancellationDate: '2026-07-05', status: ContractStatus::Cancelled);
        DerivationFixture::installment($contract, '001', '2026-02-10', '200000.00', '2026-02-10', '200000.00');
        DerivationFixture::installment($contract, '002', '2026-08-10', '200000.00', cancellationDate: '2026-06-25');
        DerivationFixture::installment($contract, '003', '2026-09-10', '200000.00', cancellationDate: '2026-06-25');

        $june = DerivationFixture::derive($construction, '2026-06-01');
        $july = DerivationFixture::derive($construction, '2026-07-01');
        $juneLine = DerivationFixture::lineFor($june, $unit);

        expect($juneLine->classification)->toBe(SalesBoardUnitClassification::Financed)
            ->and($juneLine->settlementInstallmentsPaid)->toBe(1)
            ->and($juneLine->settlementInstallmentsTotal)->toBe(3)
            ->and($june->movements->settlementsCount())->toBe(0)
            ->and($july->movements->cancellationsCount())->toBe(1)
            ->and($july->movements->cancellations[0]->contractId)->toBe($contract->id)
            ->and(DerivationFixture::lineFor($july, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock);
    });

    it('keeps reporting the settlement of a live contract renegotiated in the month', function () {
        $construction = DerivationFixture::activeConstruction();
        $unit = DerivationFixture::unit($construction, '101');
        $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00');
        DerivationFixture::installment($contract, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
        DerivationFixture::installment($contract, '002', '2026-08-10', '300000.00', cancellationDate: '2026-06-20');
        DerivationFixture::installment($contract, 'R01', '2026-06-20', '290000.00', '2026-06-20', '290000.00');

        $june = DerivationFixture::derive($construction, '2026-06-01');

        expect(DerivationFixture::lineFor($june, $unit)->classification)->toBe(SalesBoardUnitClassification::Settled)
            ->and($june->movements->settlementsCount())->toBe(1)
            ->and($june->movements->settlements[0]->contractId)->toBe($contract->id);
    });

    it('does not list the distrato of the exchange contract among the distratos of the month', function (string $exchange) {
        $construction = DerivationFixture::activeConstruction();
        $unit = DerivationFixture::unit($construction, '101');
        $contract = DerivationFixture::contract($unit, '2026-02-01', '450000.00', cancellationDate: '2026-07-15', status: ContractStatus::Cancelled);

        $factory = ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-02-01')->endedOn('2026-07-15')->worth('450000.00');

        ($exchange === 'com contrato' ? $factory->forContract($contract) : $factory)->create();

        $position = DerivationFixture::derive($construction, '2026-07-01');

        expect($position->movements->cancellationsCount())->toBe(0)
            ->and($position->movements->salesCount())->toBe(0)
            ->and(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock);
    })->with(['com contrato', 'sem contrato, vigente na véspera do distrato']);

    it('still lists the distrato of a sale contract of the same unit', function () {
        $construction = DerivationFixture::activeConstruction();
        $unit = DerivationFixture::unit($construction, '101');

        $exchangeContract = DerivationFixture::contract($unit, '2026-01-05', '450000.00', cancellationDate: '2026-02-01', status: ContractStatus::Cancelled);
        ConstructionUnitExchange::factory()->forUnit($unit)->forContract($exchangeContract)->effectiveFrom('2026-01-05')->endedOn('2026-02-01')->worth('450000.00')->create();

        // Depois da permuta, a unidade foi vendida e a venda distratada no mês.
        $sale = DerivationFixture::contract($unit, '2026-03-10', '600000.00', cancellationDate: '2026-07-20', status: ContractStatus::Cancelled);
        DerivationFixture::installment($sale, '001', '2026-12-10', '600000.00');

        $position = DerivationFixture::derive($construction, '2026-07-01');

        expect($position->movements->cancellationsCount())->toBe(1)
            ->and($position->movements->cancellations[0]->contractId)->toBe($sale->id);
    });
});
