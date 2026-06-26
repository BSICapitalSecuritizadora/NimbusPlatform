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
     * @param  list<string>  $blockFailures
     * @param  list<string>  $notices
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
        public int $blocksTotal = 0,
        public array $blockFailures = [],
        public array $notices = [],
    ) {}

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addNotice(string $message): void
    {
        $this->notices[] = $message;
    }

    public function hasNotices(): bool
    {
        return $this->notices !== [];
    }

    public function addBlockFailure(string $message): void
    {
        $this->blockFailures[] = $message;
    }

    public function blocksFailed(): int
    {
        return count($this->blockFailures);
    }

    public function blocksSucceeded(): int
    {
        return max(0, $this->blocksTotal - $this->blocksFailed());
    }

    public function hasBlockFailures(): bool
    {
        return $this->blockFailures !== [];
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
            'synced_at' => $this->fetchedAt->toDateTimeString(),
            'dry_run' => $this->dryRun,
            'fetched' => $this->fetched,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'blocks_total' => $this->blocksTotal,
            'blocks_succeeded' => $this->blocksSucceeded(),
            'blocks_failed' => $this->blocksFailed(),
            'block_failures' => $this->blockFailures,
            'notices' => $this->notices,
            'errors' => $this->errors,
        ];
    }
}
