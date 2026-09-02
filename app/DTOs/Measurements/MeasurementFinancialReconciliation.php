<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementReconciliationStatus;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Conciliação financeira completa de uma medição.
 *
 * O agregado existe para a visão da medição; a granularidade por
 * empreendimento continua sendo a unidade de cálculo e nunca é derivada do
 * total. Quando algum empreendimento não tem fundo de obra no snapshot, o
 * total soma apenas o que existe e `referenceComplete` deixa isso explícito.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementFinancialReconciliation implements Arrayable
{
    /** @param array<int, MeasurementFinancialReconciliationLine> $lines */
    public function __construct(
        public int $measurementId,
        public array $lines,
        public bool $referenceComplete,
        public ?string $expectedAmount,
        public string $registeredAmount,
        public ?string $expectedBalance,
        public string $enteredAmount,
        public ?string $divergenceAmount,
        public ?string $divergencePercent,
        public MeasurementReconciliationStatus $status,
    ) {}

    public function line(int $planSetId): ?MeasurementFinancialReconciliationLine
    {
        foreach ($this->lines as $line) {
            if ($line->planSetId === $planSetId) {
                return $line;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'measurement_id' => $this->measurementId,
            'reference_complete' => $this->referenceComplete,
            'expected_amount' => $this->expectedAmount,
            'registered_amount' => $this->registeredAmount,
            'expected_balance' => $this->expectedBalance,
            'entered_amount' => $this->enteredAmount,
            'divergence_amount' => $this->divergenceAmount,
            'divergence_percent' => $this->divergencePercent,
            'status' => $this->status->value,
            'lines' => array_map(
                fn (MeasurementFinancialReconciliationLine $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
