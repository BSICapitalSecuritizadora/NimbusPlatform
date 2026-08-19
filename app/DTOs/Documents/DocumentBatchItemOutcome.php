<?php

declare(strict_types=1);

namespace App\DTOs\Documents;

use App\DTOs\BaseDTO;
use App\Enums\DocumentBatchItemStatus;

/**
 * Desfecho de um arquivo depois do processamento, com o motivo individual da
 * falha quando ela ocorre.
 */
readonly class DocumentBatchItemOutcome extends BaseDTO
{
    public function __construct(
        public string $key,
        public string $originalName,
        public string $title,
        public DocumentBatchItemStatus $status,
        public ?string $reason = null,
        public ?int $documentId = null,
        public ?string $duplicateWarning = null,
    ) {}

    /**
     * Forma serializável para o estado do componente Livewire, que precisa
     * sobreviver ao roundtrip entre o resumo e o reprocessamento.
     *
     * @return array{
     *     key: string,
     *     original_name: string,
     *     title: string,
     *     status: string,
     *     status_label: string,
     *     status_color: string,
     *     reason: ?string,
     *     document_id: ?int,
     *     duplicate_warning: ?string,
     *     is_retryable: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'original_name' => $this->originalName,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'reason' => $this->reason,
            'document_id' => $this->documentId,
            'duplicate_warning' => $this->duplicateWarning,
            'is_retryable' => $this->status->isRetryable(),
        ];
    }
}
