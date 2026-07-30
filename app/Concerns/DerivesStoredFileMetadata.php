<?php

namespace App\Concerns;

use App\Services\DocumentStorageService;

/**
 * Deriva `file_mime`, `file_size` e `file_original_name` do arquivo realmente
 * gravado em disco, em vez de aceitar o que veio no payload do formulário.
 *
 * Metadados de arquivo enviados pelo cliente não são confiáveis: o `file_mime`
 * persistido é reutilizado como `Content-Type` no preview, então um valor
 * forjado se tornaria execução de conteúdo no navegador.
 *
 * @property string $file_path
 * @property string $file_mime
 * @property int $file_size
 * @property string $file_original_name
 */
trait DerivesStoredFileMetadata
{
    public static function bootDerivesStoredFileMetadata(): void
    {
        static::saving(function (self $model): void {
            $model->syncStoredFileMetadata();
        });
    }

    /**
     * Só toca no banco quando algum campo de arquivo mudou — inclusive quando
     * apenas os metadados mudaram, o que sinaliza tentativa de sobrescrevê-los
     * sem trocar o arquivo.
     */
    protected function syncStoredFileMetadata(): void
    {
        if (! $this->isDirty(['file_path', 'file_mime', 'file_size', 'file_original_name'])) {
            return;
        }

        $path = (string) $this->file_path;

        if ($path === '') {
            return;
        }

        $metadata = rescue(
            fn (): array => app(DocumentStorageService::class)->privateMetadata($path),
            ['mime_type' => null, 'size_bytes' => null],
            report: false,
        );

        $mimeType = $metadata['mime_type'];

        // Discos configurados com `throw => false` devolvem `false` em vez de
        // lançar quando não conseguem inspecionar o arquivo.
        if (! is_string($mimeType) || $mimeType === '') {
            $this->backfillMissingFileMetadata($path);

            return;
        }

        $this->file_mime = $mimeType;
        $this->file_original_name = basename($path);

        if (is_int($metadata['size_bytes'])) {
            $this->file_size = $metadata['size_bytes'];
        }
    }

    /**
     * Em disco remoto a leitura de metadados é uma chamada de rede e pode falhar.
     * Como o formulário não envia mais esses campos, um registro novo violaria as
     * colunas NOT NULL. Grava valores neutros — e `application/octet-stream` faz
     * o preview forçar download, que é o comportamento seguro. Em registros que
     * já existem, preserva o valor gravado em vez de sobrescrevê-lo.
     */
    protected function backfillMissingFileMetadata(string $path): void
    {
        $this->file_mime ??= 'application/octet-stream';
        $this->file_original_name ??= basename($path);
        $this->file_size ??= 0;
    }
}
