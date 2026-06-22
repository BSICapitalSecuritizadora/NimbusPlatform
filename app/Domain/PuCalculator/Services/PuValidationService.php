<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuValidationFieldDifference;
use App\Domain\PuCalculator\DTOs\PuValidationReport;
use App\Domain\PuCalculator\DTOs\PuValidationRowResult;
use App\Domain\PuCalculator\DTOs\SpreadsheetReferenceFieldData;
use App\Domain\PuCalculator\DTOs\SpreadsheetReferenceRowData;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Enums\PuValidationSeverity;
use App\Domain\PuCalculator\Enums\PuValidationStatus;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use Carbon\CarbonImmutable;

class PuValidationService
{
    public function __construct(
        private readonly PuSpreadsheetReferenceReader $reader,
        private readonly DecimalRounder $rounder,
    ) {}

    public function handle(
        Emission $emission,
        string $spreadsheetPath,
        ?string $calculationVersion = null,
        PuValidationMode $mode = PuValidationMode::RawScale,
        ?CarbonImmutable $rangeStart = null,
        ?CarbonImmutable $rangeEnd = null,
    ): PuValidationReport {
        ['sheet_name' => $sheetName, 'rows' => $referenceRows] = $this->reader->read($spreadsheetPath);
        $referenceRows = array_values(array_filter(
            $referenceRows,
            fn (SpreadsheetReferenceRowData $row): bool => ($rangeStart === null || ! $row->date->lt($rangeStart))
                && ($rangeEnd === null || ! $row->date->gt($rangeEnd)),
        ));

        $calculationVersion ??= EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id);

        $curveQuery = EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->whereIn('curve_date', array_map(fn (SpreadsheetReferenceRowData $row) => $row->date->toDateString(), $referenceRows));

        if ($calculationVersion !== null) {
            $curveQuery->where('calculation_version', $calculationVersion);
        }

        $curveRows = $curveQuery
            ->get()
            ->keyBy(fn (EmissionPuDailyCurve $row): string => $row->curve_date?->toDateString() ?? '');

        $rowResults = [];
        $divergentRowCount = 0;
        $fieldDivergenceCount = 0;
        $largestPuDifference = '0.000000';
        $largestTotalValueDifference = '0.000000';
        $largestPaymentDifference = '0.000000';
        $firstDivergenceDate = null;
        $largestDifferencesByField = [];
        $divergenceCountByField = [];
        $divergenceCountByCause = [];

