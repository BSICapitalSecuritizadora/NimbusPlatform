<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Concerns\ScansUploadedFile;
use App\DTOs\SalesBoards\StagedBuilderResponseAttachment;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Services\DocumentStorageService;
use App\Services\Security\ClamAvFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Grava no disco privado os arquivos da resposta da construtora.
 *
 * Tudo acontece **antes** da transação do envio: a varredura e a cópia de até
 * cinco arquivos não podem segurar os locks do ciclo e da validação. O envio
 * cria as linhas dentro da transação a partir do que foi gravado aqui; se ele
 * for recusado, {@see self::discard()} apaga os arquivos, e se der certo,
 * {@see self::deleteTemporaryUploads()} apaga os temporários do upload.
 *
 * Três regras de segurança, nesta ordem:
 *
 * 1. tipo e tamanho são conferidos pela extensão e pelo tamanho antes de
 *    qualquer leitura, com os limites de `uploads.sales_board_builder_response`;
 * 2. o antivírus varre o conteúdo do upload **antes** de gravar, e um arquivo
 *    infectado nunca chega ao disco privado. A política é a do projeto
 *    (`uploads.clamav.enabled`, a mesma de {@see ScansUploadedFile}):
 *    desligado, o veredito é "limpo"; ligado, infectado ou antivírus
 *    indisponível recusam o envio inteiro -- falha fechada;
 * 3. MIME, tamanho e SHA-256 saem do arquivo gravado, nunca do que o navegador
 *    declarou. Um conteúdo que não é de um tipo permitido é apagado e recusado.
 *
 * A gravação usa `storeAs()`, que o upload temporário do Livewire implementa
 * por fluxo a partir do próprio disco dele, e não o caminho absoluto do
 * arquivo: com o disco temporário no Azure, `getRealPath()` devolve um caminho
 * relativo que não existe no servidor.
 */
class SalesBoardBuilderResponseEvidenceStore
{
    public const STORAGE_DIRECTORY = 'sales-board-builder-responses';

    public function __construct(
        protected readonly DocumentStorageService $documentStorage,
        protected readonly ClamAvFileScanner $fileScanner,
    ) {}

    public static function maxFiles(): int
    {
        return (int) config('uploads.sales_board_builder_response.max_files', 5);
    }

    public static function maxKilobytes(): int
    {
        return (int) config('uploads.sales_board_builder_response.max_kb', 20480);
    }

    /**
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return array_values((array) config('uploads.sales_board_builder_response.allowed_extensions', []));
    }

    /**
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        return array_values((array) config('uploads.sales_board_builder_response.allowed_mimes', []));
    }

    /**
     * Confere, varre e grava os arquivos de um envio.
     *
     * Ou todos são gravados, ou nenhum fica no disco.
     *
     * @param  list<UploadedFile>  $files
     * @return list<StagedBuilderResponseAttachment>
     *
     * @throws SalesBoardBuilderReviewException
     */
    public function stage(array $files, int $cycleId): array
    {
        $files = array_values($files);

        if ($files === []) {
            throw SalesBoardBuilderReviewException::builderResponseAttachmentRequired();
        }

        if (count($files) > self::maxFiles()) {
            throw SalesBoardBuilderReviewException::tooManyBuilderResponseAttachments(self::maxFiles());
        }

        foreach ($files as $file) {
            $this->assertAcceptable($file);
        }

        foreach ($files as $file) {
            $this->scan($file);
        }

        $disk = $this->writeDisk();
        $staged = [];

        try {
            foreach ($files as $file) {
                $staged[] = $this->store($file, $disk, $cycleId);
            }
        } catch (Throwable $exception) {
            $this->discard($staged);

            throw $exception;
        }

        return $staged;
    }

    /**
     * Apaga do disco privado o que foi gravado para um envio que não aconteceu.
     *
     * @param  list<StagedBuilderResponseAttachment>  $staged
     */
    public function discard(array $staged): void
    {
        foreach ($staged as $attachment) {
            rescue(
                fn (): bool => Storage::disk($attachment->disk)->delete($attachment->path),
                false,
                report: false,
            );
        }
    }

    /**
     * Depois do commit, os temporários do upload não servem para mais nada --
     * e um temporário esquecido é uma cópia da resposta fora do disco privado.
     *
     * @param  list<StagedBuilderResponseAttachment>  $staged
     */
    public function deleteTemporaryUploads(array $staged): void
    {
        foreach ($staged as $attachment) {
            $upload = $attachment->temporaryUpload;

            if ($upload instanceof TemporaryUploadedFile) {
                rescue(fn (): bool => (bool) $upload->delete(), false, report: false);
            }
        }
    }

