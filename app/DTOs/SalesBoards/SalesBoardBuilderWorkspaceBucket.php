<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Support\Money\IntegerMoney;

/**
 * Um dos quatro baldes da posição, como a construtora o vê.
 */
readonly class SalesBoardBuilderWorkspaceBucket extends BaseDTO
{
    public function __construct(
        public string $label,
        public int $units,
        public ?int $valueCents,
    ) {}

    public function formattedValue(): string
    {
        return $this->valueCents === null ? '—' : 'R$ '.IntegerMoney::format($this->valueCents);
    }
}
