<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Services\Security\ClamAvFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeasurementFileValidationService
{
    public function __construct(
        private DocumentStorageService $storage,
        private ClamAvFileScanner $scanner,
    ) {}

    public function storeAsset(UploadedFile $file): string
    {
        Validator::make(['asset' => $file], [
            'asset' => ['required', 'file', 'extensions:'.implode(',', config('uploads.measurement.allowed_extensions', [])), 'max:'.config('uploads.measurement.max_kb', 51200)],
        ])->validate();

        $directory = 'measurements/assets/'.Str::uuid();
        $disk = DocumentStorageService::privateDisk();
        $path = $this->storage->privateDirectoryPath($directory).'/'.$file->hashName();
        $this->compensateAssetOnRollback($path, $disk);

        try {
            $this->storage->storePrivateFile($file, $directory);
        } catch (Throwable $exception) {
            $this->discardUnreferencedAsset($path, $disk);

            throw $exception;
        }

        return $path;
    }

    public function compensateAssetOnRollback(string $path, string $disk): void
    {
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => $this->discardUnreferencedAsset($path, $disk));
            }
        }
    }

    public function discardUnreferencedAsset(string $path, string $disk): void
    {
        rescue(function () use ($path, $disk): void {
            if (! $this->storage->isAllowedMeasurementWriteDisk($disk)
                || ! $this->storage->isSafeStoredPath($path)
                || ! str_starts_with($path, DocumentStorageService::PRIVATE_PREFIX.'/measurements/assets/')) {
                return;
            }

            foreach ([MeasurementAsset::class, Measurement::class, MeasurementPaymentReceiptEvidence::class] as $model) {
                if ($model::query()->where('storage_disk', $disk)->where('storage_path', $path)->exists()) {
                    return;
                }
            }

            if (MeasurementPayment::query()->where('receipt_disk', $disk)->where('receipt_path', $path)->exists()) {
                return;
            }

            if (! Storage::disk($disk)->delete($path)) {
                throw new \RuntimeException('Não foi possível compensar o arquivo de Engenharia rejeitado.');
            }
        }, report: true);
    }

    public function validateAsset(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement', 'asset', allowLegacyPublic: false);
        $this->scanStoredFile($path, $disk, 'asset');
    }

    public function validateStoredAsset(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement', 'asset', allowLegacyPublic: true);
    }

    public function validateReceipt(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement_receipt', 'receipt', allowLegacyPublic: false);
        $this->scanStoredFile($path, $disk, 'receipt');
    }

    public function validateStoredReceipt(string $path, string $disk): void
    {
        $this->validate($path, $disk, 'measurement_receipt', 'receipt', allowLegacyPublic: true);
    }

    private function scanStoredFile(string $path, string $disk, string $errorKey): void
    {
        if (! $this->scanner->isEnabled()) {
            return;
        }

        $stream = rescue(fn () => Storage::disk($disk)->readStream($path), null, report: false);

        try {
            $result = is_resource($stream)
                ? rescue(fn (): string => $this->scanner->scanStream($stream), ClamAvFileScanner::RESULT_UNAVAILABLE)
                : ClamAvFileScanner::RESULT_UNAVAILABLE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($result !== ClamAvFileScanner::RESULT_CLEAN) {
            throw ValidationException::withMessages([
                $errorKey => $result === ClamAvFileScanner::RESULT_INFECTED
                    ? 'O arquivo foi bloqueado pelo antivírus. Envie um arquivo seguro.'
                    : 'Não foi possível verificar a segurança do arquivo. Tente novamente quando o antivírus estiver disponível.',
            ]);
        }
    }

    private function validate(
        string $path,
        string $disk,
        string $configuration,
        string $errorKey,
        bool $allowLegacyPublic,
    ): void {
        $allowedDisk = $allowLegacyPublic
            ? $this->storage->isAllowedMeasurementReadDisk($disk)
            : $this->storage->isAllowedMeasurementWriteDisk($disk);

        if (! $allowedDisk
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
