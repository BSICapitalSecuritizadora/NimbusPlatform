<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;

/**
 * O indexador não tem homologação operacional para o degrau pedido (Fase 6).
 *
 * Recusa estruturada, não silêncio: nada troca o indexador pelo CDI, repete o
 * último índice, calcula zero ou grava versão oficial. Estende a recusa de
 * governança porque é assim que as telas e os comandos já mostram uma recusa de
 * negócio.
 */
class PuIndexerCapabilityException extends PuCurveGovernanceException
{
    public const REASON_NOT_HOMOLOGATED = 'INDEXER_NOT_OPERATIONALLY_HOMOLOGATED';

    public const REASON_UNKNOWN = 'INDEXER_UNKNOWN';

    public function __construct(
        public readonly string $reasonCode,
        public readonly ?PuIndexer $indexer,
        public readonly PuIndexerCapability $capability,
        public readonly ?int $emissionId,
        public readonly string $nextAction,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'reason' => $this->reasonCode,
            'indexer' => $this->indexer?->value,
            'capability' => $this->capability->value,
            'emission_id' => $this->emissionId,
            'next_action' => $this->nextAction,
        ];
    }
}
