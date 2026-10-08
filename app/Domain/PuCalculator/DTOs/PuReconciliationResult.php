<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuReconciliationStatus;

/**
 * Resultado determinístico da comparação entre o valor esperado oficial e a
 * liquidação vigente de uma obrigação. Diferença = liquidado − esperado (em 2
 * casas): negativa é liquidação a menor, positiva a maior. Nunca altera nenhum
 * dos dois lados.
 */
final readonly class PuReconciliationResult
{
    /**
     * @param  array<string, mixed>|null  $divergence
     */
    public function __construct(
        public PuReconciliationStatus $status,
        public ?string $reason = null,
        public ?int $calculationId = null,
        public ?int $settlementId = null,
        public ?string $expectedTotal = null,
        public ?string $actualTotal = null,
        public ?string $difference = null,
        public ?array $divergence = null,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            'status' => $this->status->value,
            'reason' => $this->reason,
            'calculation_id' => $this->calculationId,
            'settlement_id' => $this->settlementId,
            'expected_total' => $this->expectedTotal,
            'actual_total' => $this->actualTotal,
            'difference' => $this->difference,
            'divergence' => $this->divergence,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
