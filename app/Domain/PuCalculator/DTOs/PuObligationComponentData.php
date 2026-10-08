<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationComponentOwner;
use App\Domain\PuCalculator\Enums\PuObligationComponentStatus;

/**
 * Um componente do valor esperado ainda em memória: valor monetário canônico (2
 * casas) ou nulo quando não suportado, com dono e origem rastreáveis.
 */
final readonly class PuObligationComponentData
{
    /**
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        public PuObligationComponent $component,
        public PuObligationComponentOwner $owner,
        public PuObligationComponentStatus $status,
        public ?string $amount,
        public ?string $unitAmount = null,
        public ?string $quantity = null,
        public ?string $reason = null,
        public array $source = [],
    ) {}

    public function isUnsupported(): bool
    {
        return $this->status === PuObligationComponentStatus::Unsupported;
    }

    /**
     * Parte semântica do componente (entra no fingerprint do cálculo).
     *
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'component' => $this->component->value,
            'owner' => $this->owner->value,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'unit_amount' => $this->unitAmount,
            'quantity' => $this->quantity,
            'reason' => $this->reason,
        ];
    }
}
