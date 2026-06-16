<?php

use App\Domain\PuCalculator\Enums\PuValidationStatus;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Domain\PuCalculator\Services\PuValidationService;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('approves mirrored reference curves for the validation workbooks', function (string $keyword, int $expectedRowCount) {
    $spreadsheetPath = sampleSpreadsheetPath($keyword);
    $emission = Emission::factory()->create([
        'type' => str_contains($keyword, 'AMANI') ? 'CRI' : 'CR',
        'status' => 'active',
    ]);

    $referenceRows = app(PuSpreadsheetReferenceReader::class)->read($spreadsheetPath)['rows'];

    persistReferenceRows($emission, $referenceRows);

    $report = app(PuValidationService::class)->handle($emission, $spreadsheetPath);

    expect($report->sheetName)->toBe('PuDiario')
        ->and($report->totalRowsCompared)->toBe($expectedRowCount)
        ->and($report->totalDivergences)->toBe(0)
        ->and($report->largestPuDifference)->toBe('0.000000')
        ->and($report->largestTotalValueDifference)->toBe('0.000000')
        ->and($report->largestPaymentDifference)->toBe('0.000000')
        ->and($report->status)->toBe(PuValidationStatus::Approved);
})->with([
    'AMANI' => ['AMANI', 1810],
    'TROUPE' => ['TROUPE', 696],
]);

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

    $report = app(PuValidationService::class)->handle($emission, $spreadsheetPath);

    expect($report->status)->toBe(PuValidationStatus::Rejected)
        ->and($report->totalDivergences)->toBeGreaterThanOrEqual(1)
        ->and(bccomp($report->largestPuDifference, '0', 6))->toBe(1)
        ->and(collect($report->rows)->contains(fn ($row) => array_key_exists('pu_updated', $row->differences)))->toBeTrue();
});

function sampleSpreadsheetPath(string $keyword): string
{
    $matches = glob(base_path("docs/design/*{$keyword}*.xlsx"));

    expect($matches)->not()->toBeFalse()->and($matches)->not()->toBeEmpty();

    return $matches[0];
}

function persistReferenceRows(Emission $emission, array $referenceRows): void
{
    $timestamp = now();
    $rows = array_map(function ($row) use ($emission, $timestamp): array {
        return [
            'emission_id' => $emission->id,
            'curve_date' => $row->date->toDateString(),
            'is_business_day' => true,
            'unit_base_value' => $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'unit_corrected_value' => $row->residualUnitValue ?? $row->updatedUnitValue ?? '0.0000000000000000',
            'factor_di' => '1.0000000000000000',
            'factor_di_accumulated' => '1.0000000000000000',
            'factor_spread' => '1.0000000000000000',
            'factor_spread_di' => '1.0000000000000000',
            'interest_real_unit_value' => $row->interestRealUnitValue ?? '0.0000000000000000',
            'updated_unit_value' => $row->updatedUnitValue ?? '0.0000000000000000',
            'amortization_ratio' => '0.0000000000000000',
            'amortization_unit_value' => $row->amortizationUnitValue ?? '0.0000000000000000',
            'residual_unit_value' => $row->residualUnitValue ?? '0.0000000000000000',
            'quantity' => $row->quantity ?? '0.0000',
            'total_value' => $row->totalValue ?? '0.0000000000000000',
            'interest_payment_unit_value' => '0.0000000000000000',
            'interest_payment_value' => '0.0000000000000000',
            'payment_total_unit_value' => '0.0000000000000000',
            'payment_total_value' => $row->paymentTotalValue ?? '0.0000000000000000',
            'dup_correction' => 0,
            'dut_correction' => 0,
            'dup_interest' => $row->dupInterest,
            'dut_interest' => $row->dutInterest,
            'index_rate_date' => $row->date->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'event_original_date' => null,
            'event_effective_date' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }, $referenceRows);

    foreach (array_chunk($rows, 500) as $chunk) {
        EmissionPuDailyCurve::query()->insert($chunk);
    }
}
