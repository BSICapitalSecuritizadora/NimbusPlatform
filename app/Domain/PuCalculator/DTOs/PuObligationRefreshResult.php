<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * Resumo de uma atualização das obrigações pela curva oficial.
 */
final readonly class PuObligationRefreshResult
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $unmatchedInformedDates
     * @param  list<int>  $settledWithChangedCalculation
     */
    public function __construct(
        public ?string $calculationVersion,
        public array $counts = [],
        public array $unmatchedInformedDates = [],
        public array $settledWithChangedCalculation = [],
    ) {}

    public function count(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    public function changedAnything(): bool
    {
        return array_sum($this->counts) > 0;
    }
}