        foreach ($referenceRows as $referenceRow) {
            /** @var EmissionPuDailyCurve|null $curveRow */
            $curveRow = $curveRows->get($referenceRow->date->toDateString());
            $differences = [];

            if (! $curveRow instanceof EmissionPuDailyCurve) {
                $differences['curve_row'] = new PuValidationFieldDifference(
                    field: 'curve_row',
                    label: 'Linha da curva',
                    actual: null,
                    expected: $referenceRow->date->toDateString(),
                    absoluteDifference: null,
                    percentageDifference: null,
                    relatedRule: 'existência da linha diária',
                    possibleCause: 'curva não gerada para a data esperada',
                    comparisonMode: $mode->value,
                    severity: PuValidationSeverity::High,
                );
            } else {
                $this->compareNumericField($differences, $referenceRow, $mode, 'pu_updated', 'PU atualizado', (string) $curveRow->updated_unit_value, $referenceRow->updatedUnitValue, DecimalRounder::UNIT_SCALE, $referenceRow->hasPayment() ? 'reset de juros / composição do PU após evento' : 'juros acumulados e fator combinado');
                $this->compareNumericField($differences, $referenceRow, $mode, 'pu_residual', 'PU residual', (string) $curveRow->residual_unit_value, $referenceRow->residualUnitValue, DecimalRounder::UNIT_SCALE, $referenceRow->hasAmortization() ? 'ordem entre juros e amortização' : 'reset de juros / valor residual');
                $this->compareNumericField($differences, $referenceRow, $mode, 'interest_real', 'Juros real', (string) $curveRow->interest_real_unit_value, $referenceRow->interestRealUnitValue, DecimalRounder::UNIT_SCALE, 'fator DI, fator spread ou arredondamento do juros');
                $this->compareNumericField($differences, $referenceRow, $mode, 'amortization', 'Amortização', (string) $curveRow->amortization_unit_value, $referenceRow->amortizationUnitValue, DecimalRounder::UNIT_SCALE, 'evento de amortização ordinária');
                $this->compareNumericField($differences, $referenceRow, $mode, 'quantity', 'Quantidade', (string) $curveRow->quantity, $referenceRow->quantity, DecimalRounder::QUANTITY_SCALE, 'quantidade vigente na data');
                $this->compareNumericField($differences, $referenceRow, $mode, 'total_value', 'Valor total', (string) $curveRow->total_value, $referenceRow->totalValue, DecimalRounder::TOTAL_SCALE, 'PU residual x quantidade');
                $this->compareNumericField($differences, $referenceRow, $mode, 'payment_interest_total', 'Pagamento de juros', (string) $curveRow->interest_payment_value, $referenceRow->paymentInterestTotal, DecimalRounder::TOTAL_SCALE, 'liquidação do juros na data de evento');
                $this->compareNumericField($differences, $referenceRow, $mode, 'payment_total', 'Pagamento total', (string) $curveRow->payment_total_value, $referenceRow->paymentTotalValue, DecimalRounder::TOTAL_SCALE, 'ordem entre juros e amortização');
                $this->compareNumericField($differences, $referenceRow, $mode, 'factor_di', 'Fator DI', (string) $curveRow->factor_di, $referenceRow->factorDi, DecimalRounder::FACTOR_SCALE, 'data do CDI ou projeção do último índice');
                $this->compareNumericField($differences, $referenceRow, $mode, 'factor_di_accumulated', 'Fator DI acumulado', (string) $curveRow->factor_di_accumulated, $referenceRow->factorDiAccumulated, DecimalRounder::FACTOR_SCALE, 'acúmulo do CDI e truncamento/arredondamento');
                $this->compareNumericField($differences, $referenceRow, $mode, 'factor_spread', 'Fator spread', (string) $curveRow->factor_spread, $referenceRow->factorSpread, DecimalRounder::FACTOR_SCALE, 'DUP/DUT ou calendário de dias úteis');
                $this->compareNumericField($differences, $referenceRow, $mode, 'factor_spread_di', 'Fator spread x DI', (string) $curveRow->factor_spread_di, $referenceRow->factorSpreadDi, DecimalRounder::FACTOR_SCALE, 'combinação de CDI e spread');
                $this->compareNumericField($differences, $referenceRow, $mode, 'index_rate', 'CDI usado', (string) $curveRow->index_rate_value, $referenceRow->indexRateValue, DecimalRounder::RATE_SCALE, 'valor do CDI utilizado');
                $this->compareDateField($differences, $referenceRow, $mode, 'index_rate_date', 'Data do CDI usado', $curveRow->index_rate_date?->toDateString(), $referenceRow->indexRateDate?->toDateString(), 'data do índice e defasagem de consulta');
                $this->compareIntegerField($differences, $referenceRow, $mode, 'dup_interest', 'DUP juros', $curveRow->dup_interest, $referenceRow->dupInterest, 'contagem de dias úteis do período');
                $this->compareIntegerField($differences, $referenceRow, $mode, 'dut_interest', 'DUT juros', $curveRow->dut_interest, $referenceRow->dutInterest, 'base de dias úteis da fórmula');

                $largestPuDifference = $this->maxDifference(
                    $largestPuDifference,
                    $this->differenceForMode((string) $curveRow->updated_unit_value, $referenceRow->updatedUnitValue, $referenceRow->metadataFor('pu_updated'), DecimalRounder::UNIT_SCALE, $mode),
                );
                $largestTotalValueDifference = $this->maxDifference(
                    $largestTotalValueDifference,
                    $this->differenceForMode((string) $curveRow->total_value, $referenceRow->totalValue, $referenceRow->metadataFor('total_value'), DecimalRounder::TOTAL_SCALE, $mode),
                );
                $largestPaymentDifference = $this->maxDifference(
                    $largestPaymentDifference,
                    $this->differenceForMode((string) $curveRow->payment_total_value, $referenceRow->paymentTotalValue, $referenceRow->metadataFor('payment_total'), DecimalRounder::TOTAL_SCALE, $mode),
                );
            }

            if ($differences !== []) {
                $divergentRowCount++;
                $fieldDivergenceCount += count($differences);
                $firstDivergenceDate ??= $referenceRow->date;

                foreach ($differences as $field => $difference) {
                    $divergenceCountByField[$field] = ($divergenceCountByField[$field] ?? 0) + 1;

                    if ($difference->possibleCause !== null) {
                        $divergenceCountByCause[$difference->possibleCause] = ($divergenceCountByCause[$difference->possibleCause] ?? 0) + 1;
                    }

                    if (
                        ! isset($largestDifferencesByField[$field])
                        || $this->isGreaterDifference($difference, $largestDifferencesByField[$field])
                    ) {
                        $largestDifferencesByField[$field] = $difference;
                    }
                }
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
            totalFieldDivergences: $fieldDivergenceCount,
            largestPuDifference: $largestPuDifference,
            largestTotalValueDifference: $largestTotalValueDifference,
            largestPaymentDifference: $largestPaymentDifference,
            status: $divergentRowCount === 0 ? PuValidationStatus::Approved : PuValidationStatus::Rejected,
            rows: $rowResults,
            firstDivergenceDate: $firstDivergenceDate,
            largestDifferencesByField: $largestDifferencesByField,
            divergenceCountByField: $divergenceCountByField,
            divergenceCountByCause: $divergenceCountByCause,
            calculationVersion: $calculationVersion,
            mode: $mode,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
        );
    }

