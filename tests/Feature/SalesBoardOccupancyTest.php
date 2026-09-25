<?php

use App\Enums\ContractStatus;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardUnitClassification;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Services\SalesBoards\SalesBoardDerivationService;
use App\Support\Contracts\ContractOccupancy;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
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

it('flags an exchange without a contract when an ordinary sale occupies the unit', function (ContractStatus $status) {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('450000.00')->create();

    $sale = DerivationFixture::contract($unit, '2026-03-01', '600000.00', status: $status);
    DerivationFixture::installment($sale, '001', '2026-04-01', '600000.00');

    $position = DerivationFixture::derive($construction);
    $line = DerivationFixture::lineFor($position, $unit);

    expect($line->classification)->toBe(SalesBoardUnitClassification::Undetermined)
        ->and($line->contractId)->toBe($sale->id)
        ->and($position->exchangedUnits)->toBe(0)
        ->and($position->exchangedValueCents)->toBe(0)
        ->and(collect($position->issues)->pluck('code'))->toContain(SalesBoardIssueCode::ExchangeOccupancyConflict)
        ->and($position->hasBlockingIssue())->toBeTrue()
        ->and($position->isComplete())->toBeFalse();
})->with([
    'active sale' => [ContractStatus::Active],
    'settled sale' => [ContractStatus::Settled],
]);

it('explains both variants of the exchange occupancy conflict in the hint', function () {
    $hint = SalesBoardIssuePresenter::describe([SalesBoardIssueCode::ExchangeOccupancyConflict->value])[0]['hint'];

    expect($hint)->toContain('aponta para outro contrato')
        ->and($hint)->toContain('não tem contrato e a unidade está com uma venda comum')
        ->and($hint)->toContain('leve o caso à Gestão');
});

it('keeps an exchange without a contract as exchanged when its occupant is the exchange contract', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('450000.00')->create();
    DerivationFixture::contract($unit, '2026-01-01', '450000.00', status: ContractStatus::Exchanged);

    $position = DerivationFixture::derive($construction);

    expect(DerivationFixture::lineFor($position, $unit)->classification)->toBe(SalesBoardUnitClassification::Exchanged)
        ->and($position->exchangedValueCents)->toBe(45_000_000)
        ->and($position->hasBlockingIssue())->toBeFalse();
});

/**
 * A unidade vendida em A passa para B por uma escrita que não salva o
 * contrato: `contracts.construction_id` continua apontando para A.
 *
 * @return array{0: Construction, 1: Construction, 2: ConstructionUnit, 3: Contract}
 */
function movedSoldUnit(string $saleDate = '2026-03-10'): array
{
    $first = DerivationFixture::construction();
    $second = Construction::factory()->create(['emission_id' => $first->emission_id]);

    $moved = DerivationFixture::unit($first, '101');
    DerivationFixture::unit($first, '102');
    DerivationFixture::unit($second, '201');

    $contract = DerivationFixture::contract($moved, $saleDate, '600000.00');
    DerivationFixture::installment($contract, '001', '2026-12-10', '600000.00');

    ConstructionUnit::query()->whereKey($moved->id)->update(['construction_id' => $second->id]);

    return [$first, $second, $moved->fresh(), $contract->fresh()];
}

it('refuses to turn a sold unit moved to another development into stock', function () {
    [$first, $second, $moved, $contract] = movedSoldUnit();

    expect((int) $contract->construction_id)->toBe($first->id);

    $target = DerivationFixture::derive($second);
    $line = DerivationFixture::lineFor($target, $moved);

    expect($line->classification)->toBe(SalesBoardUnitClassification::Undetermined)
        ->and($line->contractId)->toBe($contract->id)
        ->and($target->stockUnits)->toBe(1)
        ->and(collect($target->issues)->pluck('code'))->toContain(SalesBoardIssueCode::UnitConstructionMismatch)
        ->and($target->isComplete())->toBeFalse();
});

it('does not let the development the contract points to lose it in silence', function () {
    [$first, , , $contract] = movedSoldUnit();

    $source = DerivationFixture::derive($first);
    $mismatch = collect($source->issues)->firstWhere('code', SalesBoardIssueCode::UnitConstructionMismatch);

    expect($source->financedUnits)->toBe(0)
        ->and($mismatch)->not->toBeNull()
        ->and($mismatch->contractId)->toBe($contract->id)
        ->and($source->hasBlockingIssue())->toBeTrue()
        ->and($source->isComplete())->toBeFalse();
});

it('flags the moved unit on both sides when the emission is derived in one batch', function () {
    [$first, $second] = movedSoldUnit('2026-07-10');

    $positions = app(SalesBoardDerivationService::class)
        ->deriveForConstructions([$first, $second], CarbonImmutable::parse('2026-07-01'));

    expect(collect($positions[$first->id]->issues)->pluck('code'))->toContain(SalesBoardIssueCode::UnitConstructionMismatch)
        ->and(collect($positions[$second->id]->issues)->pluck('code'))->toContain(SalesBoardIssueCode::UnitConstructionMismatch)
        ->and($positions[$first->id]->isComplete())->toBeFalse()
        ->and($positions[$second->id]->isComplete())->toBeFalse();
});

it('does not flag an old distrato of a moved unit that weighs on no movement of the month', function () {
    $first = DerivationFixture::construction();
    $second = Construction::factory()->create(['emission_id' => $first->emission_id]);
    $moved = DerivationFixture::unit($first, '101');

    $old = DerivationFixture::contract($moved, '2025-03-10', '600000.00', cancellationDate: '2025-06-20', status: ContractStatus::Cancelled);
    DerivationFixture::installment($old, '001', '2025-04-10', '600000.00');

    ConstructionUnit::query()->whereKey($moved->id)->update(['construction_id' => $second->id]);

    $source = DerivationFixture::derive($first);
    $target = DerivationFixture::derive($second);

    expect(collect($source->issues)->pluck('code'))->not->toContain(SalesBoardIssueCode::UnitConstructionMismatch)
        ->and(collect($target->issues)->pluck('code'))->not->toContain(SalesBoardIssueCode::UnitConstructionMismatch)
        ->and(DerivationFixture::lineFor($target, $moved)->classification)->toBe(SalesBoardUnitClassification::Stock);
});
