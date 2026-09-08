<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use Carbon\CarbonImmutable;

/**
 * Um alvo devido, antes de virar linha no banco.
 *
 * Existe para que a descoberta possa ser executada sem escrever nada -- é o que
 * torna o `--dry-run` um dry-run de verdade, e não uma execução que grava e
 * depois desfaz.
 */
readonly class SalesBoardAutomationTargetCandidate extends BaseDTO
{
    public function __construct(
        public int $constructionId,
        public CarbonImmutable $referenceMonth,
        public CarbonImmutable $dueDate,
        public bool $autoOpenBuilderReview,
    ) {}

    /**
     * A identidade do alvo, na mesma composição da unique da tabela.
     */
    public function key(): string
    {
        return $this->constructionId.'@'.$this->referenceMonth->format('Y-m');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'construction_id' => $this->constructionId,
            'reference_month' => $this->referenceMonth->format('Y-m'),
            'due_date' => $this->dueDate->toDateString(),
            'auto_open_builder_review' => $this->autoOpenBuilderReview,
        ];
    }
}
