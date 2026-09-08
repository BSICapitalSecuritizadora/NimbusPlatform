<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardApprovalOutcome;
use App\Models\SalesBoard;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;

/**
 * O que a aprovação produziu.
 *
 * `AlreadyApproved` traz a mesma publicação e o mesmo quadro da primeira
 * chamada. Um clique duplo não é erro, e devolver exceção nesse caso obrigaria a
 * tela a distinguir "falhou" de "já estava feito" pelo texto da mensagem.
 */
readonly class SalesBoardApprovalResult extends BaseDTO
{
    public function __construct(
        public SalesBoardApprovalOutcome $outcome,
        public SalesBoardManagementReview $review,
        public SalesBoardPublication $publication,
        public SalesBoard $salesBoard,
        public SalesBoardPublicationPayload $payload,
    ) {}

    public function wasPublishedNow(): bool
    {
        return $this->outcome === SalesBoardApprovalOutcome::Approved;
    }

    public function message(): string
    {
        return $this->wasPublishedNow()
            ? sprintf(
                'Quadro de Vendas de %s publicado para a competência %s.',
                (string) ($this->payload->constructionName ?? '—'),
                $this->payload->referenceMonth->format('m/Y'),
            )
            : sprintf(
                'A competência %s já havia sido aprovada e publicada.',
                $this->payload->referenceMonth->format('m/Y'),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'management_review_id' => $this->review->getKey(),
            'publication_id' => $this->publication->getKey(),
            'sales_board_id' => $this->salesBoard->getKey(),
            'payload' => $this->payload->toArray(),
        ];
    }
}
