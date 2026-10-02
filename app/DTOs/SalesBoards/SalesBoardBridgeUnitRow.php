<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardUnitClassification;

/**
 * Uma unidade que a ponte não explica: estava num balde na competência
 * anterior e está em outro nesta, sem venda, distrato, quitação, permuta,
 * inclusão ou baixa que leve de um ao outro.
 *
 * `null` na classificação é "fora do inventário" -- a unidade não estava, ou
 * não está mais, na posição.
 */
readonly class SalesBoardBridgeUnitRow extends BaseDTO
{
    public function __construct(
        public int $constructionUnitId,
        public string $unitLabel,
        public ?SalesBoardUnitClassification $previousClassification,
        public ?SalesBoardUnitClassification $currentClassification,
        public ?string $previousContractCode,
        public ?string $currentContractCode,
        public ?string $note = null,
        public ?string $block = null,
        public ?string $unit = null,
    ) {}

    public function previousLabel(): string
    {
        return $this->previousClassification?->label() ?? 'Fora do inventário';
    }

    public function currentLabel(): string
    {
        return $this->currentClassification?->label() ?? 'Fora do inventário';
    }
}
