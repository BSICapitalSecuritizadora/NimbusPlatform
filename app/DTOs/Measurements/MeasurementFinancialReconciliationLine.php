<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementReconciliationStatus;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Conciliação financeira de um empreendimento dentro de uma medição.
 *
 * Todos os valores monetários e percentuais trafegam como string decimal --
 * nunca float -- porque a comparação entre esperado e informado é o produto
 * do serviço, e um erro de representação binária viraria divergência falsa.
 *
 * Numa revisão de medição (R1, R2...), `historicalPaidAmount` é o que as
 * revisões anteriores da mesma medição lógica já pagaram: o saldo esperado
 * desconta esse valor, para a revisão nunca pedir de novo o que já foi pago.
 * Na medição sem revisão ele é sempre zero.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementFinancialReconciliationLine implements Arrayable
{
    public function __construct(
        public int $planSetId,
        public ?int $constructionId,
        public string $label,
        public ?string $fundAmount,
        public string $realizedMonthlyPercent,
        public ?string $expectedAmount,
        public string $registeredAmount,
        public ?string $expectedBalance,
        public string $enteredAmount,
        public ?string $divergenceAmount,
        public ?string $divergencePercent,
        public MeasurementReconciliationStatus $status,
        public string $historicalPaidAmount = '0.00',
    ) {}

    public function hasFinancialReference(): bool
    {
        return $this->expectedAmount !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_set_id' => $this->planSetId,
            'construction_id' => $this->constructionId,
            'label' => $this->label,
            'fund_amount' => $this->fundAmount,
            'realized_monthly_percent' => $this->realizedMonthlyPercent,
            'expected_amount' => $this->expectedAmount,
            'registered_amount' => $this->registeredAmount,
            'expected_balance' => $this->expectedBalance,
            'entered_amount' => $this->enteredAmount,
            'divergence_amount' => $this->divergenceAmount,
            'divergence_percent' => $this->divergencePercent,
            'status' => $this->status->value,
            'historical_paid_amount' => $this->historicalPaidAmount,
        ];
    }
}
