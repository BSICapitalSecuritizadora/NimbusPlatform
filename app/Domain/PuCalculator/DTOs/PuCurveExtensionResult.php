<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Services\PuCurveExtensionService;

final readonly class PuCurveExtensionResult
{
    public function __construct(
        public string $action,
        public ?int $versionId = null,
        public int $appendedRows = 0,
        public ?string $fromDate = null,
        public ?string $toDate = null,
        public ?string $firstDivergentDate = null,
        public ?string $reason = null,
    ) {}

    /**
     * Sem versão vigente, ou com um passado que mudou numa curva comum, a curva
     * precisa ser gerada inteira -- como antes da extensão diária existir. Uma
     * curva governada que divergiu nunca entra aqui: a troca exige decisão humana.
     */
    public function requiresFullGeneration(): bool
    {
        return in_array($this->action, [
            PuCurveExtensionService::ACTION_NO_CURRENT_VERSION,
            PuCurveExtensionService::ACTION_DIVERGED,
        ], true);
    }
}
