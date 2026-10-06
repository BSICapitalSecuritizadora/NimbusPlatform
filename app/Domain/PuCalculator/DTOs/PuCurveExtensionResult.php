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
        public ?string $purpose = null,
    ) {}

    /**
     * Sem versão vigente, ou com um passado que mudou numa curva comum, a curva
     * precisa ser gerada inteira -- como antes da extensão diária existir. Uma
     * curva governada que divergiu nunca entra aqui: a troca exige decisão humana.
     * A extensão OFICIAL nunca pede geração: sem curva oficial não há o que
     * estender, e nada a torna oficial além da homologação.
     */
    public function requiresFullGeneration(): bool
    {
        return $this->purpose !== PuCurveExtensionService::PURPOSE_OFFICIAL
            && in_array($this->action, [
                PuCurveExtensionService::ACTION_NO_CURRENT_VERSION,
                PuCurveExtensionService::ACTION_DIVERGED,
            ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'purpose' => $this->purpose,
            'action' => $this->action,
            'version_id' => $this->versionId,
            'appended_rows' => $this->appendedRows,
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'first_divergent_date' => $this->firstDivergentDate,
            'reason' => $this->reason,
        ];
    }
}
