<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardMovementType;

/**
 * Tudo o que mudou num movimento entre duas versões.
 *
 * Um movimento que entrou ou saiu é tão importante quanto um que mudou de valor:
 * uma venda que apareceu na competência depois de a construtora conferir o mês,
 * ou um distrato que sumiu, alteram a história do período mesmo quando os
 * totais do fechamento continuam idênticos.
 */
readonly class SalesBoardMovementDiff extends BaseDTO
{
    /**
     * @param  list<SalesBoardDiffChange>  $changes
     */
    public function __construct(
        public SalesBoardMovementType $type,
        public int $contractId,
        public ?string $contractCode,
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
            'movement_type' => $this->type->value,
            'contract_id' => $this->contractId,
            'contract_code' => $this->contractCode,
            'construction_unit_id' => $this->constructionUnitId,
            'block' => $this->block,
            'unit' => $this->unit,
            'changes' => array_map(fn (SalesBoardDiffChange $change): array => $change->toArray(), $this->changes),
        ];
    }
}
