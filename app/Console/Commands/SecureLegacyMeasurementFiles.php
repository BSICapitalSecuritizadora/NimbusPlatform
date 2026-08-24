<?php

namespace App\Console\Commands;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
                    $record->forceFill([
                        $pathColumn => $targetPath,
                        $diskColumn => $targetDisk,
                        $hashColumn => $sourceHash,
                        $mimeColumn => is_string($metadata['mime_type']) ? $metadata['mime_type'] : 'application/octet-stream',
                        $sizeColumn => is_int($metadata['size_bytes']) ? $metadata['size_bytes'] : 0,
                    ])->saveQuietly();

                    if ($record->fresh()->getAttribute($hashColumn) !== $sourceHash) {
                        $this->failed++;
                        $this->components->warn($record::class." #{$record->getKey()}: banco não confirmou o hash; origem preservada.");

                        continue;
                    }

                    Storage::disk('public')->delete($sourcePath);
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
}
