<?php

namespace App\Console\Commands;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPayment;
use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BackfillMeasurementFileHashes extends Command
{
    protected $signature = 'measurements:backfill-file-hashes
        {--execute : Persiste os hashes e metadados calculados}
        {--limit=0 : Limite total de arquivos; zero processa todos}';

    protected $description = 'Calcula SHA-256 ausente nos arquivos e comprovantes de medições (dry-run por padrão)';

    private int $processed = 0;

    private int $updated = 0;

    private int $missing = 0;

    public function handle(DocumentStorageService $storage): int
    {
        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));

        $this->components->info($execute ? 'Executando backfill.' : 'Dry-run: nenhum registro será alterado.');

        $this->process(
            Measurement::query()->whereNotNull('storage_path')->whereNull('sha256'),
            'storage_path',
            'resolved_storage_disk',
            'sha256',
            'mime_type',
            'file_size',
            $storage,
            $execute,
            $limit,
        );
        $this->process(
            MeasurementAsset::query()->whereNotNull('storage_path')->whereNull('sha256'),
            'storage_path',
            'resolved_storage_disk',
            'sha256',
            'mime_type',
            'size',
            $storage,
            $execute,
            $limit,
        );
        $this->process(
            MeasurementPayment::query()->whereNotNull('receipt_path')->whereNull('receipt_sha256'),
            'receipt_path',
            'resolved_receipt_disk',
            'receipt_sha256',
            'receipt_mime_type',
            'receipt_size',
            $storage,
            $execute,
            $limit,
        );

        $this->table(['Processados', 'Persistidos', 'Ausentes'], [[$this->processed, $this->updated, $this->missing]]);

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function process(
        Builder $query,
        string $pathColumn,
        string $resolvedDiskAttribute,
        string $hashColumn,
        string $mimeColumn,
        string $sizeColumn,
        DocumentStorageService $storage,
        bool $execute,
        int $limit,
    ): void {
        $query->orderBy('id')->chunkById(100, function ($records) use (
            $pathColumn,
            $resolvedDiskAttribute,
            $hashColumn,
            $mimeColumn,
            $sizeColumn,
            $storage,
            $execute,
            $limit,
        ): bool {
            foreach ($records as $record) {
                if ($limit > 0 && $this->processed >= $limit) {
                    return false;
                }

                $this->processed++;
                $path = (string) $record->getAttribute($pathColumn);
                $disk = (string) $record->getAttribute($resolvedDiskAttribute);

                if (! $storage->exists($path, $disk)) {
                    $this->missing++;
                    $this->components->warn($record::class." #{$record->getKey()}: arquivo ausente em {$disk}:{$path}");

                    continue;
                }

                $hash = $storage->checksum($path, $disk);

                if ($hash === null) {
                    $this->missing++;
                    $this->components->warn($record::class." #{$record->getKey()}: SHA-256 indisponível.");

                    continue;
                }

                $metadata = $storage->metadata($path, $disk);

                if ($execute) {
                    $record->forceFill([
                        $hashColumn => $hash,
                        $mimeColumn => is_string($metadata['mime_type']) ? $metadata['mime_type'] : 'application/octet-stream',
                        $sizeColumn => is_int($metadata['size_bytes']) ? $metadata['size_bytes'] : 0,
                    ])->saveQuietly();
                    $this->updated++;
                }
            }

            return ! ($limit > 0 && $this->processed >= $limit);
        });
    }
}
