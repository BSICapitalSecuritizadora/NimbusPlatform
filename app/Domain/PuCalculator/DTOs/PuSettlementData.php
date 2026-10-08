<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuSettlementSource;

/**
 * Fato de liquidação como um conector (B3, banco) ou um registro manual o
 * entrega: a obrigação (por identidade econômica ou id), a data e o valor TOTAL
 * efetivamente liquidados, a origem e a referência externa quando houver, e os
 * componentes só se a origem os informou -- nunca rateados por estimativa.
 *
 * Não há campo de percentual nem de parcela: liquidação é fechada.
 */
final readonly class PuSettlementData
{
    /**
     * @param  array<string, string>|null  $components  componente => valor, só se a origem informou
     */
    public function __construct(
        public int $emissionId,
        public string $settlementDate,
        public string $amount,
        public PuSettlementSource $source,
        public ?PuObligationType $obligationType = null,
        public ?string $contractualDate = null,
        public int $sequence = 1,
        public ?int $obligationId = null,
        public ?string $externalReference = null,
        public ?array $components = null,
        public string $currency = 'BRL',
    ) {}

    public function ingestionKey(): ?string
    {
        $reference = $this->externalReference !== null ? trim($this->externalReference) : '';

        return $reference === '' ? null : $this->source->value.'|'.$reference;
    }
}
