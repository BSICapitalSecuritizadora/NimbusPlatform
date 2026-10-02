<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\BuilderReviewerType;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewAttachment;
use Carbon\CarbonImmutable;

/**
 * Como a resposta da construtora aparece na Validação e na Análise.
 *
 * Uma estrutura só para as duas telas: "registrada internamente por quem e
 * quando" e "resposta de quem, por qual canal, recebida em que dia", com os
 * arquivos. O caminho do arquivo no disco nunca sai daqui -- a tela recebe só
 * o nome, o tamanho e o link autenticado de download.
 *
 * Rodada enviada antes da exigência de evidência chega com `hasEvidence`
 * falso, e a tela avisa em vez de bloquear.
 */
readonly class SalesBoardBuilderResponseView extends BaseDTO
{
    /**
     * @param  list<array{id: int, name: string, size: string, url: string|null}>  $attachments
     */
    public function __construct(
        public bool $hasEvidence,
        public string $reviewerTypeLabel,
        public ?string $registeredByName,
        public ?CarbonImmutable $submittedAt,
        public ?string $respondentName,
        public ?string $respondentEmail,
        public ?string $channelLabel,
        public ?CarbonImmutable $receivedOn,
        public array $attachments,
    ) {}

    public static function fromReview(SalesBoardBuilderReview $review): self
    {
        $attachments = $review->attachments
            ->map(fn (SalesBoardBuilderReviewAttachment $attachment): array => [
                'id' => (int) $attachment->getKey(),
                'name' => (string) $attachment->original_name,
                'size' => $attachment->humanSize(),
                'url' => $attachment->isAvailable() ? $attachment->downloadUrl() : null,
            ])
            ->values()
            ->all();

        return new self(
            hasEvidence: $review->hasBuilderResponseEvidence() && ($attachments !== []),
            reviewerTypeLabel: BuilderReviewerType::tryFrom((string) $review->reviewer_type)?->label()
                ?? BuilderReviewerType::InternalPreview->label(),
            registeredByName: $review->reviewer_name,
            submittedAt: $review->submitted_at,
            respondentName: $review->builder_respondent_name,
            respondentEmail: $review->builder_respondent_email,
            channelLabel: $review->builder_response_channel?->label(),
            receivedOn: $review->builder_response_received_on,
            attachments: $attachments,
        );
    }
}
