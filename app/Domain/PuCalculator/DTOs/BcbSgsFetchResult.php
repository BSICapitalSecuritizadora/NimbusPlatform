<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * Resultado consolidado de uma consulta em blocos à série SGS do Banco Central.
 *
 * Reúne os pontos retornados (já deduplicados por data) e as falhas por bloco, permitindo distinguir
 * uma sincronização parcial (alguns blocos falharam) de uma sincronização completa.
 */
final readonly class BcbSgsFetchResult
{
    /**
     * @param  list<BcbSgsRateData>  $rates
     * @param  list<BcbSgsBlockFailure>  $blockFailures
     * @param  list<BcbSgsRawPayload>  $rawPayloads
     * @param  list<array<string, mixed>>  $invalidEntries
     * @param  list<array<string, mixed>>  $duplicateDates
     */
    public function __construct(
        public array $rates,
        public array $blockFailures,
        public int $blocksTotal,
        public array $rawPayloads = [],
        public array $invalidEntries = [],
        public array $duplicateDates = [],
    ) {}

    public function blocksFailed(): int
    {
        return count($this->blockFailures);
    }

    public function blocksSucceeded(): int
    {
        return $this->blocksTotal - $this->blocksFailed();
    }

    public function hasBlockFailures(): bool
    {
        return $this->blockFailures !== [];
    }
}
