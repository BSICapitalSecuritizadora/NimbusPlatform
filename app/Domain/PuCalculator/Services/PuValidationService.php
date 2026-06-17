<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuValidationFieldDifference;
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

    public function handle(Emission $emission, string $spreadsheetPath, ?string $calculationVersion = null): PuValidationReport
    {
        ['sheet_name' => $sheetName, 'rows' => $referenceRows] = $this->reader->read($spreadsheetPath);
        $calculationVersion ??= EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id);

        $curveQuery = EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->whereIn('curve_date', array_map(fn ($row) => $row->date->toDateString(), $referenceRows));

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
                );
            } else {
                $this->compareNumericField($differences, 'pu_updated', 'PU atualizado', (string) $curveRow->updated_unit_value, $referenceRow->updatedUnitValue, DecimalRounder::VALIDATION_SCALE, $referenceRow->hasPayment() ? 'reset de juros / composição do PU após evento' : 'juros acumulados e fator combinado');
                $this->compareNumericField($differences, 'pu_residual', 'PU residual', (string) $curveRow->residual_unit_value, $referenceRow->residualUnitValue, DecimalRounder::VALIDATION_SCALE, $referenceRow->hasAmortization() ? 'ordem entre juros e amortização' : 'reset de juros / valor residual');
                $this->compareNumericField($differences, 'interest_real', 'Juros real', (string) $curveRow->interest_real_unit_value, $referenceRow->interestRealUnitValue, DecimalRounder::VALIDATION_SCALE, 'fator DI, fator spread ou arredondamento do juros');
                $this->compareNumericField($differences, 'amortization', 'Amortização', (string) $curveRow->amortization_unit_value, $referenceRow->amortizationUnitValue, DecimalRounder::VALIDATION_SCALE, 'evento de amortização ordinária');
                $this->compareNumericField($differences, 'quantity', 'Quantidade', (string) $curveRow->quantity, $referenceRow->quantity, DecimalRounder::QUANTITY_SCALE, 'quantidade vigente na data');
                $this->compareNumericField($differences, 'total_value', 'Valor total', (string) $curveRow->total_value, $referenceRow->totalValue, DecimalRounder::VALIDATION_SCALE, 'PU residual x quantidade');
                $this->compareNumericField($differences, 'payment_interest_total', 'Pagamento de juros', (string) $curveRow->interest_payment_value, $referenceRow->paymentInterestTotal, DecimalRounder::VALIDATION_SCALE, 'liquidação do juros na data de evento');
                $this->compareNumericField($differences, 'payment_total', 'Pagamento total', (string) $curveRow->payment_total_value, $referenceRow->paymentTotalValue, DecimalRounder::VALIDATION_SCALE, 'ordem entre juros e amortização');
                $this->compareNumericField($differences, 'factor_di', 'Fator DI', (string) $curveRow->factor_di, $referenceRow->factorDi, DecimalRounder::VALIDATION_SCALE, 'data do CDI ou projeção do último índice');
                $this->compareNumericField($differences, 'factor_di_accumulated', 'Fator DI acumulado', (string) $curveRow->factor_di_accumulated, $referenceRow->factorDiAccumulated, DecimalRounder::VALIDATION_SCALE, 'acúmulo do CDI e truncamento/arredondamento');
                $this->compareNumericField($differences, 'factor_spread', 'Fator spread', (string) $curveRow->factor_spread, $referenceRow->factorSpread, DecimalRounder::VALIDATION_SCALE, 'DUP/DUT ou calendário de dias úteis');
                $this->compareNumericField($differences, 'factor_spread_di', 'Fator spread x DI', (string) $curveRow->factor_spread_di, $referenceRow->factorSpreadDi, DecimalRounder::VALIDATION_SCALE, 'combinação de CDI e spread');
                $this->compareNumericField($differences, 'index_rate', 'CDI usado', (string) $curveRow->index_rate_value, $referenceRow->indexRateValue, DecimalRounder::RATE_SCALE, 'valor do CDI utilizado');
                $this->compareDateField($differences, 'index_rate_date', 'Data do CDI usado', $curveRow->index_rate_date?->toDateString(), $referenceRow->indexRateDate?->toDateString(), 'data do índice e defasagem de consulta');
                $this->compareIntegerField($differences, 'dup_interest', 'DUP juros', $curveRow->dup_interest, $referenceRow->dupInterest, 'contagem de dias úteis do período');
                $this->compareIntegerField($differences, 'dut_interest', 'DUT juros', $curveRow->dut_interest, $referenceRow->dutInterest, 'base de dias úteis da fórmula');

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
        );
    }

    /**
     * @param  array<string, PuValidationFieldDifference>  $differences
     */
    private function compareNumericField(
        array &$differences,
        string $field,
        string $label,
        ?string $actual,
        ?string $expected,
        int $scale,
        string $possibleCause,
    ): void {
        if ($expected === null) {
            return;
        }

        $difference = $this->rounder->absoluteDifference($actual, $expected, $scale);

        if (bccomp($difference, '0', $scale) === 1) {
            $differences[$field] = new PuValidationFieldDifference(
                field: $field,
                label: $label,
                actual: $actual,
                expected: $expected,
                absoluteDifference: $difference,
                percentageDifference: $this->percentageDifference($difference, $expected, $scale),
                relatedRule: $label,
                possibleCause: $this->refinePossibleCause($field, $difference, $possibleCause),
            );
        }
    }

    /**
     * @param  array<string, PuValidationFieldDifference>  $differences
     */
    private function compareIntegerField(
        array &$differences,
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
            $differences[$field] = new PuValidationFieldDifference(
                field: $field,
                label: $label,
                actual: $actual !== null ? (string) $actual : null,
                expected: (string) $expected,
                absoluteDifference: $actual !== null ? (string) abs($actual - $expected) : null,
                percentageDifference: null,
                relatedRule: $label,
                possibleCause: $possibleCause,
            );
        }
    }

    /**
     * @param  array<string, PuValidationFieldDifference>  $differences
     */
    private function compareDateField(
        array &$differences,
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
            $differences[$field] = new PuValidationFieldDifference(
                field: $field,
                label: $label,
                actual: $actual,
                expected: $expected,
                absoluteDifference: null,
                percentageDifference: null,
                relatedRule: $label,
                possibleCause: $possibleCause,
            );
        }
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
            'quantity', 'total_value' => 'quantidade vigente por data ou reflexo no valor total',
            default => $defaultCause,
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
}