    /**
     * @throws SalesBoardBuilderReviewException
     */
    protected function assertAcceptable(UploadedFile $file): void
    {
        $name = self::displayName($file);
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, self::allowedExtensions(), true)) {
            throw SalesBoardBuilderReviewException::builderResponseAttachmentRejected(
                $name,
                'tipo de arquivo não permitido. Envie PDF, DOC, DOCX, XLS, XLSX, PNG ou JPG.',
            );
        }

        if ((int) $file->getSize() > self::maxKilobytes() * 1024) {
            throw SalesBoardBuilderReviewException::builderResponseAttachmentRejected(
                $name,
                sprintf('o arquivo passa do limite de %d MB.', (int) ceil(self::maxKilobytes() / 1024)),
            );
        }
    }

    /**
     * A varredura lê o conteúdo do upload, e não um caminho no servidor.
     *
     * @throws SalesBoardBuilderReviewException
     */
    protected function scan(UploadedFile $file): void
    {
        if (! $this->fileScanner->isEnabled()) {
            return;
        }

        $stream = $this->openUploadStream($file);

        if (! is_resource($stream)) {
            $this->reject('antivirus_indisponivel', $file);
        }

        try {
            $result = $this->fileScanner->scanStream($stream);
        } finally {
            fclose($stream);
        }

        if ($result === ClamAvFileScanner::RESULT_INFECTED) {
            $this->reject('malware_detectado', $file);
        }

        if ($result !== ClamAvFileScanner::RESULT_CLEAN) {
            $this->reject('antivirus_indisponivel', $file);
        }
    }

    /**
     * @throws SalesBoardBuilderReviewException
     */
    protected function store(UploadedFile $file, string $disk, int $cycleId): StagedBuilderResponseAttachment
    {
        $name = self::displayName($file);
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $directory = $this->documentStorage->privateDirectoryPath(self::STORAGE_DIRECTORY.'/'.$cycleId);

        $path = $file->storeAs($directory, Str::random(40).'.'.$extension, ['disk' => $disk]);

        if (! is_string($path) || ($path === '')) {
            throw SalesBoardBuilderReviewException::builderResponseAttachmentRejected($name, 'não foi possível gravar o arquivo. Tente enviar de novo.');
        }

        $metadata = rescue(
            fn (): array => $this->documentStorage->metadata($path, $disk),
            ['mime_type' => null, 'size_bytes' => null],
            report: false,
        );

        $staged = new StagedBuilderResponseAttachment(
            disk: $disk,
            path: $path,
            originalName: $name,
            mimeType: is_string($metadata['mime_type']) && ($metadata['mime_type'] !== '') ? $metadata['mime_type'] : null,
            sizeBytes: (int) ($metadata['size_bytes'] ?? 0),
            checksum: $this->documentStorage->checksum($path, $disk),
            temporaryUpload: $file,
        );

        if (! in_array($staged->mimeType, self::allowedMimes(), true)) {
            $this->discard([$staged]);

            throw SalesBoardBuilderReviewException::builderResponseAttachmentRejected(
                $name,
                'o conteúdo do arquivo não corresponde a um tipo permitido. Envie PDF, DOC, DOCX, XLS, XLSX, PNG ou JPG.',
            );
        }

        if ($staged->sizeBytes <= 0) {
            $this->discard([$staged]);

            throw SalesBoardBuilderReviewException::builderResponseAttachmentRejected($name, 'o arquivo está vazio.');
        }

        return $staged;
    }

    /**
     * O disco privado configurado, desde que seja um dos de escrita de
     * documentos: o disco público nunca recebe a resposta da construtora.
     */
    protected function writeDisk(): string
    {
        $disk = DocumentStorageService::privateDisk();

        if (! $this->documentStorage->isAllowedMeasurementWriteDisk($disk)) {
            throw new InvalidArgumentException('The configured private disk cannot receive builder response attachments.');
        }

        return $disk;
    }

    /**
     * @return resource|false
     */
    protected function openUploadStream(UploadedFile $file): mixed
    {
        if ($file instanceof TemporaryUploadedFile) {
            return rescue(fn (): mixed => $file->readStream(), false, report: false) ?? false;
        }

        $path = $file->getPathname();

        return ($path !== '' && is_file($path)) ? @fopen($path, 'rb') : false;
    }

    /**
     * @throws SalesBoardBuilderReviewException
     */
    protected function reject(string $reason, UploadedFile $file): never
    {
        Log::critical('Resposta da construtora bloqueada pela varredura antivírus.', [
            'reason' => $reason,
            'original_name' => self::displayName($file),
        ]);

        if ($reason === 'malware_detectado') {
            throw SalesBoardBuilderReviewException::builderResponseAttachmentRejected(
                self::displayName($file),
                'o arquivo não passou na verificação de segurança e foi bloqueado.',
            );
        }

        throw SalesBoardBuilderReviewException::builderResponseScanUnavailable();
    }

    /**
     * O nome que a pessoa deu ao arquivo, sem caminho e no tamanho da coluna. É
     * só exibição: o download o entrega por `Content-Disposition` sanitizado.
     */
    protected static function displayName(UploadedFile $file): string
    {
        $name = trim(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())));

        return mb_substr($name === '' ? 'resposta-construtora' : $name, 0, 255);
    }
}
