<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ContractSettlementState;
use App\Enums\SalesBoardUnitClassification;

/**
 * Uma unidade como a competência anterior a congelou -- o mínimo que a
 * derivação da competência seguinte precisa para saber o que já foi apurado.
 *
 * Só fatos congelados: a classificação, o contrato que ocupava a unidade com o
 * valor e a data da venda daquele dia, a permuta e o estado da quitação. A
 * comparação com a fonte viva é da derivação; esta linha não sabe nada do
 * mundo depois do congelamento.
 */
readonly class SalesBoardPriorLine extends BaseDTO
{
    public function __construct(
        public int $unitId,
        public SalesBoardUnitClassification $classification,
        public ?int $contractId,
        public ?string $contractCode,
        public ?int $saleValueCents,
        public ?string $saleDate,
        public ?int $exchangeId,
        public ?ContractSettlementState $settlementState,
        public ?string $block,
        public ?string $unit,
        public ?int $bucketValueCents = null,
        public ?string $exchangeEndedOn = null,
    ) {}

    /**
     * O contrato desta linha é o de uma venda que a competência anterior já
     * contou -- financiada ou quitada.
     */
    public function holdsSale(): bool
    {
        return in_array($this->classification, [SalesBoardUnitClassification::Financed, SalesBoardUnitClassification::Settled], true)
            && ($this->contractId !== null);
    }

    public function displayName(): string
    {
        return trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');
    }
}
