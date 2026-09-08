<?php

use App\Enums\ContractStatus;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardUnitClassification;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Support\Contracts\ContractOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

it('holds the unit from the sale date up to but not including the cancellation', function (string $date, bool $expected) {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-03-10', cancellationDate: '2026-06-20', status: ContractStatus::Cancelled);

    expect(ContractOccupancy::occupiesAt($contract, CarbonImmutable::parse($date)))->toBe($expected);
})->with([
    'day before the sale' => ['2026-03-09', false],
    'sale day' => ['2026-03-10', true],
    'mid contract' => ['2026-05-01', true],
    'day before the distrato' => ['2026-06-19', true],
    'distrato day' => ['2026-06-20', false],
    'after the distrato' => ['2026-06-21', false],
]);

it('never counts a resale as two units on the day the unit changes hands', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    $first = DerivationFixture::contract($unit, '2026-03-10', '600000.00', cancellationDate: '2026-06-20', status: ContractStatus::Cancelled);
    DerivationFixture::installment($first, '001', '2026-04-10', '600000.00');

    $second = DerivationFixture::contract($unit, '2026-06-20', '700000.00');
    DerivationFixture::installment($second, '001', '2026-07-10', '700000.00');

    $may = DerivationFixture::derive($construction, '2026-05-01');
    $june = DerivationFixture::derive($construction, '2026-06-01');
    $july = DerivationFixture::derive($construction, '2026-07-01');

    expect($may->unitsTotal)->toBe(1)
        ->and(DerivationFixture::lineFor($may, $unit)->contractId)->toBe($first->id)
        ->and($june->unitsTotal)->toBe(1)
        ->and(DerivationFixture::lineFor($june, $unit)->contractId)->toBe($second->id)
        ->and($june->financedUnits)->toBe(1)
        ->and($june->financedValueCents)->toBe(70_000_000)
        ->and($july->financedUnits)->toBe(1)
        ->and($july->undeterminedUnits)->toBe(0);
});

it('leaves the unit in stock before the first sale', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    DerivationFixture::contract($unit, '2026-06-10');

    $position = DerivationFixture::derive($construction, '2026-05-01');

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and(DerivationFixture::lineFor($position, $unit)->contractId)->toBeNull();
});

it('returns the unit to stock after a distrato', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-03-10', cancellationDate: '2026-06-20', status: ContractStatus::Cancelled);
    DerivationFixture::installment($contract, '001', '2026-04-10', '600000.00');

    $position = DerivationFixture::derive($construction, '2026-07-01');

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and($position->stockUnits)->toBe(1);
});

it('refuses to pick a winner when two contracts occupy the same unit', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    // Legacy overlap: written straight to the table, past the single-live-contract guard.
    $first = DerivationFixture::contract($unit, '2026-01-10');
    $second = Contract::factory()->make([
        'construction_unit_id' => $unit->id,
        'sale_date' => '2026-02-10',
        'sale_value' => '700000.00',
        'status' => ContractStatus::Cancelled,
        'cancellation_date' => '2026-12-01',
    ]);
    $second->construction_id = $construction->id;
    $second->saveQuietly();

    $position = DerivationFixture::derive($construction);
    $line = DerivationFixture::lineFor($position, $unit);

    expect($line->classification)->toBe(SalesBoardUnitClassification::Undetermined)
        ->and($position->undeterminedUnits)->toBe(1)
        ->and($position->stockUnits)->toBe(0)
        ->and($position->financedUnits)->toBe(0)
        ->and($position->unitsTotal)->toBe(1)
        ->and(collect($position->issues)->pluck('code'))->toContain(SalesBoardIssueCode::AmbiguousOccupancy)
        ->and($line->contractId)->toBeNull();
});

it('classifies a baseline exchange without a contract as exchanged', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('450000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
        ->and($position->exchangedUnits)->toBe(1)
        ->and($position->exchangedValueCents)->toBe(45_000_000)
        ->and($position->stockUnits)->toBe(0);
});

it('classifies an exchange whose contract is the occupant as exchanged', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-02-01', status: ContractStatus::Exchanged);

    ConstructionUnitExchange::factory()->forUnit($unit)->forContract($contract)
        ->effectiveFrom('2026-02-01')->worth('450000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
        ->and($position->exchangedValueCents)->toBe(45_000_000)
        ->and($position->financedUnits)->toBe(0);
});

it('does not apply an exchange before it takes effect nor on the day it ends', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    ConstructionUnitExchange::factory()->forUnit($unit)
        ->effectiveFrom('2026-06-01')->endedOn('2026-07-31')->worth('450000.00')->create();

    $before = DerivationFixture::derive($construction, '2026-05-01');
    $during = DerivationFixture::derive($construction, '2026-06-01');
    $onEndDate = DerivationFixture::derive($construction, '2026-07-01');

    expect(DerivationFixture::lineFor($before, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and(DerivationFixture::lineFor($during, $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
        // The position date of 07/2026 is exactly 31/07, the day the exchange ends.
        ->and(DerivationFixture::lineFor($onEndDate, $unit)->classification)->toBe(SalesBoardUnitClassification::Stock);
});

it('refuses to choose between two effective exchanges', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('400000.00')->create();
    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-02-01')->worth('450000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Undetermined)
        ->and($position->exchangedUnits)->toBe(0)
        ->and(collect($position->issues)->pluck('code'))->toContain(SalesBoardIssueCode::AmbiguousExchange);
});

it('flags an exchange that points at a different contract than the occupant', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    $other = DerivationFixture::contract(DerivationFixture::unit($construction, '999'), '2026-01-01');
    $occupant = DerivationFixture::contract($unit, '2026-02-01');
    DerivationFixture::installment($occupant, '001', '2026-03-01', '600000.00');

    ConstructionUnitExchange::factory()->forUnit($unit)->forContract($other)
        ->effectiveFrom('2026-01-01')->worth('450000.00')->create();

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Undetermined)
        ->and(collect($position->issues)->pluck('code'))->toContain(SalesBoardIssueCode::ExchangeOccupancyConflict);
});

it('flags a contract marked exchanged with no registered exchange, without classifying it', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-02-01', status: ContractStatus::Exchanged);
    DerivationFixture::installment($contract, '001', '2026-03-01', '600000.00');

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Financed)
        ->and($position->exchangedUnits)->toBe(0)
        ->and(collect($position->issues)->pluck('code'))->toContain(SalesBoardIssueCode::ExchangeSourceMissing);
});
