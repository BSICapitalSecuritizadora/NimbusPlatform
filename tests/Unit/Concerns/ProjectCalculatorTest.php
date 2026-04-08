<?php

use App\Concerns\ProjectCalculator;

it('calculates total project cost from mixed numeric inputs', function () {
    expect(ProjectCalculator::calculateCostTotal('R$ 1.000.000,00', '3.000.000,00'))
        ->toBe(4000000.0);
});

it('calculates the total number of units from mixed integer inputs', function () {
    expect(ProjectCalculator::calculateUnitsTotal('15', 20, '10', '55'))->toBe(100);
});

it('calculates sales percentage excluding exchanged units from the sellable base', function () {
    expect(ProjectCalculator::calculateSalesPercentage(15, 20, 10, 55))->toBe(38.89);
});

it('returns zero sales percentage when there are no sellable units', function () {
    expect(ProjectCalculator::calculateSalesPercentage(0, 0, 10, 0))->toBe(0.0);
});

it('calculates work stage percentage based on incurred cost over total cost', function () {
    expect(ProjectCalculator::calculateWorkStagePercentage('1.000.000,00', '4.000.000,00'))
        ->toBe(25.0);
});

it('returns zero work stage percentage when the total cost is zero or negative', function (mixed $costTotal) {
    expect(ProjectCalculator::calculateWorkStagePercentage('1.000,00', $costTotal))->toBe(0.0);
})->with([
    'zero total' => [0],
    'negative total' => [-100],
    'invalid total' => ['foo'],
]);

it('calculates the total expected payment flow', function () {
    expect(ProjectCalculator::calculatePaymentFlowTotal('100.000,10', '200.000,20', '300.000,30'))
        ->toBe(600000.6);
});

it('calculates the total gross sales value', function () {
    expect(ProjectCalculator::calculateSalesValuesTotal('900.000,00', '1.500.000,50', '2.500.000,75'))
        ->toBe(4900001.25);
});

it('rounds recurring decimal monetary fragments before aggregating totals', function () {
    expect(ProjectCalculator::calculatePaymentFlowTotal('33,3333', '33,3333', '33,3333'))->toBe(99.99)
        ->and(ProjectCalculator::calculateSalesValuesTotal('R$ 33,335', null, ''))->toBe(33.34)
        ->and(ProjectCalculator::calculateCostTotal('33.3333', '66.6666'))->toBe(100.0);
});

it('returns zero safely when a percentage calculation would divide by zero', function () {
    expect(ProjectCalculator::calculateSalesPercentage(0, 0, 0, 0))->toBe(0.0)
        ->and(ProjectCalculator::calculateSalesPercentage(0, 0, 15, 0))->toBe(0.0)
        ->and(ProjectCalculator::calculateWorkStagePercentage('33,3333', 0))->toBe(0.0)
        ->and(ProjectCalculator::calculateWorkStagePercentage('33,3333', ''))->toBe(0.0)
        ->and(ProjectCalculator::calculateWorkStagePercentage(null, null))->toBe(0.0);
});
