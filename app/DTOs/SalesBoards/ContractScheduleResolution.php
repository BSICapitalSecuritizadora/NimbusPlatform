<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Services\SalesBoards\ContractSettlementResolver;

/**
 * O que o {@see ContractSettlementResolver} apura do cronograma de um conjunto
 * de contratos, numa leitura só: a quitação em cada data pedida e os fatos que
 * não dependem de data.
 */
readonly class ContractScheduleResolution extends BaseDTO
{
    /**
     * @param  array<string, array<int, ContractSettlementSummary>>  $summaries  `Y-m-d` => contrato => resumo
     * @param  array<int, ContractScheduleFacts>  $facts  indexado por `contract_id`
     */
    public function __construct(
        public array $summaries,
        public array $facts,
    ) {}

    /**
     * @return array<int, ContractSettlementSummary>
     */
    public function summariesAt(string $day): array
    {
        return $this->summaries[$day] ?? [];
    }

    public function factsFor(int $contractId): ?ContractScheduleFacts
    {
        return $this->facts[$contractId] ?? null;
    }
}
