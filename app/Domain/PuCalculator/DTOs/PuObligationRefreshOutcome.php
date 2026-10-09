<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;

/**
 * O que uma execução da atualização durável das obrigações fez (Fase 6).
 *
 *  - `nothing_to_do`: nenhum pedido elegível (já atendido, ou outro executor o
 *    reservou, ou a nova tentativa ainda não venceu);
 *  - `succeeded`: as obrigações foram recompostas a partir do estado governado
 *    vigente; os pedidos reservados e os cobertos foram encerrados;
 *  - `retry_scheduled` / `exhausted` / `blocked`: a falha ficou registrada nos
 *    pedidos, com a categoria e o que acontece a seguir.
 */
final readonly class PuObligationRefreshOutcome
{
    public const NOTHING_TO_DO = 'nothing_to_do';

    public const SUCCEEDED = 'succeeded';

    public const RETRY_SCHEDULED = 'retry_scheduled';

    public const EXHAUSTED = 'exhausted';

    public const BLOCKED = 'blocked';

    /**
     * @param  list<int>  $claimedRequestIds
     * @param  list<int>  $coveredRequestIds
     */
    public function __construct(
        public int $emissionId,
        public string $via,
        public string $status,
        public array $claimedRequestIds = [],
        public array $coveredRequestIds = [],
        public int $attempts = 0,
        public ?PuOperationalFailureCategory $failureCategory = null,
        public ?string $message = null,
        public ?PuObligationRefreshResult $result = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function failed(): bool
    {
        return in_array($this->status, [self::RETRY_SCHEDULED, self::EXHAUSTED, self::BLOCKED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'via' => $this->via,
            'status' => $this->status,
            'claimed_request_ids' => $this->claimedRequestIds,
            'covered_request_ids' => $this->coveredRequestIds,
            'attempts' => $this->attempts,
            'failure_category' => $this->failureCategory?->value,
            'message' => $this->message,
            'result' => $this->result?->counts,
        ];
    }
}
