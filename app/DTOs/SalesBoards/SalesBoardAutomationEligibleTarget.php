<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use Carbon\CarbonImmutable;

/**
 * Um empreendimento habilitado para automação.
 *
 * `startReferenceMonth` é obrigatório, e é a peça que impede a automação de
 * varrer o passado. Sem ela, "habilitar" um empreendimento com três anos de
 * histórico significaria gerar trinta e seis competências que ninguém pediu --
 * cada uma com derivação completa, e cada uma virando posição congelada.
 */
readonly class SalesBoardAutomationEligibleTarget extends BaseDTO
{
    public function __construct(
        public int $constructionId,
        public CarbonImmutable $startReferenceMonth,
        public bool $autoOpenBuilderReview = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'construction_id' => $this->constructionId,
            'start_reference_month' => $this->startReferenceMonth->format('Y-m'),
            'auto_open_builder_review' => $this->autoOpenBuilderReview,
        ];
    }
}
