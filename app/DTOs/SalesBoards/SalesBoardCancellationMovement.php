<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardMovementTiming;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Um distrato ocorrido na competência -- ou, com `timing`, um distrato de
 * competência anterior que a competência recebeu (extemporâneo ou de
 * competência sem posição).
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
        public ?SalesBoardMovementTiming $timing = null,
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
            'timing' => $this->timing?->value,
        ];
    }
}
