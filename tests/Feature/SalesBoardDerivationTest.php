<?php

use App\Enums\ContractSettlementState;
use App\Enums\ContractStatus;
use App\Enums\ResolvedUnitValueSource;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardUnitClassification;
use App\Models\ConstructionUnitValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

it('classifies a unit with no contract as stock and prices it from the unit value', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '500000.00');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and($position->stockUnits)->toBe(1)
        ->and($position->stockValueCents)->toBe(50_000_000)
        ->and(DerivationFixture::lineFor($position, $unit)->unitReferenceValueSource)->toBe(ResolvedUnitValueSource::Base)
        ->and($position->unitsTotal)->toBe(1);
});

it('prices stock from the value history when one is in force', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '500000.00');

    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-06-01')->worth('550000.00')->create();
    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-09-01')->worth('900000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect($position->stockValueCents)->toBe(55_000_000)
        ->and(DerivationFixture::lineFor($position, $unit)->unitReferenceValueSource)->toBe(ResolvedUnitValueSource::History);
});

it('never publishes a partial stock value when one stock unit has no value', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', '500000.00');
    $priceless = DerivationFixture::unit($construction, '102', null);

    $position = DerivationFixture::derive($construction);

    expect($position->stockUnits)->toBe(2)
        ->and($position->stockValueCents)->toBeNull()
        ->and($position->isComplete())->toBeFalse()
        ->and(DerivationFixture::lineFor($position, $priceless)->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and(collect($position->issues)->pluck('code'))->toContain(SalesBoardIssueCode::UnitValueMissing);
});

it('classifies an occupied unit with an outstanding installment as financed', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-03-10', '600000.00');

    DerivationFixture::installment($contract, '001', '2026-04-10', '300000.00', '2026-04-10', '300000.00');
    DerivationFixture::installment($contract, '002', '2026-08-10', '300000.00');

    $position = DerivationFixture::derive($construction);
    $line = DerivationFixture::lineFor($position, $unit);

    expect($line->classification)->toBe(SalesBoardUnitClassification::Financed)
        ->and($line->settlementState)->toBe(ContractSettlementState::Outstanding)
        ->and($position->financedUnits)->toBe(1)
        ->and($position->financedValueCents)->toBe(60_000_000)
        ->and($position->stockUnits)->toBe(0);
});

it('keeps a defaulted contract with no future installment as financed', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-01-10');

    DerivationFixture::installment($contract, '001', '2026-02-10', '600000.00');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Financed);
});

it('keeps a partially paid installment as financed', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-01-10');

    DerivationFixture::installment($contract, '001', '2026-02-10', '600000.00', '2026-02-10', '599999.99');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Financed);
});

it('ignores the current contract status and answers for the queried date', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    // Marked settled today, but the payment only lands in August.
    $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00', status: ContractStatus::Settled);
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00', '2026-08-10', '600000.00');

    $july = DerivationFixture::derive($construction, '2026-07-01');
    $august = DerivationFixture::derive($construction, '2026-08-01');

    expect(DerivationFixture::lineFor($july, $unit)->classification)->toBe(SalesBoardUnitClassification::Financed)
        ->and(DerivationFixture::lineFor($august, $unit)->classification)->toBe(SalesBoardUnitClassification::Settled);
});

it('classifies a fully paid contract as settled and prices it from the sale value', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00');

    DerivationFixture::installment($contract, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($contract, '002', '2026-03-10', '300000.00', '2026-03-10', '350000.00');

    $position = DerivationFixture::derive($construction);
    $line = DerivationFixture::lineFor($position, $unit);

    expect($line->classification)->toBe(SalesBoardUnitClassification::Settled)
        ->and($line->settlementInstallmentsPaid)->toBe(2)
        ->and($position->settledUnits)->toBe(1)
        ->and($position->settledValueCents)->toBe(60_000_000);
});

it('treats a contract with no valid installments as undetermined, never settled', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    DerivationFixture::contract($unit, '2026-01-10');

    $position = DerivationFixture::derive($construction);
    $line = DerivationFixture::lineFor($position, $unit);

    expect($line->classification)->toBe(SalesBoardUnitClassification::Undetermined)
        ->and($line->settlementState)->toBe(ContractSettlementState::Undetermined)
        ->and($position->settledUnits)->toBe(0)
        ->and($position->undeterminedUnits)->toBe(1)
        ->and(collect($position->issues)->pluck('code'))->toContain(SalesBoardIssueCode::SettlementUndetermined);
});

it('counts an installment cancelled after the date and ignores one cancelled before it', function () {
    $construction = DerivationFixture::construction();

    $stillOwed = DerivationFixture::unit($construction, '101');
    $contractA = DerivationFixture::contract($stillOwed, '2026-01-10');
    DerivationFixture::installment($contractA, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($contractA, '002', '2026-03-10', '300000.00', cancellationDate: '2026-09-01');

    $settled = DerivationFixture::unit($construction, '102');
    $contractB = DerivationFixture::contract($settled, '2026-01-10');
    DerivationFixture::installment($contractB, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($contractB, '002', '2026-03-10', '300000.00', cancellationDate: '2026-04-01');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $stillOwed)->classification)->toBe(SalesBoardUnitClassification::Financed)
        ->and(DerivationFixture::lineFor($position, $settled)->classification)->toBe(SalesBoardUnitClassification::Settled);
});

it('ignores a soft deleted installment', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-01-10');

    DerivationFixture::installment($contract, '001', '2026-02-10', '300000.00', '2026-02-10', '300000.00');
    DerivationFixture::installment($contract, '002', '2026-03-10', '300000.00')->delete();

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Settled);
});

it('does not let a schedule that disagrees with the sale value change the settlement', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-01-10', '600000.00');

    DerivationFixture::installment($contract, '001', '2026-02-10', '100000.00', '2026-02-10', '100000.00');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Settled)
        ->and($position->settledValueCents)->toBe(60_000_000);
});
