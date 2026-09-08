<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardIssueSeverity;

/**
 * Um achado da derivação, preso ao que o originou.
 *
 * Nada aqui é persistido nesta fase.
 */
readonly class SalesBoardIssue extends BaseDTO
{
    public function __construct(
        public SalesBoardIssueCode $code,
        public string $message,
        public ?int $constructionUnitId = null,
        public ?int $contractId = null,
        public ?string $contractCode = null,
    ) {}

    public function severity(): SalesBoardIssueSeverity
    {
        return $this->code->severity();
    }

    public function isBlocker(): bool
    {
        return $this->severity() === SalesBoardIssueSeverity::Blocker;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity()->value,
            'message' => $this->message,
            'construction_unit_id' => $this->constructionUnitId,
            'contract_id' => $this->contractId,
            'contract_code' => $this->contractCode,
        ];
    }
}
