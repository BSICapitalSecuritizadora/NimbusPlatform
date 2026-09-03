<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardPositionStatus;
use App\Models\SalesBoard;
use Carbon\CarbonImmutable;

/**
 * Posição do quadro de vendas de um empreendimento numa competência.
 *
 * `positionDate` é a competência consultada; `referenceMonthUsed` é a
 * competência do quadro que respondeu por ela. As duas divergem quando o
 * empreendimento não atualizou o quadro no mês e a última posição conhecida foi
 * transportada — é isso, e não um zero, que representa quem apenas atrasou o
 * envio.
 */
readonly class ConstructionSalesPosition extends BaseDTO
{
    public function __construct(
        public int $constructionId,
        public ?string $constructionName,
        public CarbonImmutable $positionDate,
        public SalesBoardPositionStatus $status,
        public ?CarbonImmutable $referenceMonthUsed,
        public ?SalesBoard $salesBoard,
        public int $stockUnits,
        public int $financedUnits,
        public int $paidUnits,
        public int $exchangedUnits,
        public int $totalUnits,
        public float $stockValue,
        public float $financedValue,
        public float $paidValue,
        public float $exchangedValue,
    ) {}

    public static function fromSalesBoard(
        int $constructionId,
        ?string $constructionName,
        CarbonImmutable $positionDate,
        CarbonImmutable $referenceMonthUsed,
        SalesBoard $salesBoard,
    ): self {
        return new self(
            constructionId: $constructionId,
            constructionName: $constructionName,
            positionDate: $positionDate,
            status: $referenceMonthUsed->equalTo($positionDate)
                ? SalesBoardPositionStatus::Current
                : SalesBoardPositionStatus::CarriedForward,
            referenceMonthUsed: $referenceMonthUsed,
            salesBoard: $salesBoard,
            stockUnits: (int) $salesBoard->stock_units,
            financedUnits: (int) $salesBoard->financed_units,
            paidUnits: (int) $salesBoard->paid_units,
            exchangedUnits: (int) $salesBoard->exchanged_units,
            totalUnits: (int) $salesBoard->total_units,
            stockValue: (float) $salesBoard->stock_value,
            financedValue: (float) $salesBoard->financed_value,
            paidValue: (float) $salesBoard->paid_value,
            exchangedValue: (float) $salesBoard->exchanged_value,
        );
    }

    /**
     * Ausência explícita: o empreendimento não tem posição na competência e
     * nada é somado por ele. Zerar os campos aqui não é assumir estoque zero —
     * é o {@see self::$status} que responde por isso, e ele nunca entra na soma.
     */
    public static function absent(
        int $constructionId,
        ?string $constructionName,
        CarbonImmutable $positionDate,
        SalesBoardPositionStatus $status,
    ): self {
        return new self(
            constructionId: $constructionId,
            constructionName: $constructionName,
            positionDate: $positionDate,
            status: $status,
            referenceMonthUsed: null,
            salesBoard: null,
            stockUnits: 0,
            financedUnits: 0,
            paidUnits: 0,
            exchangedUnits: 0,
            totalUnits: 0,
            stockValue: 0.0,
            financedValue: 0.0,
            paidValue: 0.0,
            exchangedValue: 0.0,
        );
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    public function wasCarriedForward(): bool
    {
        return $this->status === SalesBoardPositionStatus::CarriedForward;
    }

    public function referenceMonthUsedDate(): ?string
    {
        return $this->referenceMonthUsed?->toDateString();
    }

    public function referenceMonthUsedLabel(): ?string
    {
        return $this->referenceMonthUsed?->format('m/Y');
    }
}
