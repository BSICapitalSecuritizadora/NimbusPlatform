<?php

declare(strict_types=1);

namespace App\DTOs\Documents;

use App\DTOs\BaseDTO;

/**
 * Dados informados uma única vez e aplicados a todos os documentos do lote.
 *
 * Não há campo de publicação aqui de propósito: todo documento do lote nasce
 * como rascunho (`is_published` e `is_public` falsos), e a publicação continua
 * sendo uma ação explícita por documento na listagem.
 */
readonly class DocumentBatchDefaults extends BaseDTO
{
    /**
     * @param  array<int, int>  $emissionIds
     */
    public function __construct(
        public string $category,
        public array $emissionIds = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromFormData(array $data): self
    {
        return new self(
            category: (string) ($data['category'] ?? ''),
            emissionIds: array_values(array_filter(array_map(
                static fn (mixed $id): int => (int) $id,
                (array) ($data['emissions'] ?? []),
            ))),
        );
    }
}
