<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Services\SalesBoards\SalesBoardPriorPositionResolver;
use Carbon\CarbonImmutable;

/**
 * A âncora de uma competência: a posição congelada da competência anterior,
 * contra a qual os fatos atrasados são apurados ({@see SalesBoardPriorPositionResolver}).
 *
 * É a competência imediatamente anterior que não foi cancelada, na versão da
 * publicação vigente -- ou na versão vigente do ciclo, se ela ainda não foi
 * publicada. Os meses cancelados entre a âncora e a competência ficam em
 * `skippedCancelledMonths`: os fatos deles são absorvidos pela competência
 * seguinte, que estende a janela dos movimentos até o fim da âncora.
 */
readonly class SalesBoardPriorPosition extends BaseDTO
{
    /**
     * @param  list<CarbonImmutable>  $skippedCancelledMonths  do mais recente para o mais antigo
     * @param  array<int, SalesBoardPriorLine>  $lines  indexado por `construction_unit_id`
     */
    public function __construct(
        public int $constructionId,
        public int $cycleId,
        public int $baselineId,
        public int $baselineVersion,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $positionDate,
        public bool $isPublished,
        public array $skippedCancelledMonths,
        public array $lines,
    ) {}

    public function line(int $unitId): ?SalesBoardPriorLine
    {
        return $this->lines[$unitId] ?? null;
    }

    /**
     * A competência da âncora como a operação a lê (`07/2026`).
     */
    public function label(): string
    {
        return $this->referenceMonth->format('m/Y');
    }

    public function versionLabel(): string
    {
        return 'V'.$this->baselineVersion;
    }

    /**
     * Há competências canceladas entre a âncora e a competência apurada.
     */
    public function skipsCancelledMonths(): bool
    {
        return $this->skippedCancelledMonths !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'construction_id' => $this->constructionId,
            'cycle_id' => $this->cycleId,
            'baseline_id' => $this->baselineId,
            'baseline_version' => $this->baselineVersion,
            'reference_month' => $this->label(),
            'position_date' => $this->positionDate->toDateString(),
            'is_published' => $this->isPublished,
            'skipped_cancelled_months' => array_map(
                static fn (CarbonImmutable $month): string => $month->format('m/Y'),
                $this->skippedCancelledMonths,
            ),
            'lines' => count($this->lines),
        ];
    }
}
