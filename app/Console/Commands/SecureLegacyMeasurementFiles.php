<?php

namespace App\Console\Commands;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SecureLegacyMeasurementFiles extends Command
{
    protected $signature = 'measurements:secure-legacy-files
        {--execute : Copia, verifica, atualiza o banco e remove a cópia pública}
        {--limit=0 : Limite total de arquivos; zero processa todos}';

    protected $description = 'Migra arquivos legados de medições do disco público para o armazenamento privado (dry-run por padrão)';

    private int $processed = 0;

    private int $migrated = 0;

    private int $failed = 0;

    public function handle(DocumentStorageService $storage): int
    {
        $this->processed = 0;
        $this->migrated = 0;
        $this->failed = 0;

        $targetDisk = DocumentStorageService::privateDisk();

        if ($targetDisk === 'public') {
            $this->components->error('O disco privado está configurado como public; a migração foi cancelada.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));
        $this->components->info($execute ? 'Executando migração segura.' : 'Dry-run: nenhum arquivo será copiado ou removido.');

        $this->process(Measurement::query(), 'storage_path', 'storage_disk', 'sha256', 'mime_type', 'file_size', 'files', $storage, $targetDisk, $execute, $limit);
        $this->process(MeasurementAsset::query(), 'storage_path', 'storage_disk', 'sha256', 'mime_type', 'size', 'assets', $storage, $targetDisk, $execute, $limit);
        $this->process(MeasurementPayment::query(), 'receipt_path', 'receipt_disk', 'receipt_sha256', 'receipt_mime_type', 'receipt_size', 'receipts', $storage, $targetDisk, $execute, $limit);
        $this->reconcileResidualPublicCopies(Measurement::query(), 'storage_path', 'storage_disk', 'sha256', 'files', $storage, $targetDisk, $execute, $limit);
        $this->reconcileResidualPublicCopies(MeasurementAsset::query(), 'storage_path', 'storage_disk', 'sha256', 'assets', $storage, $targetDisk, $execute, $limit);
        $this->reconcileResidualPublicCopies(MeasurementPayment::query(), 'receipt_path', 'receipt_disk', 'receipt_sha256', 'receipts', $storage, $targetDisk, $execute, $limit);

        $this->table(['Processados', 'Migrados', 'Falhas'], [[$this->processed, $this->migrated, $this->failed]]);

        return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function process(
        Builder $query,
        string $pathColumn,
        string $diskColumn,
        string $hashColumn,
        string $mimeColumn,
        string $sizeColumn,
        string $type,
        DocumentStorageService $storage,
        string $targetDisk,
        bool $execute,
        int $limit,
    ): void {
        $query->whereNotNull($pathColumn)
            ->where(fn (Builder $legacy): Builder => $legacy->whereNull($diskColumn)->orWhere($diskColumn, 'public'))
            ->orderBy('id')
            ->chunkById(100, function ($records) use (
                $pathColumn,
                $diskColumn,
                $hashColumn,
                $mimeColumn,
                $sizeColumn,
                $type,
                $storage,
                $targetDisk,
                $execute,
                $limit,
            ): bool {
                foreach ($records as $record) {
                    if ($limit > 0 && $this->processed >= $limit) {
                        return false;
                    }

                    $this->processed++;
                    $sourcePath = (string) $record->getAttribute($pathColumn);

                    if (! $storage->exists($sourcePath, 'public')) {
                        $this->failed++;
                        $this->components->warn($record::class." #{$record->getKey()}: origem pública ausente.");

                        continue;
                    }

                    $sourceHash = $storage->checksum($sourcePath, 'public');
                    $targetPath = DocumentStorageService::PRIVATE_PREFIX
                        ."/measurements/legacy/{$type}/{$record->getKey()}/".basename($sourcePath);

                    if ($sourceHash === null) {
                        $this->failed++;
                        $this->components->warn($record::class." #{$record->getKey()}: não foi possível calcular o SHA-256 da origem.");

                        continue;
                    }

                    if (! $execute) {
                        continue;
                    }

                    if (! $this->copyAndVerify($sourcePath, $targetPath, $sourceHash, $targetDisk, $storage)) {
                        $this->failed++;
                        $this->components->warn($record::class." #{$record->getKey()}: cópia privada não pôde ser verificada.");

                        continue;
                    }

                    $metadata = $storage->metadata($targetPath, $targetDisk);

                    try {
                        DB::transaction(function () use (
                            $record,
                            $pathColumn,
                            $diskColumn,
                            $hashColumn,
                            $mimeColumn,
                            $sizeColumn,
                            $sourcePath,
                            $sourceHash,
                            $targetPath,
                            $targetDisk,
                            $metadata,
                            $storage,
                        ): void {
                            $locked = $record::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

                            if ((string) $locked->getAttribute($pathColumn) !== $sourcePath
                                || ! in_array($locked->getAttribute($diskColumn), [null, 'public'], true)) {
                                throw new RuntimeException('O registro foi alterado durante a migração.');
                            }

                            $saved = $locked->forceFill([
                                $pathColumn => $targetPath,
                                $diskColumn => $targetDisk,
                                $hashColumn => $sourceHash,
                                $mimeColumn => is_string($metadata['mime_type']) ? $metadata['mime_type'] : 'application/octet-stream',
                                $sizeColumn => is_int($metadata['size_bytes']) ? $metadata['size_bytes'] : 0,
                            ])->saveQuietly();

                            if (! $saved
                                || $locked->fresh()?->getAttribute($hashColumn) !== $sourceHash
                                || $locked->fresh()?->getAttribute($diskColumn) !== $targetDisk) {
                                throw new RuntimeException('O banco não confirmou o destino privado.');
                            }

                            $deleted = Storage::disk('public')->delete($sourcePath);

                            if (! $deleted || $storage->exists($sourcePath, 'public')) {
                                throw new RuntimeException('A cópia pública não pôde ser removida e confirmada.');
                            }
                        });
                    } catch (Throwable $exception) {
                        $this->restorePublicSourceAfterRollback(
                            $record,
                            $diskColumn,
                            $sourcePath,
                            $sourceHash,
                            $targetPath,
                            $targetDisk,
                            $storage,
                        );
                        $this->failed++;
                        $this->components->warn($record::class." #{$record->getKey()}: {$exception->getMessage()}");

                        continue;
                    }

                    $confirmed = $record->fresh();

                    if ($confirmed?->getAttribute($diskColumn) !== $targetDisk
                        || $confirmed?->getAttribute($hashColumn) !== $sourceHash
                        || ! $storage->exists($targetPath, $targetDisk)
                        || $storage->exists($sourcePath, 'public')) {
                        $this->restoreTrackedPublicState(
                            $record,
                            $pathColumn,
                            $diskColumn,
                            $hashColumn,
                            $sourcePath,
                            $sourceHash,
                            $targetPath,
                            $targetDisk,
                            $storage,
                        );
                        $this->failed++;
                        $this->components->warn($record::class." #{$record->getKey()}: verificação pós-commit falhou.");

                        continue;
                    }

                    $this->migrated++;
                }

                return ! ($limit > 0 && $this->processed >= $limit);
            });
    }

    private function copyAndVerify(
        string $sourcePath,
        string $targetPath,
        string $sourceHash,
        string $targetDisk,
        DocumentStorageService $storage,
    ): bool {
        if ($storage->exists($targetPath, $targetDisk)) {
            return hash_equals($sourceHash, (string) $storage->checksum($targetPath, $targetDisk));
        }

        $stream = Storage::disk('public')->readStream($sourcePath);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            if (! Storage::disk($targetDisk)->writeStream($targetPath, $stream)) {
                return false;
            }
        } finally {
            fclose($stream);
        }

        return hash_equals($sourceHash, (string) $storage->checksum($targetPath, $targetDisk));
    }

    /**
     * Reconciles records left by older executions that committed the private
     * path before confirming deletion of the public source. A public file is
     * removed only when its basename and SHA-256 both match the canonical
     * private legacy record, avoiding deletion of an unrelated namesake.
     *
     * @param  Builder<Model>  $query
     */
    private function reconcileResidualPublicCopies(
        Builder $query,
        string $pathColumn,
        string $diskColumn,
        string $hashColumn,
        string $type,
        DocumentStorageService $storage,
        string $targetDisk,
        bool $execute,
        int $limit,
    ): void {
        try {
            $publicFiles = Storage::disk('public')->allFiles();
        } catch (Throwable $exception) {
            $this->failed++;
            $this->components->warn("Não foi possível inspecionar cópias públicas residuais: {$exception->getMessage()}");

            return;
        }

        if ($publicFiles === []) {
            return;
        }

        $filesByBasename = collect($publicFiles)->groupBy(fn (string $path): string => basename($path));
        $legacyPrefix = DocumentStorageService::PRIVATE_PREFIX."/measurements/legacy/{$type}/";

        $query->where($diskColumn, $targetDisk)
            ->where($pathColumn, 'like', $legacyPrefix.'%')
            ->whereNotNull($hashColumn)
            ->orderBy('id')
            ->chunkById(100, function ($records) use (
                $pathColumn,
                $hashColumn,
                $storage,
                $targetDisk,
                $execute,
                $limit,
                $legacyPrefix,
                $filesByBasename,
            ): bool {
                foreach ($records as $record) {
                    $privatePath = (string) $record->getAttribute($pathColumn);
                    $expectedPrefix = $legacyPrefix.$record->getKey().'/';

                    if (! str_starts_with($privatePath, $expectedPrefix)) {
                        continue;
                    }

                    $expectedHash = (string) $record->getAttribute($hashColumn);
                    $matchingPublicPaths = $filesByBasename
                        ->get(basename($privatePath), collect())
                        ->filter(fn (string $publicPath): bool => hash_equals(
                            $expectedHash,
                            (string) $storage->checksum($publicPath, 'public'),
                        ));

                    foreach ($matchingPublicPaths as $publicPath) {
                        if ($limit > 0 && $this->processed >= $limit) {
                            return false;
                        }

                        $this->processed++;

                        if (! hash_equals($expectedHash, (string) $storage->checksum($privatePath, $targetDisk))) {
                            $this->failed++;
                            $this->components->warn($record::class." #{$record->getKey()}: cópia pública residual detectada, mas o destino privado não pôde ser validado.");

                            continue;
                        }

                        if (! $execute) {
                            $this->components->warn($record::class." #{$record->getKey()}: cópia pública residual detectada em {$publicPath}.");

                            continue;
                        }

                        $deleted = Storage::disk('public')->delete($publicPath);

                        if (! $deleted || $storage->exists($publicPath, 'public')) {
                            $this->failed++;
                            $this->components->warn($record::class." #{$record->getKey()}: cópia pública residual não pôde ser removida.");

                            continue;
                        }

                        $this->migrated++;
                    }
                }

                return ! ($limit > 0 && $this->processed >= $limit);
            });
    }

    private function restorePublicSourceAfterRollback(
        Model $record,
        string $diskColumn,
        string $sourcePath,
        string $sourceHash,
        string $targetPath,
        string $targetDisk,
        DocumentStorageService $storage,
    ): void {
        $current = $record->fresh();

        if ($current?->getAttribute($diskColumn) === $targetDisk
            || $storage->exists($sourcePath, 'public')) {
            return;
        }

        $stream = Storage::disk($targetDisk)->readStream($targetPath);

        if (! is_resource($stream)) {
            return;
        }

        try {
            Storage::disk('public')->writeStream($sourcePath, $stream);
        } finally {
            fclose($stream);
        }

        if (! hash_equals($sourceHash, (string) $storage->checksum($sourcePath, 'public'))) {
            $this->components->error($record::class." #{$record->getKey()}: rollback não conseguiu restaurar a origem pública.");
        }
    }

    private function restoreTrackedPublicState(
        Model $record,
        string $pathColumn,
        string $diskColumn,
        string $hashColumn,
        string $sourcePath,
        string $sourceHash,
        string $targetPath,
        string $targetDisk,
        DocumentStorageService $storage,
    ): void {
        if (! $storage->exists($sourcePath, 'public')) {
            $this->restorePublicSourceAfterRollback(
                $record,
                $diskColumn,
                $sourcePath,
                $sourceHash,
                $targetPath,
                $targetDisk,
                $storage,
            );
        }

        if (! hash_equals($sourceHash, (string) $storage->checksum($sourcePath, 'public'))) {
            $this->components->error($record::class." #{$record->getKey()}: não foi possível restaurar uma origem pública verificável para retry.");

            return;
        }

        $record->fresh()?->forceFill([
            $pathColumn => $sourcePath,
            $diskColumn => 'public',
            $hashColumn => $sourceHash,
        ])->saveQuietly();
    }
}
