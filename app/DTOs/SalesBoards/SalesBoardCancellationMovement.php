<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Um distrato ocorrido na competência.
 */
readonly class SalesBoardCancellationMovement extends BaseDTO
{
    public function __construct(
        public int $contractId,
        public ?string $contractCode,
        public int $constructionUnitId,
        public ?string $block,
        public ?string $unit,
        public ?CarbonImmutable $saleDate,
        public ?int $saleValueCents,
        public CarbonImmutable $cancellationDate,
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
            'cancellation_date' => $this->cancellationDate->toDateString(),
        ];
    }
}