    /**
     * @param  array<string, PuValidationFieldDifference>  $differences
     */
    private function compareNumericField(
        array &$differences,
        SpreadsheetReferenceRowData $referenceRow,
        PuValidationMode $mode,
        string $field,
        string $label,
        ?string $actualRaw,
        ?string $expectedRaw,
        int $rawScale,
        string $possibleCause,
    ): void {
        if ($expectedRaw === null) {
            return;
        }

        $metadata = $referenceRow->metadataFor($field);
        $displayScale = $metadata?->displayScale ?? $this->defaultDisplayScale($field);
        $actualDisplay = $this->roundForScale($actualRaw, $displayScale);
        $expectedDisplay = $metadata?->displayValue ?? $this->roundForScale($expectedRaw, $displayScale);
        $actual = $mode === PuValidationMode::DisplayScale
            ? $actualDisplay
            : $this->roundForScale($actualRaw, $rawScale);
        $expected = $mode === PuValidationMode::DisplayScale
            ? $expectedDisplay
            : $this->roundForScale($expectedRaw, $rawScale);
        $comparisonScale = $mode === PuValidationMode::DisplayScale ? $displayScale : $rawScale;
        $difference = $this->rounder->absoluteDifference($actual, $expected, $comparisonScale);

        if (bccomp($difference, '0', $comparisonScale) === 1) {
            $differences[$field] = new PuValidationFieldDifference(
                field: $field,
                label: $label,
                actual: $actual,
                expected: $expected,
                absoluteDifference: $difference,
                percentageDifference: $this->percentageDifference($difference, $expected, $comparisonScale),
                relatedRule: $label,
                possibleCause: $this->refinePossibleCause($field, $difference, $possibleCause),
                comparisonMode: $mode->value,
                actualRaw: $this->roundForScale($actualRaw, $rawScale),
                expectedRaw: $this->roundForScale($expectedRaw, $rawScale),
                actualDisplay: $actualDisplay,
                expectedDisplay: $expectedDisplay,
                spreadsheetCell: $metadata?->cellReference,
                spreadsheetFormula: $metadata?->formula,
                displayScale: $displayScale,
                numberFormatCode: $metadata?->numberFormatCode,
                severity: $this->severityForNumericDifference($field, $difference),
            );
        }
    }

    /**
     * @param  array<string, PuValidationFieldDifference>  $differences
     */
    private function compareIntegerField(
        array &$differences,
        SpreadsheetReferenceRowData $referenceRow,
        PuValidationMode $mode,
        string $field,
        string $label,
        ?int $actual,
        ?int $expected,
        string $possibleCause,
    ): void {
        if ($expected === null) {
            return;
        }

        if ($actual !== $expected) {
            $metadata = $referenceRow->metadataFor($field);

            $differences[$field] = new PuValidationFieldDifference(
                field: $field,
                label: $label,
                actual: $actual !== null ? (string) $actual : null,
                expected: (string) $expected,
                absoluteDifference: $actual !== null ? (string) abs($actual - $expected) : null,
                percentageDifference: null,
                relatedRule: $label,
                possibleCause: $possibleCause,
                comparisonMode: $mode->value,
                actualRaw: $actual !== null ? (string) $actual : null,
                expectedRaw: (string) $expected,
                actualDisplay: $actual !== null ? (string) $actual : null,
                expectedDisplay: (string) $expected,
                spreadsheetCell: $metadata?->cellReference,
                spreadsheetFormula: $metadata?->formula,
                displayScale: $metadata?->displayScale,
                numberFormatCode: $metadata?->numberFormatCode,
                severity: PuValidationSeverity::High,
            );
        }
    }

