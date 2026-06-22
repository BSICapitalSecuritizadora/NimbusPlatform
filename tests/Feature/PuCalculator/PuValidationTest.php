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

it('approves the mirrored AMANI reference curve in display-scale mode', function () {
    $keyword = 'AMANI';
    $expectedRowCount = 1810;
    $spreadsheetPath = sampleSpreadsheetPath($keyword);
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];

    persistReferenceRows($emission, $referenceRows);

    $report = app(PuValidationService::class)->handle(
        $emission,
        $spreadsheetPath,
        'v1',
        PuValidationMode::DisplayScale,
    );

    expect($report->sheetName)->toBe('PuDiario')
        ->and($report->totalRowsCompared)->toBe($expectedRowCount)
        ->and($report->totalDivergences)->toBe(0)
        ->and($report->totalFieldDivergences)->toBe(0)
        ->and($report->largestPuDifference)->toBe('0.000000')
        ->and($report->largestTotalValueDifference)->toBe('0.000000')
        ->and($report->largestPaymentDifference)->toBe('0.000000')
        ->and($report->mode)->toBe(PuValidationMode::DisplayScale)
        ->and($report->status)->toBe(PuValidationStatus::Approved);
});

it('reports divergences when the generated curve does not match the TROUPE workbook', function () {
    $spreadsheetPath = sampleSpreadsheetPath('TROUPE');
    $emission = Emission::factory()->create([
        'type' => 'CR',
        'status' => 'active',
    ]);

    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];

    persistReferenceRows($emission, $referenceRows);

    EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->whereDate('curve_date', $referenceRows[0]->date)
        ->update([
            'updated_unit_value' => '999.0000000000000000',
        ]);

    $report = app(PuValidationService::class)->handle(
        $emission,
        $spreadsheetPath,
        'v1',
        PuValidationMode::DisplayScale,
    );

    expect($report->status)->toBe(PuValidationStatus::Rejected)
        ->and($report->totalDivergences)->toBeGreaterThanOrEqual(1)
        ->and($report->totalFieldDivergences)->toBeGreaterThanOrEqual(1)
        ->and(bccomp($report->largestPuDifference, '0', 6))->toBe(1)
        ->and(($report->divergenceCountByField['pu_updated'] ?? 0))->toBeGreaterThanOrEqual(1)
        ->and(collect($report->rows)->contains(fn ($row) => array_key_exists('pu_updated', $row->differences)))->toBeTrue();
});

function sampleSpreadsheetPath(string $keyword): string
{
    return app(PuValidationSpreadsheetLocatorService::class)->findByKeyword($keyword);
}

function persistReferenceRows(Emission $emission, array $referenceRows, string $calculationVersion = 'v1'): void
{
    $timestamp = now();
    $rows = array_map(function ($row) use ($emission, $timestamp, $calculationVersion): array {
        $amortizationUnitValue = $row->amortizationUnitValue ?? '0.0000000000000000';
        $quantity = $row->quantity ?? '0.0000';

        return [
            'emission_id' => $emission->id,
            'curve_date' => $row->date->toDateString(),
            'calculation_version' => $calculationVersion,
            'is_business_day' => true,
            'unit_base_value' => $row->unitBaseValue ?? $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'unit_corrected_value' => $row->correctedUnitValue ?? $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'factor_di' => $row->factorDi ?? '1.0000000000000000',
            'factor_di_accumulated' => $row->factorDiAccumulated ?? '1.0000000000000000',
            'factor_spread' => $row->factorSpread ?? '1.0000000000000000',
            'factor_spread_di' => $row->factorSpreadDi ?? '1.0000000000000000',
            'interest_real_unit_value' => $row->interestRealUnitValue ?? '0.0000000000000000',
            'updated_unit_value' => $row->updatedUnitValue ?? '0.0000000000000000',
            'amortization_ratio' => '0.0000000000000000',
            'amortization_unit_value' => $amortizationUnitValue,
            'amortization_value' => bcmul($amortizationUnitValue, $quantity, 16),
            'residual_unit_value' => $row->residualUnitValue ?? '0.0000000000000000',
            'quantity' => $quantity,
            'total_value' => $row->totalValue ?? '0.0000000000000000',
            'interest_payment_unit_value' => $quantity !== '0.0000' && $row->paymentInterestTotal !== null
                ? bcdiv($row->paymentInterestTotal, $quantity, 16)
                : '0.0000000000000000',
            'interest_payment_value' => $row->paymentInterestTotal ?? '0.0000000000000000',
            'payment_total_unit_value' => $quantity !== '0.0000' && $row->paymentTotalValue !== null
                ? bcdiv($row->paymentTotalValue, $quantity, 16)
                : '0.0000000000000000',
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

it('validates the latest generated version by default when multiple curve versions exist', function () {
    $spreadsheetPath = sampleSpreadsheetPath('AMANI');
    $emission = Emission::factory()->create([
        'type' => 'CRI',
        'status' => 'active',
    ]);

    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];

    persistReferenceRows($emission, $referenceRows, 'v2');

    EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->update([
            'calculation_version' => 'v1',
        ]);

    persistReferenceRows($emission, $referenceRows, 'v2');

    $report = app(PuValidationService::class)->handle(
        $emission,
        $spreadsheetPath,
        'v2',
        PuValidationMode::DisplayScale,
    );

    expect($report->status)->toBe(PuValidationStatus::Approved)
        ->and($report->mode)->toBe(PuValidationMode::DisplayScale)
        ->and($report->calculationVersion)->toBe('v2');
});
