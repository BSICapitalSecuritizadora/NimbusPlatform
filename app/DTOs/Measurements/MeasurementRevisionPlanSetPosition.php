<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementRevisionAdjustmentStatus;
use App\Enums\MeasurementRevisionDifferenceType;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Posição de uma revisão de medição em um empreendimento: o que a revisão
 * substituída aprovou, o que a revisão aprova, a diferença que a revisão
 * introduz, o que a família já pagou, o que a própria revisão pagou e o que
 * sobra em aberto -- conceitos distintos, nunca confundidos:
 *
 * - diferença da revisão (incremental) = aprovado revisado − aprovado da
 *   revisão anterior;
 * - pago pela família = pago antes da revisão (revisões anteriores, em
 *   qualquer situação) + pago na revisão;
 * - saldo em aberto = aprovado revisado − pago pela família (com sinal):
 *   positivo é saldo a pagar, negativo é valor pago a maior;
 * - valor pago a maior em aberto = o total que a família pagou acima do
 *   aprovado vigente -- o sistema não registra devolução, estorno nem
 *   compensação, então nada o reduz além de uma revisão que aprove mais;
 * - reconhecido anteriormente = a parte do valor pago a maior em aberto que
 *   uma finalização anterior da família já reconheceu (decisão registrada e
 *   aceite do Finalizador), medida contra o aprovado vigente;
 * - a reconhecer nesta revisão = o valor pago a maior vindo dos pagamentos
 *   anteriores à revisão que nenhuma finalização reconheceu: é o que exige a
 *   decisão na etapa Pagamento e o aceite na Finalização desta revisão.
 *
 * Reconhecer não é regularizar: os dois últimos são só a origem do
 * reconhecimento; o valor em aberto continua em aberto.
 *
 * `settledMeasurementId` / `settledApprovedAmount` (nomes e coluna da Fase 3)
 * são só a última medição anterior da família que foi finalizada e o aprovado
 * dela: referência de reconhecimento, nunca regularização -- finalizar não
 * devolve nem compensa nada.
 *
 * Na revisão substituída, a situação é "posição substituída" (o que vale é a
 * revisão seguinte), e a situação que ela tinha enquanto vigente fica em
 * `statusWhenEffective`: é o que a Finalização dela reconheceu, ou deixou de
 * reconhecer, e não muda depois -- os valores são os daquela época.
 *
 * Valores monetários e percentuais em string decimal (escala 2), nunca float.
 * Sem referência financeira (empreendimento sem Fundo de Obra), os valores que
 * dependem do aprovado ficam nulos: a exposição não é mensurável.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementRevisionPlanSetPosition implements Arrayable
{
    /**
     * @param  list<int>  $previouslyAcknowledgedByMeasurementIds  as finalizações anteriores que reconheceram a parte "reconhecida anteriormente"
     */
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
        public ?string $unacknowledgedOverpaymentAmount,
        public MeasurementRevisionAdjustmentStatus $adjustmentStatus,
        public string $familyPaidAmount = '0.00',
        public ?string $outstandingOverpaymentAmount = null,
        public ?string $remainingPayableAmount = null,
        public ?string $previouslyAcknowledgedOverpaymentAmount = null,
        public array $previouslyAcknowledgedByMeasurementIds = [],
        public ?MeasurementRevisionAdjustmentStatus $statusWhenEffective = null,
    ) {}

    /**
     * A posição de uma revisão substituída: histórico do que ela aprovou e
     * reconheceu, não o que está em aberto hoje.
     */
    public function isHistorical(): bool
    {
        return $this->statusWhenEffective !== null;
    }

    public function hasFinancialReference(): bool
    {
        return $this->revisedApprovedAmount !== null;
    }

    /**
     * Valor pago a maior que nenhuma finalização reconheceu e que esta revisão
     * precisa decidir e aceitar expressamente.
     */
    public function hasUnacknowledgedOverpayment(): bool
    {
        return $this->unacknowledgedOverpaymentAmount !== null
            && bccomp($this->unacknowledgedOverpaymentAmount, '0', 2) > 0;
    }

    /**
     * A família pagou acima do aprovado vigente, reconhecido ou não: o valor
     * continua em aberto, porque o sistema não registra devolução.
     */
    public function hasOutstandingOverpayment(): bool
    {
        return $this->outstandingOverpaymentAmount !== null
            && bccomp($this->outstandingOverpaymentAmount, '0', 2) > 0;
    }

    public function hasRemainingPayable(): bool
    {
        return $this->remainingPayableAmount !== null
            && bccomp($this->remainingPayableAmount, '0', 2) > 0;
    }

    public function hasPreviouslyAcknowledgedOverpayment(): bool
    {
        return $this->previouslyAcknowledgedOverpaymentAmount !== null
            && bccomp($this->previouslyAcknowledgedOverpaymentAmount, '0', 2) > 0;
    }

    public function hasHistoricalPayment(): bool
    {
        return bccomp($this->historicalPaidAmount, '0', 2) > 0;
    }

    /**
     * A família pagou, e a revisão não tem como medir quanto deveria: sem
     * Fundo de Obra não há valor aprovado com que comparar.
     */
    public function isExposureUnmeasurable(): bool
    {
        return ! $this->hasFinancialReference() && $this->hasHistoricalPayment();
    }

    public function fundChanged(): bool
    {
        return $this->previousFundAmount !== $this->revisedFundAmount;
    }

    /**
     * As chaves continuam as da Fase 3 -- `unresolved_overpayment_amount` é o
     * valor a reconhecer nesta revisão, como na coluna de mesmo nome das
     * diferenças registradas --, para as trilhas de auditoria antigas e novas
     * falarem a mesma língua.
     *
     * @return array<string, mixed>
     */
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
            'family_paid_amount' => $this->familyPaidAmount,
            'open_balance_amount' => $this->openBalanceAmount,
            'outstanding_overpayment_amount' => $this->outstandingOverpaymentAmount,
            'remaining_payable_amount' => $this->remainingPayableAmount,
            'settled_measurement_id' => $this->settledMeasurementId,
            'settled_approved_amount' => $this->settledApprovedAmount,
            'previously_acknowledged_overpayment_amount' => $this->previouslyAcknowledgedOverpaymentAmount,
            'previously_acknowledged_by_measurement_ids' => $this->previouslyAcknowledgedByMeasurementIds,
            'unresolved_overpayment_amount' => $this->unacknowledgedOverpaymentAmount,
            'adjustment_status' => $this->adjustmentStatus->value,
        ];
    }
}
