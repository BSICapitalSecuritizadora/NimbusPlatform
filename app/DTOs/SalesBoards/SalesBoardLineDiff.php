<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;

/**
 * Tudo o que mudou numa unidade entre duas versões.
 *
 * Agrupado por unidade, e não por campo, porque é assim que a conferência
 * acontece: alguém abre a unidade 101 e quer ver de uma vez que ela trocou de
 * classificação **e** de contrato, em vez de encontrar as duas informações em
 * listas separadas.
 */
readonly class SalesBoardLineDiff extends BaseDTO
{
    /**
     * @param  list<SalesBoardDiffChange>  $changes
     */
    public function __construct(
        public int $constructionUnitId,
        public ?string $block,
        public ?string $unit,
        public array $changes,
    ) {}

    public function displayName(): string
    {
        return trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');
    }

    public function hasMaterialChange(): bool
    {
        return collect($this->changes)->contains(fn (SalesBoardDiffChange $change): bool => ! $change->isSourceOnly());
    }

    public function describe(): string
    {
        return collect($this->changes)
            ->map(fn (SalesBoardDiffChange $change): string => $change->describe())
            ->implode('; ');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'construction_unit_id' => $this->constructionUnitId,
            'block' => $this->block,
            'unit' => $this->unit,
            'changes' => array_map(fn (SalesBoardDiffChange $change): array => $change->toArray(), $this->changes),
        ];
    }
}
