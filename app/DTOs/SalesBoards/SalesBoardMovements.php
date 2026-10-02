<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesPriceConformityStatus;

/**
 * O que aconteceu na competência: vendas, quitações e distratos.
 *
 * Movimento não é posição. Uma venda feita e distratada dentro do mesmo mês não
 * aparece em nenhum balde no fechamento, mas aconteceu duas vezes aqui -- e a
 * lista que a escondesse estaria mentindo sobre o mês.
 *
 * As listas também carregam o que a competência recebeu de antes dela -- os
 * movimentos com `timing` ({@see SalesBoardMovementTiming}). As contagens
 * separam os dois, e a revisão de venda publicada não conta como venda.
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

    /**
     * As vendas da competência -- as do mês e as de competência anterior. A
     * revisão de venda publicada é a mesma venda de antes e só entra com
     * `$includeRevisions`.
     */
    public function salesCount(bool $includeRevisions = false): int
    {
        return count(array_filter(
            $this->sales,
            fn (SalesBoardSaleMovement $sale): bool => $includeRevisions || ($sale->timing?->countsAsNewSale() ?? true),
        ));
    }

    /**
     * Só os movimentos do mês, sem os de competência anterior.
     */
    public function inMonth(): self
    {
        return new self(
            sales: array_values(array_filter($this->sales, fn (SalesBoardSaleMovement $sale): bool => $sale->timing === null)),
            settlements: array_values(array_filter($this->settlements, fn (SalesBoardSettlementMovement $settlement): bool => $settlement->timing === null)),
            cancellations: array_values(array_filter($this->cancellations, fn (SalesBoardCancellationMovement $cancellation): bool => $cancellation->timing === null)),
        );
    }

    /**
     * Só os movimentos de competência anterior: extemporâneos, revisões de venda
     * publicada e os de competência sem posição.
     */
    public function fromEarlierCompetences(): self
    {
        return new self(
            sales: array_values(array_filter($this->sales, fn (SalesBoardSaleMovement $sale): bool => $sale->timing !== null)),
            settlements: array_values(array_filter($this->settlements, fn (SalesBoardSettlementMovement $settlement): bool => $settlement->timing !== null)),
            cancellations: array_values(array_filter($this->cancellations, fn (SalesBoardCancellationMovement $cancellation): bool => $cancellation->timing !== null)),
        );
    }

    /**
     * Quantos movimentos vieram de competência anterior, de qualquer tipo.
     */
    public function lateCount(): int
    {
        $earlier = $this->fromEarlierCompetences();

        return count($earlier->sales) + count($earlier->settlements) + count($earlier->cancellations);
    }

    /**
     * Contagem por timing, com o do mês em `no_mes`.
     *
     * @return array<string, int>
     */
    public function countsByTiming(): array
    {
        $counts = ['no_mes' => 0];

        foreach (SalesBoardMovementTiming::cases() as $timing) {
            $counts[$timing->value] = 0;
        }

        foreach ([...$this->sales, ...$this->settlements, ...$this->cancellations] as $movement) {
            $counts[$movement->timing?->value ?? 'no_mes']++;
        }

        return $counts;
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
