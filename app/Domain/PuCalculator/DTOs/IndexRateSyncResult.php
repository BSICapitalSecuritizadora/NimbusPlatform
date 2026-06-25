<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuIndexer;
use Carbon\CarbonImmutable;

/**
 * Resumo de uma execução de sincronização de índices (idempotente).
 */
final class IndexRateSyncResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly PuIndexer $indexer,
        public readonly string $source,
        public readonly int $externalSeriesCode,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly CarbonImmutable $fetchedAt,
        public readonly bool $dryRun,
        public int $fetched = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public array $errors = [],
    ) {}

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'indexer' => $this->indexer->value,
            'source' => $this->source,
            'external_series_code' => $this->externalSeriesCode,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'fetched_at' => $this->fetchedAt->toDateTimeString(),
            'dry_run' => $this->dryRun,
            'fetched' => $this->fetched,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
