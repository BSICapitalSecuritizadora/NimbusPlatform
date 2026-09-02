<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Carbon\CarbonImmutable;

/**
 * Recalcula o checksum da curva a partir das linhas realmente persistidas,
 * reutilizando a canonicalização já homologada em 2B.5.15
 * (`PuNumericHomologationFingerprintService::curveChecksum`). Não existe uma
 * segunda canonicalização: a única coisa que este serviço faz é reconstruir os
 * `PuDailyCurveRowData` a partir do banco.
 *
 * A reconstrução normaliza cada decimal na escala da própria coluna em vez de
 * confiar na formatação incidental devolvida pelo driver: o candidate em memória
 * já nasce arredondado nessas escalas (`DecimalRounder`), então o checksum é
 * reproduzido semanticamente e não por coincidência de string.
 *
 * A classe é aberta para extensão exclusivamente para permitir o cenário de teste
 * que simula corrupção entre a linha gravada e o checksum do candidate.
 */
class PuPersistedCurveChecksumService
{
    public function __construct(
        private readonly PuNumericHomologationFingerprintService $fingerprints,
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * Checksum das linhas persistidas de uma versão de curva.
     */
    public function checksum(EmissionPuCurveVersion $version): string
    {
        $rows = $version->dailyCurves()
            ->orderBy('curve_date')
            ->orderBy('id')
            ->get()
            ->map(fn (EmissionPuDailyCurve $row): PuDailyCurveRowData => $this->rowData($row))
            ->all();

        return $this->fingerprints->curveChecksum($rows);
    }

    /**
     * Mesmo boundary aplicado a linhas em memória. Serve para provar, em teste, que
     * o checksum do candidate sobrevive ao round-trip pelo banco.
     *
     * @param  list<PuDailyCurveRowData>  $rows
     */
    public function checksumForRows(array $rows): string
    {
        return $this->fingerprints->curveChecksum($rows);
    }

    private function rowData(EmissionPuDailyCurve $row): PuDailyCurveRowData
    {
        return new PuDailyCurveRowData(
            date: $this->date($row->curve_date) ?? CarbonImmutable::parse((string) $row->getRawOriginal('curve_date')),
            isBusinessDay: (bool) $row->is_business_day,
            unitBaseValue: $this->unit($row->unit_base_value),
            unitCorrectedValue: $this->unit($row->unit_corrected_value),
            factorDi: $this->factor($row->factor_di),
            factorDiAccumulated: $this->factor($row->factor_di_accumulated),
            factorSpread: $this->factor($row->factor_spread),
            factorSpreadDi: $this->factor($row->factor_spread_di),
            interestRealUnitValue: $this->unit($row->interest_real_unit_value),
            updatedUnitValue: $this->unit($row->updated_unit_value),
            amortizationRatio: $this->unit($row->amortization_ratio),
            amortizationUnitValue: $this->unit($row->amortization_unit_value),
            amortizationValue: $this->unit($row->amortization_value),
            residualUnitValue: $this->unit($row->residual_unit_value),
            quantity: $this->rounder->normalize($row->quantity, DecimalRounder::QUANTITY_SCALE),
            totalValue: $this->unit($row->total_value),
            interestPaymentUnitValue: $this->unit($row->interest_payment_unit_value),
            interestPaymentValue: $this->unit($row->interest_payment_value),
            paymentTotalUnitValue: $this->unit($row->payment_total_unit_value),
            paymentTotalValue: $this->unit($row->payment_total_value),
            dupCorrection: $row->dup_correction,
            dutCorrection: $row->dut_correction,
            dupInterest: $row->dup_interest,
            dutInterest: $row->dut_interest,
            indexRateDate: $this->date($row->index_rate_date),
            indexRateValue: $row->index_rate_value !== null
                ? $this->rounder->normalize($row->index_rate_value, DecimalRounder::RATE_SCALE)
                : null,
            eventOriginalDate: $this->date($row->event_original_date),
            eventEffectiveDate: $this->date($row->event_effective_date),
            calculationMemory: is_array($row->calculation_memory) ? $row->calculation_memory : [],
        );
    }

    private function unit(mixed $value): string
    {
        return $this->rounder->normalize(
            is_string($value) || is_int($value) || is_float($value) ? $value : null,
            DecimalRounder::UNIT_SCALE,
        );
    }

    private function factor(mixed $value): string
    {
        return $this->rounder->normalize(
            is_string($value) || is_int($value) || is_float($value) ? $value : null,
            DecimalRounder::FACTOR_SCALE,
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
