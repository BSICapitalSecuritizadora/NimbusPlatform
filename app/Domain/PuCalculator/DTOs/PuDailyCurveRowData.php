<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class PuDailyCurveRowData
{
    public function __construct(
        public CarbonImmutable $date,
        public bool $isBusinessDay,
        public string $unitBaseValue,
        public string $unitCorrectedValue,
        public string $factorDi,
        public string $factorDiAccumulated,
        public string $factorSpread,
        public string $factorSpreadDi,
        public string $interestRealUnitValue,
        public string $updatedUnitValue,
        public string $amortizationRatio,
        public string $amortizationUnitValue,
        public string $amortizationValue,
        public string $residualUnitValue,
        public string $quantity,
        public string $totalValue,
        public string $interestPaymentUnitValue,
        public string $interestPaymentValue,
        public string $paymentTotalUnitValue,
        public string $paymentTotalValue,
        public ?int $dupCorrection,
        public ?int $dutCorrection,
        public ?int $dupInterest,
        public ?int $dutInterest,
        public ?CarbonImmutable $indexRateDate,
        public ?string $indexRateValue,
        public ?CarbonImmutable $eventOriginalDate,
        public ?CarbonImmutable $eventEffectiveDate,
        public array $calculationMemory = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPersistenceArray(int $emissionId, string $calculationVersion): array
    {
        return [
            'emission_id' => $emissionId,
            'curve_date' => $this->date->toDateString(),
            'calculation_version' => $calculationVersion,
            'is_business_day' => $this->isBusinessDay,
            'unit_base_value' => $this->unitBaseValue,
            'unit_corrected_value' => $this->unitCorrectedValue,
            'factor_di' => $this->factorDi,
            'factor_di_accumulated' => $this->factorDiAccumulated,
            'factor_spread' => $this->factorSpread,
            'factor_spread_di' => $this->factorSpreadDi,
            'interest_real_unit_value' => $this->interestRealUnitValue,
            'updated_unit_value' => $this->updatedUnitValue,
            'amortization_ratio' => $this->amortizationRatio,
            'amortization_unit_value' => $this->amortizationUnitValue,
            'amortization_value' => $this->amortizationValue,
            'residual_unit_value' => $this->residualUnitValue,
            'quantity' => $this->quantity,
            'total_value' => $this->totalValue,
            'interest_payment_unit_value' => $this->interestPaymentUnitValue,
            'interest_payment_value' => $this->interestPaymentValue,
            'payment_total_unit_value' => $this->paymentTotalUnitValue,
            'payment_total_value' => $this->paymentTotalValue,
            'dup_correction' => $this->dupCorrection,
            'dut_correction' => $this->dutCorrection,
            'dup_interest' => $this->dupInterest,
            'dut_interest' => $this->dutInterest,
            'index_rate_date' => $this->indexRateDate?->toDateString(),
            'index_rate_value' => $this->indexRateValue,
            'event_original_date' => $this->eventOriginalDate?->toDateString(),
            'event_effective_date' => $this->eventEffectiveDate?->toDateString(),
            'calculation_memory' => $this->calculationMemory === []
                ? null
                : json_encode($this->calculationMemory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Pagamento FINANCEIRO da linha: depende da quantidade em carteira na data.
     */
    public function hasPayment(): bool
    {
        return bccomp($this->paymentTotalValue, '0', 12) === 1;
    }

    /**
     * Pagamento UNITÁRIO da linha, independente da posição em carteira.
     *
     * É este -- e nunca o financeiro -- que encerra o período de juros. O Termo de
     * Securitização ancora o Fator DI na última Data de Pagamento dos Juros
     * Remuneratórios, que é um fato do papel e não da posição: o cupom vence,
     * e o período recomeça, mesmo que ninguém detenha o título na data.
     *
     * A distinção é material porque toda curva sem timeline de integralização
     * (simulação e homologação unitária alimentam a engine com
     * `integralizationHistories` vazio) tem quantidade zero em todas as datas, e
     * portanto `payment_total_value` zero ainda quando o cupom unitário foi pago.
     */
    public function hasUnitPayment(): bool
    {
        return bccomp($this->paymentTotalUnitValue, '0', 12) === 1;
    }
}
