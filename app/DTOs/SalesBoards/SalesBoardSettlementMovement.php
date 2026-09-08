<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Um contrato que passou a estar quitado dentro da competência.
 *
 * Derivado da transição do estado de quitação entre o dia anterior ao início do
 * mês e o fim dele, nunca de `Contract.status`: o status não guarda quando a
 * quitação aconteceu.
 */
readonly class SalesBoardSettlementMovement extends BaseDTO
{
    public function __construct(
        public int $contractId,
        public ?string $contractCode,
        public int $constructionUnitId,
        public ?string $block,
        public ?string $unit,
        public ?CarbonImmutable $saleDate,
        public ?int $saleValueCents,
        public int $installments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_id' => $this->contractId,
            'contract_code' => $this->contractCode,
            'construction_unit_id' => $this->constructionUnitId,
            'block' => $this->block,
            'unit' => $this->unit,
            'sale_date' => $this->saleDate?->toDateString(),
            'sale_value' => $this->saleValueCents === null ? null : IntegerMoney::format($this->saleValueCents),
            'installments' => $this->installments,
        ];
    }
}
