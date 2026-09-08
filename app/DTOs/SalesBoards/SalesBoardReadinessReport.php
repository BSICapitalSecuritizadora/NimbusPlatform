<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use Carbon\CarbonImmutable;

/**
 * Se a competência de um empreendimento pode ser automatizada, e por quê não.
 *
 * Não é opinião nem nota de corte: é a lista de evidências. `isReady` é
 * verdadeiro exatamente quando não sobrou nenhum bloqueador -- ou seja, quando
 * todos os quatro baldes conseguem ser explicados com os dados que existem.
 */
readonly class SalesBoardReadinessReport extends BaseDTO
{
    /**
     * @param  list<SalesBoardIssue>  $blockingIssues
     * @param  list<SalesBoardIssue>  $warnings
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public int $constructionId,
        public ?string $constructionName,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $positionDate,
        public array $blockingIssues,
        public array $warnings,
        public array $metrics,
        public SalesBoardLegacyComparison $legacyComparison,
    ) {}

    public function isReady(): bool
    {
        return $this->blockingIssues === [];
    }

    /**
     * Bloqueadores agrupados por código, que é como o operador lê: "12 unidades
     * em estoque sem valor" é acionável, doze linhas iguais não são.
     *
     * @return array<string, int>
     */
    public function blockingIssueCounts(): array
    {
        $counts = [];

        foreach ($this->blockingIssues as $issue) {
            $counts[$issue->code->value] = ($counts[$issue->code->value] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function warningCounts(): array
    {
        $counts = [];

        foreach ($this->warnings as $warning) {
            $counts[$warning->code->value] = ($counts[$warning->code->value] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'construction_id' => $this->constructionId,
            'construction' => $this->constructionName,
            'reference_month' => $this->referenceMonth->format('m/Y'),
            'position_date' => $this->positionDate->toDateString(),
            'is_ready' => $this->isReady(),
            'blocking_issues' => array_map(fn (SalesBoardIssue $issue): array => $issue->toArray(), $this->blockingIssues),
            'warnings' => array_map(fn (SalesBoardIssue $issue): array => $issue->toArray(), $this->warnings),
            'metrics' => $this->metrics,
            'legacy_comparison' => $this->legacyComparison->toArray(),
        ];
    }
}
