<?php

declare(strict_types=1);

namespace App\DTOs\Documents;

use App\DTOs\BaseDTO;

/**
 * Inspeção de um arquivo do lote, antes de qualquer gravação.
 *
 * A mesma inspeção alimenta a etapa de conferência (onde é só informativa) e o
 * processamento (onde decide se o arquivo é rejeitado). O estado do formulário
 * é controlável pelo cliente, então o que aparece na conferência nunca é
 * reaproveitado como decisão: o processamento reexecuta a análise.
 */
readonly class DocumentBatchFileAnalysis extends BaseDTO
{
    /**
     * @param  string|null  $error  Motivo da rejeição, quando houver.
     * @param  string|null  $duplicateOfKey  Chave do arquivo idêntico já presente no lote.
     * @param  string|null  $duplicateWarning  Advertência de possível duplicidade no acervo já cadastrado.
     */
    public function __construct(
        public string $key,
        public string $originalName,
        public string $extension,
        public int $sizeBytes,
        public ?string $error = null,
        public ?string $duplicateOfKey = null,
        public ?string $duplicateWarning = null,
    ) {}

    public function isRejected(): bool
    {
        return $this->error !== null;
    }

    public function isDuplicatedInBatch(): bool
    {
        return $this->duplicateOfKey !== null;
    }

    public function isProcessable(): bool
    {
        return ! $this->isRejected() && ! $this->isDuplicatedInBatch();
    }
}
