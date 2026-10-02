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

    /**
     * A retificação diz que o quadro publicado foi atualizado, e não criado: a
     * Gestão precisa saber que a posição anterior ficou no histórico.
     */
    public function message(): string
    {
        if (! $this->wasPublishedNow()) {
            return sprintf(
                'A competência %s já havia sido aprovada e publicada.',
                $this->payload->referenceMonth->format('m/Y'),
            );
        }

        if ($this->publication->isRectification()) {
            return sprintf(
                'Retificação publicada: o Quadro de Vendas de %s na competência %s foi atualizado (%s). A posição anterior continua no histórico de versões.',
                (string) ($this->payload->constructionName ?? '—'),
                $this->payload->referenceMonth->format('m/Y'),
                mb_strtolower($this->publication->sequenceLabel()),
            );
        }

        return sprintf(
            'Quadro de Vendas de %s publicado para a competência %s.',
            (string) ($this->payload->constructionName ?? '—'),
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
