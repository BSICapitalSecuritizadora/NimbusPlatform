<?php

namespace App\DTOs\Measurements;

use Illuminate\Contracts\Support\Arrayable;

/**
 * O que ainda é devido num empreendimento da medição: o valor aprovado pela
 * Engenharia (Fundo de Obra congelado × realizado do mês), o que a família já
 * pagou antes (só na revisão), o que esta medição registrou e o saldo a pagar
 * que sobra -- nunca negativo: o que passa do aprovado não é crédito, é valor
 * pago a maior, que a revisão trata à parte.
 *
 * Sem Fundo de Obra no registro da Engenharia o valor aprovado é nulo: não há
 * referência financeira, e o saldo não pode ser determinado. Zero e nulo
 * nunca se confundem -- o Fundo de Obra zero é um Fundo válido.
 *
 * Valores em string decimal, escala 2, nunca float.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementAmountDueLine implements Arrayable
{
    public function __construct(
        public int $planSetId,
        public string $label,
        public ?int $constructionId,
        public ?int $planVersionId,
        public ?int $planVersionNumber,
        public ?string $fundAmount,
        public string $realizedMonthlyPercent,
        public ?string $approvedAmount,
        public string $paidBeforeAmount,
        public string $registeredAmount,
        public ?string $remainingAmount,
        public ?string $unacknowledgedOverpaymentAmount = null,
    ) {}

    public function hasFinancialReference(): bool
    {
        return $this->approvedAmount !== null;
    }

    /**
     * Ainda há valor a pagar neste empreendimento.
     */
    public function isPayable(): bool
    {
        return $this->remainingAmount !== null
            && bccomp($this->remainingAmount, '0', 2) > 0;
    }

    /**
     * Valor pago a maior pela família que nenhuma Finalização reconheceu (só
     * na revisão): pede a decisão da etapa Pagamento, então não é "nada a
     * pagar".
     */
    public function hasUnacknowledgedOverpayment(): bool
    {
        return $this->unacknowledgedOverpaymentAmount !== null
            && bccomp($this->unacknowledgedOverpaymentAmount, '0', 2) > 0;
    }

    /**
     * Por que o valor aprovado é zero, para a trilha reconstruir a decisão sem
     * refazer a conta: sem avanço físico no mês, Fundo de Obra zero, ou
     * produto que arredonda a zero centavo na escala da moeda.
     */
    public function zeroReason(): ?string
    {
        if ($this->approvedAmount === null || bccomp($this->approvedAmount, '0', 2) !== 0) {
            return null;
        }

        return match (true) {
            bccomp($this->realizedMonthlyPercent, '0', 2) === 0 => 'no_progress',
            bccomp((string) $this->fundAmount, '0', 2) === 0 => 'zero_fund',
            default => 'rounded_to_zero',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_set_id' => $this->planSetId,
            'label' => $this->label,
            'construction_id' => $this->constructionId,
            'plan_version_id' => $this->planVersionId,
            'plan_version_number' => $this->planVersionNumber,
            'fund_amount' => $this->fundAmount,
            'realized_monthly_percent' => $this->realizedMonthlyPercent,
            'approved_amount' => $this->approvedAmount,
            'paid_before_amount' => $this->paidBeforeAmount,
            'registered_amount' => $this->registeredAmount,
            'remaining_amount' => $this->remainingAmount,
            'unacknowledged_overpayment_amount' => $this->unacknowledgedOverpaymentAmount,
            'zero_reason' => $this->zeroReason(),
        ];
    }
}
