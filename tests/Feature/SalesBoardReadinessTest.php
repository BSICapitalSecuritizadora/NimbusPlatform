<?php

use App\DTOs\SalesBoards\SalesBoardLegacyComparison;
use App\Enums\ContractStatus;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\SalesBoard;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

function readiness($construction, string $referenceMonth = '2026-07-01')
{
    return app(SalesBoardReadinessService::class)
        ->forConstruction($construction, CarbonImmutable::parse($referenceMonth));
}

it('is ready when every unit falls into a bucket with a known value', function () {
    $construction = DerivationFixture::construction();

    DerivationFixture::unit($construction, '101');

    $financedUnit = DerivationFixture::unit($construction, '102');
    $contract = DerivationFixture::contract($financedUnit, '2026-01-10');
    DerivationFixture::installment($contract, '001', '2026-12-10', '600000.00');

    $report = readiness($construction);

    expect($report->isReady())->toBeTrue()
        ->and($report->blockingIssues)->toBe([])
        ->and($report->metrics['buckets_balance'])->toBeTrue()
        ->and($report->metrics['is_complete'])->toBeTrue();
});

it('blocks a construction with no units', function () {
    $construction = DerivationFixture::construction();

    $report = readiness($construction);

    expect($report->isReady())->toBeFalse()
        ->and(array_keys($report->blockingIssueCounts()))->toContain('NO_CONSTRUCTION_UNITS');
});

it('blocks when a stock unit has no value on the position date', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', null);

    $report = readiness($construction);

    expect($report->isReady())->toBeFalse()
        ->and(array_keys($report->blockingIssueCounts()))->toContain('UNIT_VALUE_MISSING');
});

it('blocks when an occupying contract has no schedule', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    DerivationFixture::contract($unit, '2026-01-10');

    $report = readiness($construction);

    expect($report->isReady())->toBeFalse()
        ->and(array_keys($report->blockingIssueCounts()))->toContain('SETTLEMENT_UNDETERMINED');
});

it('blocks on ambiguous occupancy', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    DerivationFixture::contract($unit, '2026-01-10');
    $overlap = Contract::factory()->make([
        'construction_unit_id' => $unit->id,
        'sale_date' => '2026-02-10',
        'sale_value' => '700000.00',
        'status' => ContractStatus::Cancelled,
        'cancellation_date' => '2026-12-01',
    ]);
    $overlap->construction_id = $construction->id;
    $overlap->saveQuietly();

    $report = readiness($construction);

    expect($report->isReady())->toBeFalse()
        ->and(array_keys($report->blockingIssueCounts()))->toContain('AMBIGUOUS_OCCUPANCY');
});

it('blocks on ambiguous exchanges', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');

    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();
    ConstructionUnitExchange::factory()->forUnit($unit)->effectiveFrom('2026-02-01')->create();

    $report = readiness($construction);

    expect($report->isReady())->toBeFalse()
        ->and(array_keys($report->blockingIssueCounts()))->toContain('AMBIGUOUS_EXCHANGE');
});

it('blocks a contract marked exchanged with no registered source', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101');
    $contract = DerivationFixture::contract($unit, '2026-02-01', status: ContractStatus::Exchanged);
    DerivationFixture::installment($contract, '001', '2026-12-01', '600000.00');

    $report = readiness($construction);

    expect($report->isReady())->toBeFalse()
        ->and(array_keys($report->blockingIssueCounts()))->toContain('EXCHANGE_SOURCE_MISSING');
});

it('blocks a sale of the competence with no policy in force', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');
    $contract = DerivationFixture::contract($unit, '2026-07-10', '950000.00');
    DerivationFixture::installment($contract, '001', '2026-12-10', '950000.00');

    $report = readiness($construction);

    expect($report->isReady())->toBeFalse()
        ->and(array_keys($report->blockingIssueCounts()))->toContain('SALE_DISCOUNT_POLICY_MISSING');
});

it('does not require a policy for an old contract that is merely still financed', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');
    $contract = DerivationFixture::contract($unit, '2024-03-10', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-12-10', '600000.00');

    $report = readiness($construction);

    expect($report->isReady())->toBeTrue()
        ->and($report->blockingIssues)->toBe([]);
});

it('does not block on a non conform sale', function () {
    $construction = DerivationFixture::construction();
    $unit = DerivationFixture::unit($construction, '101', '1000000.00');

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->allowing('5.00')->create();

    $contract = DerivationFixture::contract($unit, '2026-07-10', '900000.00');
    DerivationFixture::installment($contract, '001', '2026-12-10', '900000.00');

    $report = readiness($construction);

    expect($report->isReady())->toBeTrue()
        ->and(array_keys($report->warningCounts()))->toContain('SALE_NON_CONFORM')
        ->and($report->metrics['sales_non_conform'])->toBe(1);
});

it('does not treat a unit without a contract as a problem', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101');

    expect(readiness($construction)->isReady())->toBeTrue();
});

it('reports no legacy position when the construction has no published board', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101');

    $comparison = readiness($construction)->legacyComparison;

    expect($comparison->status)->toBe(SalesBoardLegacyComparison::STATUS_NO_LEGACY_POSITION)
        ->and($comparison->hasLegacyPosition())->toBeFalse()
        ->and($comparison->differences)->toBe([]);
});

it('matches the published board when the derived position agrees with it', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', '500000.00');

    SalesBoard::factory()->forEmissionAndConstruction($construction->emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 1,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '500000.00',
        'financed_value' => '0.00',
        'paid_value' => '0.00',
        'exchanged_value' => '0.00',
    ]);

    $comparison = readiness($construction)->legacyComparison;

    expect($comparison->matches())->toBeTrue()
        ->and($comparison->legacyReferenceMonth)->toBe('07/2026')
        ->and($comparison->differences)->toBe([]);
});

it('reports a difference in quantity or value without invalidating the derivation', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', '500000.00');

    SalesBoard::factory()->forEmissionAndConstruction($construction->emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 3,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '900000.00',
        'financed_value' => '0.00',
        'paid_value' => '0.00',
        'exchanged_value' => '0.00',
    ]);

    $report = readiness($construction);

    expect($report->legacyComparison->status)->toBe(SalesBoardLegacyComparison::STATUS_DIFFERENCE)
        ->and($report->legacyComparison->differences)->toHaveKeys(['stock_units', 'stock_value'])
        ->and($report->legacyComparison->differences['stock_units'])->toBe(['derived' => 1, 'legacy' => 3])
        ->and($report->legacyComparison->differences['stock_value'])->toBe(['derived' => '500.000,00', 'legacy' => '900.000,00'])
        // Divergence is investigation material, never a blocker.
        ->and($report->isReady())->toBeTrue();
});

it('compares against the carried forward published position', function () {
    $construction = DerivationFixture::construction();
    DerivationFixture::unit($construction, '101', '500000.00');

    SalesBoard::factory()->forEmissionAndConstruction($construction->emission, $construction)->create([
        'reference_month' => '2026-04-01',
        'stock_units' => 1,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '500000.00',
        'financed_value' => '0.00',
        'paid_value' => '0.00',
        'exchanged_value' => '0.00',
    ]);

    $comparison = readiness($construction, '2026-07-01')->legacyComparison;

    expect($comparison->hasLegacyPosition())->toBeTrue()
        ->and($comparison->legacyReferenceMonth)->toBe('04/2026')
        ->and($comparison->matches())->toBeTrue();
});
