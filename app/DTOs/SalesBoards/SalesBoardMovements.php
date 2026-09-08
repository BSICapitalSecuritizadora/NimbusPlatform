<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesPriceConformityStatus;

/**
 * O que aconteceu na competência: vendas, quitações e distratos.
 *
 * Movimento não é posição. Uma venda feita e distratada dentro do mesmo mês não
 * aparece em nenhum balde no fechamento, mas aconteceu duas vezes aqui -- e a
 * lista que a escondesse estaria mentindo sobre o mês.
 */
readonly class SalesBoardMovements extends BaseDTO
{
    /**
     * @param  list<SalesBoardSaleMovement>  $sales
     * @param  list<SalesBoardSettlementMovement>  $settlements
     * @param  list<SalesBoardCancellationMovement>  $cancellations
     */
    public function __construct(
        public array $sales,
        public array $settlements,
        public array $cancellations,
    ) {}

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public function salesCount(): int
    {
        return count($this->sales);
    }

    public function settlementsCount(): int
    {
        return count($this->settlements);
    }

    public function cancellationsCount(): int
    {
        return count($this->cancellations);
    }

    /**
     * @return list<SalesBoardSaleMovement>
     */
    public function salesWithStatus(SalesPriceConformityStatus $status): array
    {
        return array_values(array_filter(
            $this->sales,
            fn (SalesBoardSaleMovement $sale): bool => $sale->conformity->status === $status,
        ));
    }

    public function conformSalesCount(): int
    {
        return count($this->salesWithStatus(SalesPriceConformityStatus::Conform));
    }

    public function nonConformSalesCount(): int
    {
        return count($this->salesWithStatus(SalesPriceConformityStatus::NonConform));
    }

    public function undeterminedSalesCount(): int
    {
        return count($this->salesWithStatus(SalesPriceConformityStatus::Undetermined));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sales' => array_map(fn (SalesBoardSaleMovement $sale): array => $sale->toArray(), $this->sales),
            'settlements' => array_map(fn (SalesBoardSettlementMovement $settlement): array => $settlement->toArray(), $this->settlements),
            'cancellations' => array_map(fn (SalesBoardCancellationMovement $cancellation): array => $cancellation->toArray(), $this->cancellations),
        ];
    }
}
