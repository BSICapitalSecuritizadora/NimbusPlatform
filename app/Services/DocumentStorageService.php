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
    public const PRIVATE_DISK = 'local';

    public const PRIVATE_PREFIX = 'nimbus_docs';

    public const TMP_DIRECTORY = 'tmp_uploads';

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_DISKS = [
        'local',
        'public',
    ];

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
        $path = $file->store($this->privateDirectoryPath($directory), self::PRIVATE_DISK);

        return [
            'disk' => self::PRIVATE_DISK,
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
        $disk = $this->filesystem(self::PRIVATE_DISK);
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
        return $this->exists($path, self::PRIVATE_DISK);
    }

    public function downloadPrivate(string $path, string $downloadName): StreamedResponse
    {
        return $this->download($path, $downloadName, self::PRIVATE_DISK);
    }

    public function previewPrivate(
        string $path,
        ?string $mimeType = null,
        ?string $downloadName = null,
    ): BinaryFileResponse {
        return $this->preview($path, $mimeType, $downloadName, self::PRIVATE_DISK);
    }

    /**
     * @return array{mime_type: ?string, size_bytes: ?int}
     */
    public function privateMetadata(string $path): array
    {
        return $this->metadata($path, self::PRIVATE_DISK);
    }

    public function absolutePrivatePath(string $path): string
    {
        return $this->absolutePath($path, self::PRIVATE_DISK);
    }

    public function exists(string $path, string $disk = self::PRIVATE_DISK): bool
    {
        return $this->filesystem($disk)->exists($path);
    }

    public function download(
        string $path,
        string $downloadName,
        string $disk = self::PRIVATE_DISK,
    ): StreamedResponse {
        return $this->filesystem($disk)->download($path, $downloadName);
    }

    public function preview(
        string $path,
        ?string $mimeType = null,
        ?string $downloadName = null,
        string $disk = self::PRIVATE_DISK,
    ): BinaryFileResponse {
        $resolvedDownloadName = $downloadName ?: basename($path);

        return response()->file($this->absolutePath($path, $disk), [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                'inline',
                $resolvedDownloadName,
                Str::ascii($resolvedDownloadName),
            ),
        ]);
    }

    /**
     * @return array{mime_type: ?string, size_bytes: ?int}
     */
    public function metadata(string $path, string $disk = self::PRIVATE_DISK): array
    {
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

    public function absolutePath(string $path, string $disk = self::PRIVATE_DISK): string
    {
        return Storage::disk($this->normalizeDisk($disk))->path($path);
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
