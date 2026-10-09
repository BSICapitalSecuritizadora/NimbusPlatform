<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementRevisionAdjustmentStatus;
use App\Enums\MeasurementRevisionDifferenceType;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Posição de uma revisão de medição em um empreendimento: o que a revisão
 * substituída aprovou, o que a revisão aprova, a diferença que a revisão
 * introduz, o que a família já pagou, o que a própria revisão pagou e o saldo
 * que sobra -- conceitos distintos, nunca confundidos:
 *
 * - diferença da revisão = aprovado revisado − aprovado da revisão anterior;
 * - saldo em aberto = aprovado revisado − pago antes da revisão − pago na revisão;
 * - valor pago a maior não resolvido = o que a revisão deixou pago acima do
 *   aprovado, além do que já tinha sido aceito na última finalização da família.
 *
 * Valores monetários e percentuais em string decimal (escala 2), nunca float.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementRevisionPlanSetPosition implements Arrayable
{
    public function __construct(
        public int $planSetId,
        public string $label,
        public ?int $planVersionId,
        public ?int $planVersionNumber,
        public string $previousRealizedMonthlyPercent,
        public string $revisedRealizedMonthlyPercent,
        public int $physicalDifferenceBasisPoints,
        public ?string $previousFundAmount,
        public ?string $revisedFundAmount,
        public ?string $previousApprovedAmount,
        public ?string $revisedApprovedAmount,
        public ?string $financialDifferenceAmount,
        public MeasurementRevisionDifferenceType $differenceType,
        public string $historicalPaidAmount,
        public string $ownPaidAmount,
        public ?string $openBalanceAmount,
        public ?int $settledMeasurementId,
        public ?string $settledApprovedAmount,
        public string $unresolvedOverpaymentAmount,
        public MeasurementRevisionAdjustmentStatus $adjustmentStatus,
    ) {}

    public function hasFinancialReference(): bool
    {
        return $this->revisedApprovedAmount !== null;
    }

    public function hasUnresolvedOverpayment(): bool
    {
        return bccomp($this->unresolvedOverpaymentAmount, '0', 2) > 0;
    }

    public function hasHistoricalPayment(): bool
    {
        return bccomp($this->historicalPaidAmount, '0', 2) > 0;
    }

    public function fundChanged(): bool
    {
        return $this->previousFundAmount !== $this->revisedFundAmount;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_set_id' => $this->planSetId,
            'label' => $this->label,
            'plan_version_id' => $this->planVersionId,
            'plan_version_number' => $this->planVersionNumber,
            'previous_realized_monthly_percent' => $this->previousRealizedMonthlyPercent,
            'revised_realized_monthly_percent' => $this->revisedRealizedMonthlyPercent,
            'physical_difference_basis_points' => $this->physicalDifferenceBasisPoints,
            'previous_fund_amount' => $this->previousFundAmount,
            'revised_fund_amount' => $this->revisedFundAmount,
            'previous_approved_amount' => $this->previousApprovedAmount,
            'revised_approved_amount' => $this->revisedApprovedAmount,
            'financial_difference_amount' => $this->financialDifferenceAmount,
            'difference_type' => $this->differenceType->value,
            'historical_paid_amount' => $this->historicalPaidAmount,
            'own_paid_amount' => $this->ownPaidAmount,
            'open_balance_amount' => $this->openBalanceAmount,
            'settled_measurement_id' => $this->settledMeasurementId,
            'settled_approved_amount' => $this->settledApprovedAmount,
            'unresolved_overpayment_amount' => $this->unresolvedOverpaymentAmount,
            'adjustment_status' => $this->adjustmentStatus->value,
        ];
    }
}
