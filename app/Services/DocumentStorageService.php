<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentStorageService
{
    public const PRIVATE_PREFIX = 'nimbus_docs';

    public const TMP_DIRECTORY = 'tmp_uploads';

    public const DEFAULT_PRIVATE_DISK = 'local';

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_DISKS = [
        'local',
        'private',
        'public',
    ];

    /**
     * Disco onde os documentos privados são gravados. Configurável para permitir
     * a migração do armazenamento local para o Azure Blob Storage sem alterar
     * os registros já existentes (cada registro guarda o disco de origem).
     */
    public static function privateDisk(): string
    {
        return (string) config('filesystems.private_disk', self::DEFAULT_PRIVATE_DISK);
    }

    /**
     * Únicos tipos que podem ser servidos inline: nenhum deles é interpretado
     * como documento executável pelo navegador. Qualquer outro tipo é forçado
     * para download, de modo que um `Content-Type` indevido não vire XSS.
     *
     * @var array<int, string>
     */
    private const INLINE_SAFE_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /**
     * CSP aplicada a arquivos servidos ao navegador: sem rede, sem script e em
     * origem opaca, mesmo que o tipo declarado seja contornado.
     *
     * `allow-scripts` não reabre execução de script: `default-src 'none'` já
     * cobre `script-src`, então nada carrega ou executa no documento. Ele apenas
     * evita que a flag de sandbox interfira nos leitores internos do navegador
     * (o visualizador de PDF é acionado por script no próprio navegador). O que
     * protege a sessão é a ausência de `allow-same-origin`: o documento fica em
     * origem opaca e não alcança cookies nem DOM da aplicação.
     */
    private const FILE_RESPONSE_CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox allow-scripts";

    /**
     * @return array{
     *     disk: string,
     *     path: string,
     *     stored_name: string,
     *     original_name: string,
     *     mime_type: ?string,
     *     size_bytes: int,
     *     checksum: ?string
     * }
     */
    public function storePrivateFile(UploadedFile $file, string $directory): array
    {
        $privateDisk = self::privateDisk();
        $path = $file->store($this->privateDirectoryPath($directory), $privateDisk);

        return [
            'disk' => $privateDisk,
            'path' => $path,
            'stored_name' => basename($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
        ];
    }

    /**
     * Write the file to the temporary staging area. Returns the same shape as storePrivateFile().
     *
     * @return array{disk: string, path: string, stored_name: string, original_name: string, mime_type: ?string, size_bytes: int, checksum: ?string}
     */
    public function stagePrivateFile(UploadedFile $file): array
    {
        return $this->storePrivateFile($file, self::TMP_DIRECTORY);
    }

    /**
     * Move a staged file from the tmp directory to its permanent directory.
     * Creates the destination directory if needed, then renames the file in-place on disk.
     */
    public function moveStagedFile(string $fromPath, string $toDirectory, string $storedName): string
    {
        $disk = $this->filesystem(self::privateDisk());
        $finalPath = $this->privateDirectoryPath($toDirectory).'/'.$storedName;

        $disk->makeDirectory(dirname($finalPath));
        $disk->move($fromPath, $finalPath);

        return $finalPath;
    }

    public function privateDirectoryPath(string $directory): string
    {
        return $this->privateDirectory($directory);
    }

    public function privateExists(string $path): bool
    {
        return $this->exists($path, self::privateDisk());
    }

    public function downloadPrivate(string $path, string $downloadName): StreamedResponse
    {
        return $this->download($path, $downloadName, self::privateDisk());
    }

    public function previewPrivate(
        string $path,
        ?string $mimeType = null,
        ?string $downloadName = null,
    ): BinaryFileResponse|StreamedResponse {
        return $this->preview($path, $mimeType, $downloadName, self::privateDisk());
    }

    /**
     * @return array{mime_type: ?string, size_bytes: ?int}
     */
    public function privateMetadata(string $path): array
    {
        return $this->metadata($path, self::privateDisk());
    }

    /**
     * Só faz sentido em discos locais; discos remotos (Azure Blob) não expõem
     * um caminho de sistema de arquivos.
     */
    public function absolutePrivatePath(string $path): string
    {
        return $this->absolutePath($path, self::privateDisk());
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        return $this->filesystem($disk ?? self::privateDisk())->exists($path);
    }

    public function download(
        string $path,
        string $downloadName,
        ?string $disk = null,
    ): StreamedResponse {
        return $this->filesystem($disk ?? self::privateDisk())->download($path, $downloadName);
    }

    public function preview(
        string $path,
        ?string $mimeType = null,
        ?string $downloadName = null,
        ?string $disk = null,
    ): BinaryFileResponse|StreamedResponse {
        $resolvedDisk = $disk ?? self::privateDisk();
        $isInlineSafe = in_array($mimeType, self::INLINE_SAFE_MIMES, true);
        $resolvedMime = $isInlineSafe ? (string) $mimeType : 'application/octet-stream';
        $resolvedDownloadName = $downloadName ?: basename($path);
        $disposition = $isInlineSafe ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;

        $headers = [
            'Content-Type' => $resolvedMime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::FILE_RESPONSE_CSP,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition,
                $resolvedDownloadName,
                Str::ascii($resolvedDownloadName),
            ),
        ];

        if (! $this->isLocalDisk($resolvedDisk)) {
            return $this->filesystem($resolvedDisk)->response(
                $path,
                $resolvedDownloadName,
                $headers,
                $disposition,
            );
        }

        return response()->file($this->absolutePath($path, $resolvedDisk), $headers);
    }

    /**
     * SHA-256 do arquivo gravado, lido em fluxo para não carregar o conteúdo
     * inteiro na memória. Devolve `null` quando o arquivo não pode ser lido —
     * em disco remoto isto é uma chamada de rede e pode falhar.
     */
    public function checksum(string $path, ?string $disk = null): ?string
    {
        $stream = rescue(
            fn () => $this->filesystem($disk ?? self::privateDisk())->readStream($path),
            null,
            report: false,
        );

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{mime_type: ?string, size_bytes: ?int}
     */
    public function metadata(string $path, ?string $disk = null): array
    {
        $disk ??= self::privateDisk();

        if (! $this->exists($path, $disk)) {
            return [
                'mime_type' => null,
                'size_bytes' => null,
            ];
        }

        $filesystem = $this->filesystem($disk);

        return [
            'mime_type' => $filesystem->mimeType($path),
            'size_bytes' => $filesystem->size($path),
        ];
    }

    public function absolutePath(string $path, ?string $disk = null): string
    {
        return Storage::disk($this->normalizeDisk($disk ?? self::privateDisk()))->path($path);
    }

    protected function isLocalDisk(string $disk): bool
    {
        return config("filesystems.disks.{$this->normalizeDisk($disk)}.driver") === 'local';
    }

    protected function privateDirectory(string $directory): string
    {
        $normalizedDirectory = trim($directory, '/');

        if ($normalizedDirectory === '') {
            return self::PRIVATE_PREFIX;
        }

        if (($normalizedDirectory === self::PRIVATE_PREFIX) || str_starts_with($normalizedDirectory, self::PRIVATE_PREFIX.'/')) {
            return $normalizedDirectory;
        }

        return self::PRIVATE_PREFIX.'/'.$normalizedDirectory;
    }

    protected function filesystem(string $disk): FilesystemAdapter
    {
        return Storage::disk($this->normalizeDisk($disk));
    }

    protected function normalizeDisk(string $disk): string
    {
        if (! in_array($disk, self::SUPPORTED_DISKS, true)) {
            throw new InvalidArgumentException("Unsupported storage disk [{$disk}].");
        }

        return $disk;
    }
}
