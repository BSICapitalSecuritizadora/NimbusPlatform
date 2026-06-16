<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuValidationReport;
use App\Domain\PuCalculator\DTOs\PuValidationRowResult;
use App\Domain\PuCalculator\Enums\PuValidationStatus;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;

class PuValidationService
{
    public function __construct(
        private readonly PuSpreadsheetReferenceReader $reader,
        private readonly DecimalRounder $rounder,
    ) {}

    public function handle(Emission $emission, string $spreadsheetPath): PuValidationReport
    {
        ['sheet_name' => $sheetName, 'rows' => $referenceRows] = $this->reader->read($spreadsheetPath);

        $curveRows = EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->whereIn('curve_date', array_map(fn ($row) => $row->date->toDateString(), $referenceRows))
            ->get()
            ->keyBy(fn (EmissionPuDailyCurve $row): string => $row->curve_date?->toDateString() ?? '');

        $rowResults = [];
        $divergentRowCount = 0;
        $largestPuDifference = '0.000000';
        $largestTotalValueDifference = '0.000000';
        $largestPaymentDifference = '0.000000';

        foreach ($referenceRows as $referenceRow) {
            /** @var EmissionPuDailyCurve|null $curveRow */
            $curveRow = $curveRows->get($referenceRow->date->toDateString());
            $differences = [];

            if (! $curveRow instanceof EmissionPuDailyCurve) {
                $differences['curve'] = 'Linha não encontrada na curva gerada.';
            } else {
                $this->compareField($differences, 'pu_updated', (string) $curveRow->updated_unit_value, $referenceRow->updatedUnitValue, DecimalRounder::VALIDATION_SCALE);
                $this->compareField($differences, 'pu_residual', (string) $curveRow->residual_unit_value, $referenceRow->residualUnitValue, DecimalRounder::VALIDATION_SCALE);
                $this->compareField($differences, 'interest_real', (string) $curveRow->interest_real_unit_value, $referenceRow->interestRealUnitValue, DecimalRounder::VALIDATION_SCALE);
                $this->compareField($differences, 'amortization', (string) $curveRow->amortization_unit_value, $referenceRow->amortizationUnitValue, DecimalRounder::VALIDATION_SCALE);
                $this->compareField($differences, 'quantity', (string) $curveRow->quantity, $referenceRow->quantity, DecimalRounder::QUANTITY_SCALE);
                $this->compareField($differences, 'total_value', (string) $curveRow->total_value, $referenceRow->totalValue, DecimalRounder::VALIDATION_SCALE);
                $this->compareField($differences, 'payment_total', (string) $curveRow->payment_total_value, $referenceRow->paymentTotalValue, DecimalRounder::VALIDATION_SCALE);
                $this->compareField($differences, 'index_rate', (string) $curveRow->index_rate_value, $referenceRow->indexRateValue, DecimalRounder::RATE_SCALE);
                $this->compareIntegerField($differences, 'dup_interest', $curveRow->dup_interest, $referenceRow->dupInterest);
                $this->compareIntegerField($differences, 'dut_interest', $curveRow->dut_interest, $referenceRow->dutInterest);

                $largestPuDifference = $this->maxDifference(
                    $largestPuDifference,
                    $this->rounder->absoluteDifference($curveRow->updated_unit_value, $referenceRow->updatedUnitValue, DecimalRounder::VALIDATION_SCALE),
                );
                $largestTotalValueDifference = $this->maxDifference(
                    $largestTotalValueDifference,
                    $this->rounder->absoluteDifference($curveRow->total_value, $referenceRow->totalValue, DecimalRounder::VALIDATION_SCALE),
                );
                $largestPaymentDifference = $this->maxDifference(
                    $largestPaymentDifference,
                    $this->rounder->absoluteDifference($curveRow->payment_total_value, $referenceRow->paymentTotalValue, DecimalRounder::VALIDATION_SCALE),
                );
            }

            if ($differences !== []) {
                $divergentRowCount++;
            }

            $rowResults[] = new PuValidationRowResult(
                date: $referenceRow->date,
                approved: $differences === [],
                differences: $differences,
            );
        }

        return new PuValidationReport(
            sheetName: $sheetName,
            totalRowsCompared: count($referenceRows),
            totalDivergences: $divergentRowCount,
            largestPuDifference: $largestPuDifference,
            largestTotalValueDifference: $largestTotalValueDifference,
            largestPaymentDifference: $largestPaymentDifference,
            status: $divergentRowCount === 0 ? PuValidationStatus::Approved : PuValidationStatus::Rejected,
            rows: $rowResults,
        );
    }

    /**
     * @param  array<string, string>  $differences
     */
    private function compareField(array &$differences, string $field, ?string $actual, ?string $expected, int $scale = DecimalRounder::UNIT_SCALE): void
    {
        if ($expected === null) {
            return;
        }

        $difference = $this->rounder->absoluteDifference($actual, $expected, $scale);

        if (bccomp($difference, '0', $scale) === 1) {
            $differences[$field] = sprintf('Esperado %s e obtido %s.', $expected, $actual ?? 'null');
        }
    }

    /**
     * @param  array<string, string>  $differences
     */
    private function compareIntegerField(array &$differences, string $field, ?int $actual, ?int $expected): void
    {
        if ($expected === null) {
            return;
        }

        if ($actual !== $expected) {
            $differences[$field] = sprintf('Esperado %s e obtido %s.', $expected, $actual ?? 'null');
        }
    }

    private function maxDifference(string $currentMaximum, string $candidate): string
    {
        return bccomp($candidate, $currentMaximum, DecimalRounder::VALIDATION_SCALE) === 1
            ? $candidate
            : $currentMaximum;
    }
}
