<?php

declare(strict_types=1);

namespace App\DTOs\Documents;

use App\DTOs\BaseDTO;
use App\Enums\DocumentBatchItemStatus;

/**
 * Resultado consolidado de um lote, incluindo sucesso parcial.
 */
readonly class DocumentBatchResult extends BaseDTO
{
    /**
     * @param  array<int, DocumentBatchItemOutcome>  $outcomes
     */
    public function __construct(public array $outcomes) {}

    /**
     * @return array<int, DocumentBatchItemOutcome>
     */
    public function withStatus(DocumentBatchItemStatus $status): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (DocumentBatchItemOutcome $outcome): bool => $outcome->status === $status,
        ));
    }

    public function countWithStatus(DocumentBatchItemStatus $status): int
    {
        return count($this->withStatus($status));
    }

    public function createdCount(): int
    {
        return $this->countWithStatus(DocumentBatchItemStatus::Created);
    }

    /**
     * Sucesso parcial: pelo menos um documento criado e pelo menos um item que
     * não chegou a virar documento.
     */
    public function isPartialSuccess(): bool
    {
        $created = $this->createdCount();

        return $created > 0 && $created < count($this->outcomes);
    }

    /**
     * @return array<int, int>
     */
    public function createdDocumentIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (DocumentBatchItemOutcome $outcome): ?int => $outcome->documentId,
            $this->withStatus(DocumentBatchItemStatus::Created),
        )));
    }
}
