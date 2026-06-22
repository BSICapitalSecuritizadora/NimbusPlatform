<?php

use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Enums\PuValidationStatus;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Domain\PuCalculator\Services\PuValidationService;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a grouped validation report with first divergence and largest differences by field', function () {
    $spreadsheetPath = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword('TROUPE');
    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];
    $emission = Emission::factory()->create([
        'type' => 'CR',
        'status' => 'active',
    ]);

    persistReferenceRowsForHomologation($emission, $referenceRows);

    EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->whereDate('curve_date', $referenceRows[0]->date)
        ->update([
            'updated_unit_value' => '998.0000000000000000',
            'factor_di' => '1.1111111111111111',
        ]);

    $report = app(PuValidationService::class)->handle(
        $emission,
        $spreadsheetPath,
        'v1',
        PuValidationMode::DisplayScale,
    );

    expect($report->status)->toBe(PuValidationStatus::Rejected)
        ->and($report->totalDivergences)->toBeGreaterThanOrEqual(1)
        ->and($report->totalFieldDivergences)->toBeGreaterThanOrEqual(2)
        ->and($report->firstDivergenceDate?->toDateString())->toBe($referenceRows[0]->date->toDateString())
        ->and($report->largestDifferencesByField)->toHaveKeys(['pu_updated', 'factor_di'])
        ->and(($report->divergenceCountByField['pu_updated'] ?? 0))->toBeGreaterThanOrEqual(1)
        ->and($report->mode)->toBe(PuValidationMode::DisplayScale)
        ->and($report->largestDifferencesByField['pu_updated']->possibleCause)->not()->toBeNull();
});

function persistReferenceRowsForHomologation(Emission $emission, array $referenceRows, string $calculationVersion = 'v1'): void
{
    $timestamp = now();
    $rows = array_map(function ($row) use ($emission, $timestamp, $calculationVersion): array {
        $quantity = $row->quantity ?? '0.0000';
        $interestPaymentUnitValue = $quantity !== '0.0000' && $row->paymentInterestTotal !== null
            ? bcdiv($row->paymentInterestTotal, $quantity, 16)
            : '0.0000000000000000';
        $paymentTotalUnitValue = $quantity !== '0.0000' && $row->paymentTotalValue !== null
            ? bcdiv($row->paymentTotalValue, $quantity, 16)
            : '0.0000000000000000';

        return [
            'emission_id' => $emission->id,
            'curve_date' => $row->date->toDateString(),
            'calculation_version' => $calculationVersion,
            'is_business_day' => true,
            'unit_base_value' => $row->unitBaseValue ?? $row->residualUnitValue ?? '0.0000000000000000',
            'unit_corrected_value' => $row->correctedUnitValue ?? $row->residualUnitValue ?? '0.0000000000000000',
            'factor_di' => $row->factorDi ?? '1.0000000000000000',
            'factor_di_accumulated' => $row->factorDiAccumulated ?? '1.0000000000000000',
            'factor_spread' => $row->factorSpread ?? '1.0000000000000000',
            'factor_spread_di' => $row->factorSpreadDi ?? '1.0000000000000000',
            'interest_real_unit_value' => $row->interestRealUnitValue ?? '0.0000000000000000',
            'updated_unit_value' => $row->updatedUnitValue ?? '0.0000000000000000',
            'amortization_ratio' => '0.0000000000000000',
            'amortization_unit_value' => $row->amortizationUnitValue ?? '0.0000000000000000',
            'amortization_value' => bcmul($row->amortizationUnitValue ?? '0.0000000000000000', $quantity, 16),
            'residual_unit_value' => $row->residualUnitValue ?? '0.0000000000000000',
            'quantity' => $quantity,
            'total_value' => $row->totalValue ?? '0.0000000000000000',
            'interest_payment_unit_value' => $interestPaymentUnitValue,
            'interest_payment_value' => $row->paymentInterestTotal ?? '0.0000000000000000',
            'payment_total_unit_value' => $paymentTotalUnitValue,
            'payment_total_value' => $row->paymentTotalValue ?? '0.0000000000000000',
            'dup_correction' => $row->dupCorrection,
            'dut_correction' => $row->dutCorrection,
            'dup_interest' => $row->dupInterest,
            'dut_interest' => $row->dutInterest,
            'index_rate_date' => $row->indexRateDate?->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'event_original_date' => $row->eventOriginalDate?->toDateString(),
            'event_effective_date' => $row->eventDueDate?->toDateString(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }, $referenceRows);

    foreach (array_chunk($rows, 500) as $chunk) {
        EmissionPuDailyCurve::query()->insert($chunk);
    }
}
