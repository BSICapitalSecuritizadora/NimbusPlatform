<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MeasurementFileValidationService
{
    public function __construct(private DocumentStorageService $storage) {}

    public function validateAsset(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement', 'asset');
    }

    public function validateReceipt(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement_receipt', 'receipt');
    }

    private function validate(string $path, string $disk, string $configuration, string $errorKey): void
    {
        if (! $this->storage->isAllowedMeasurementDisk($disk)
            || ! $this->storage->isSafeStoredPath($path)
            || ! $this->storage->exists($path, $disk)) {
            throw ValidationException::withMessages([
                $errorKey => 'O arquivo enviado não foi encontrado em um armazenamento permitido.',
            ]);
        }

        $metadata = $this->storage->metadata($path, $disk);
        $mimeType = $metadata['mime_type'];
        $size = $metadata['size_bytes'];
        $allowedMimes = (array) config("uploads.{$configuration}.allowed_mimes", []);
        $allowedExtensions = array_map(
            static fn (mixed $extension): string => Str::lower((string) $extension),
            (array) config("uploads.{$configuration}.allowed_extensions", []),
        );
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        $maximumBytes = (int) config("uploads.{$configuration}.max_bytes", 0);

        if (! is_int($size) || $size < 1 || ($maximumBytes > 0 && $size > $maximumBytes)) {
            throw ValidationException::withMessages([
                $errorKey => 'O arquivo enviado está vazio ou excede o limite permitido.',
            ]);
        }

        if (! is_string($mimeType)
            || ! in_array($mimeType, $allowedMimes, true)
            || ! in_array($extension, $allowedExtensions, true)
            || ! $this->extensionMatchesMime($extension, $mimeType)
            || ! $this->contentMatchesMime($path, $disk, $mimeType)) {
            throw ValidationException::withMessages([
                $errorKey => 'O tipo real ou a extensão do arquivo enviado não é permitido.',
            ]);
        }
    }

    private function extensionMatchesMime(string $extension, string $mimeType): bool
    {
        return match ($mimeType) {
            'application/pdf' => $extension === 'pdf',
            'image/jpeg' => in_array($extension, ['jpg', 'jpeg'], true),
            'image/png' => $extension === 'png',
            default => false,
        };
    }

    private function contentMatchesMime(string $path, string $disk, string $mimeType): bool
    {
        $prefix = $this->storage->readPrefix($path, $disk, 8);

        if (! is_string($prefix)) {
            return false;
        }

        return match ($mimeType) {
            'application/pdf' => str_starts_with($prefix, '%PDF-'),
            'image/jpeg' => str_starts_with($prefix, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($prefix, "\x89PNG\r\n\x1A\n"),
            default => false,
        };
    }
}
