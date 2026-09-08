<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ContractSettlementState;
use Carbon\CarbonImmutable;

/**
 * Se um contrato estava quitado numa data, e a contagem que sustenta a resposta.
 *
 * Os contadores acompanham o estado porque "quitado" sem "3 de 3 parcelas pagas"
 * é uma afirmação que ninguém consegue conferir.
 */
readonly class ContractSettlementSummary extends BaseDTO
{
    public function __construct(
        public int $contractId,
        public CarbonImmutable $positionDate,
        public ContractSettlementState $state,
        public int $validInstallments,
        public int $paidInstallments,
    ) {}

    public function isSettled(): bool
    {
        return $this->state === ContractSettlementState::Settled;
    }

    public function isOutstanding(): bool
    {
        return $this->state === ContractSettlementState::Outstanding;
    }

    public function isUndetermined(): bool
    {
        return $this->state === ContractSettlementState::Undetermined;
    }
}
