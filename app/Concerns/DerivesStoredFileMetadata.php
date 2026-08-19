<?php

namespace App\Concerns;

use App\Services\DocumentStorageService;

/**
 * Deriva os metadados do arquivo realmente gravado em disco, em vez de aceitar
 * o que veio no payload do formulário.
 *
 * Metadados enviados pelo cliente não são confiáveis: o MIME persistido é
 * reutilizado como `Content-Type` no preview, então um valor forjado se tornaria
 * execução de conteúdo no navegador. Campos `Hidden` do Filament fazem parte do
 * estado Livewire e são reescritos por quem controla a requisição.
 *
 * Os nomes de coluna são sobrescrevíveis porque os models não convergem: o
 * `Document` usa `mime_type`/`file_name`, os do portal usam
 * `file_mime`/`file_original_name`.
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
     * Coluna que guarda o caminho do arquivo no disco.
     */
    protected function storedFilePathColumn(): string
    {
        return 'file_path';
    }

    /**
     * Coluna do MIME derivado do disco.
     */
    protected function storedFileMimeColumn(): string
    {
        return 'file_mime';
    }

    /**
     * Coluna do tamanho em bytes.
     */
    protected function storedFileSizeColumn(): string
    {
        return 'file_size';
    }

    /**
     * Coluna que guarda o SHA-256 do arquivo, ou `null` para não derivá-lo.
     *
     * Só vale a pena onde a identidade do conteúdo é usada — hoje, a detecção de
     * documentos iguais no cadastro em lote. Calcular o hash exige ler o arquivo
     * inteiro, o que em disco remoto é uma transferência completa; por isso é
     * opcional e não o padrão.
     */
    protected function storedFileChecksumColumn(): ?string
    {
        return null;
    }

    /**
     * Coluna do nome de exibição, ou `null` para não derivá-lo.
     *
     * Só faz sentido derivar quando o nome exibido é o do arquivo em disco. Onde
     * o nome original informado pelo usuário é o que aparece no download, herdar
     * `basename($path)` trocaria "Termo de Securitização.pdf" pelo nome de
     * armazenamento — e o nome é inofensivo, porque sai sempre por
     * `HeaderUtils::makeDisposition()`, que o sanitiza.
     */
    protected function storedFileNameColumn(): ?string
    {
        return 'file_original_name';
    }

    /**
     * Disco em que o arquivo está gravado.
     */
    protected function storedFileMetadataDisk(): string
    {
        return DocumentStorageService::privateDisk();
    }

    /**
     * Só toca no banco quando algum campo de arquivo mudou — inclusive quando
     * apenas os metadados mudaram, o que sinaliza tentativa de sobrescrevê-los
     * sem trocar o arquivo.
     */
    protected function syncStoredFileMetadata(): void
    {
        $pathColumn = $this->storedFilePathColumn();
        $mimeColumn = $this->storedFileMimeColumn();
        $sizeColumn = $this->storedFileSizeColumn();
        $nameColumn = $this->storedFileNameColumn();
        $checksumColumn = $this->storedFileChecksumColumn();

        $watchedColumns = array_values(array_filter([
            $pathColumn,
            $mimeColumn,
            $sizeColumn,
            $nameColumn,
            $checksumColumn,
        ]));

        if (! $this->isDirty($watchedColumns)) {
            return;
        }

        $path = (string) $this->getAttribute($pathColumn);

        if ($path === '') {
            return;
        }

        $this->syncStoredFileChecksum($path, $checksumColumn);

        $metadata = rescue(
            fn (): array => app(DocumentStorageService::class)->metadata($path, $this->storedFileMetadataDisk()),
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

        $this->setAttribute($mimeColumn, $mimeType);

        if ($nameColumn !== null) {
            $this->setAttribute($nameColumn, basename($path));
        }

        if (is_int($metadata['size_bytes'])) {
            $this->setAttribute($sizeColumn, $metadata['size_bytes']);
        }
    }

    /**
     * O checksum vem sempre do arquivo em disco, nunca do payload: um valor
     * enviado pelo cliente transformaria a detecção de duplicidade em algo que o
     * remetente controla.
     *
     * Diferente do MIME e do tamanho, aqui a falha de leitura grava `null` em vez
     * de preservar o valor anterior. O hash antigo pertence ao arquivo antigo:
     * mantê-lo depois de uma troca de arquivo faria o sistema afirmar que dois
     * documentos são idênticos quando não são. `null` significa "desconhecido",
     * que é o que de fato se sabe, e o `documents:backfill-checksums` recalcula
     * depois.
     */
    protected function syncStoredFileChecksum(string $path, ?string $checksumColumn): void
    {
        if ($checksumColumn === null) {
            return;
        }

        $this->setAttribute(
            $checksumColumn,
            app(DocumentStorageService::class)->checksum($path, $this->storedFileMetadataDisk()),
        );
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
        $mimeColumn = $this->storedFileMimeColumn();
        $sizeColumn = $this->storedFileSizeColumn();
        $nameColumn = $this->storedFileNameColumn();

        $this->setAttribute($mimeColumn, $this->getAttribute($mimeColumn) ?? 'application/octet-stream');
        $this->setAttribute($sizeColumn, $this->getAttribute($sizeColumn) ?? 0);

        if ($nameColumn !== null) {
            $this->setAttribute($nameColumn, $this->getAttribute($nameColumn) ?? basename($path));
        }
    }
}
