<?php

declare(strict_types=1);

namespace App\DTOs\Documents;

use App\DTOs\BaseDTO;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Um arquivo do lote, já pareado com o título revisado pelo usuário.
 *
 * A `$key` é a chave do arquivo no estado do `FileUpload` do Filament e é o que
 * liga a linha da conferência ao arquivo de verdade — o resumo final e o
 * reprocessamento dos que falharam também são endereçados por ela.
 */
readonly class DocumentBatchItem extends BaseDTO
{
    public function __construct(
        public string $key,
        public TemporaryUploadedFile $file,
        public string $title,
    ) {}

    public function originalName(): string
    {
        return $this->file->getClientOriginalName();
    }

    /**
     * Título derivado do nome do arquivo, sem a extensão. É só o valor inicial:
     * o usuário edita na etapa de conferência antes de confirmar.
     */
    public static function titleFromFileName(string $originalName): string
    {
        $title = trim(pathinfo($originalName, PATHINFO_FILENAME));

        return $title === '' ? $originalName : mb_substr($title, 0, 255);
    }
}