    /**
     * @param  array<string, PuValidationFieldDifference>  $differences
     */
    private function compareDateField(
        array &$differences,
        SpreadsheetReferenceRowData $referenceRow,
        PuValidationMode $mode,
        string $field,
        string $label,
        ?string $actual,
        ?string $expected,
        string $possibleCause,
    ): void {
        if ($expected === null) {
            return;
        }

        if ($actual !== $expected) {
            $metadata = $referenceRow->metadataFor($field);

            $differences[$field] = new PuValidationFieldDifference(
                field: $field,
                label: $label,
                actual: $actual,
                expected: $expected,
                absoluteDifference: null,
                percentageDifference: null,
                relatedRule: $label,
                possibleCause: $possibleCause,
                comparisonMode: $mode->value,
                actualRaw: $actual,
                expectedRaw: $expected,
                actualDisplay: $actual,
                expectedDisplay: $expected,
                spreadsheetCell: $metadata?->cellReference,
                spreadsheetFormula: $metadata?->formula,
                displayScale: $metadata?->displayScale,
                numberFormatCode: $metadata?->numberFormatCode,
                severity: PuValidationSeverity::High,
            );
        }
    }

    private function differenceForMode(
        ?string $actualRaw,
        ?string $expectedRaw,
        ?SpreadsheetReferenceFieldData $metadata,
        int $rawScale,
        PuValidationMode $mode,
    ): string {
        $displayScale = $metadata?->displayScale ?? $rawScale;
        $actual = $mode === PuValidationMode::DisplayScale
            ? $this->roundForScale($actualRaw, $displayScale)
            : $this->roundForScale($actualRaw, $rawScale);
        $expected = $mode === PuValidationMode::DisplayScale
            ? ($metadata?->displayValue ?? $this->roundForScale($expectedRaw, $displayScale))
            : $this->roundForScale($expectedRaw, $rawScale);

        return $this->rounder->absoluteDifference(
            $actual,
            $expected,
            $mode === PuValidationMode::DisplayScale ? $displayScale : $rawScale,
        );
    }

    private function maxDifference(string $currentMaximum, string $candidate): string
    {
        return bccomp($candidate, $currentMaximum, DecimalRounder::VALIDATION_SCALE) === 1
            ? $candidate
            : $currentMaximum;
    }

    private function percentageDifference(string $absoluteDifference, string $expected, int $scale): ?string
    {
        $normalizedExpected = $this->rounder->normalize($expected, $scale + 4);
        $absoluteExpected = ltrim($normalizedExpected, '-');

        if (bccomp($absoluteExpected, '0', $scale + 4) === 0) {
            return null;
        }

        return $this->rounder->round(
            bcmul(
                bcdiv($absoluteDifference, $absoluteExpected, DecimalRounder::INTERNAL_SCALE),
                '100',
                DecimalRounder::INTERNAL_SCALE,
            ),
            DecimalRounder::VALIDATION_SCALE,
        );
    }

    private function refinePossibleCause(string $field, string $difference, string $defaultCause): string
    {
        if (bccomp($difference, '0.000100', 6) <= 0) {
            return 'arredondamento / escala decimal';
        }

        return match ($field) {
            'index_rate', 'index_rate_date', 'factor_di', 'factor_di_accumulated' => 'data do CDI, defasagem ou projeção do último índice',
            'factor_spread', 'dup_interest', 'dut_interest' => 'calendário de dias úteis ou DUP/DUT do período',
            'quantity' => 'quantidade vigente por data',
            'total_value' => 'precisao interna do PU residual x quantidade ou arredondamento do total',
            default => $defaultCause,
        };
    }

    private function roundForScale(?string $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->rounder->round($value, $scale);
    }

    private function defaultDisplayScale(string $field): int
    {
        return match ($field) {
            'quantity' => DecimalRounder::QUANTITY_SCALE,
            'index_rate' => DecimalRounder::RATE_SCALE,
            'factor_di', 'factor_di_accumulated', 'factor_spread', 'factor_spread_di' => 8,
            'dup_interest', 'dut_interest', 'dup_correction', 'dut_correction' => 0,
            default => 8,
        };
    }

    private function isGreaterDifference(PuValidationFieldDifference $candidate, PuValidationFieldDifference $current): bool
    {
        if ($candidate->absoluteDifference === null) {
            return $current->absoluteDifference === null;
        }

        if ($current->absoluteDifference === null) {
            return true;
        }

        return bccomp($candidate->absoluteDifference, $current->absoluteDifference, DecimalRounder::VALIDATION_SCALE) === 1;
    }

    private function severityForNumericDifference(string $field, string $difference): PuValidationSeverity
    {
        if (in_array($field, ['quantity', 'index_rate', 'dup_interest', 'dut_interest'], true)) {
            return PuValidationSeverity::High;
        }

        if (bccomp($difference, '0.001000', DecimalRounder::VALIDATION_SCALE) <= 0) {
            return PuValidationSeverity::Low;
        }

        if (bccomp($difference, '0.010000', DecimalRounder::VALIDATION_SCALE) <= 0) {
            return PuValidationSeverity::Medium;
        }

        return PuValidationSeverity::High;
    }
}
