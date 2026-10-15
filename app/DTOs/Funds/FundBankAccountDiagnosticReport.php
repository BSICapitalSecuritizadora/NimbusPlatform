<?php

declare(strict_types=1);

namespace App\DTOs\Funds;

use App\DTOs\BaseDTO;

/**
 * Result of the Phase 1B.1 bank-account diagnostic over the funds table.
 *
 * Everything in here is derived from reads: masked identifiers, aggregate
 * totals, conflict listings and the per-fund dry-run preview. Nothing in the
 * report holds a live model, so serializing it can never touch the database.
 */
readonly class FundBankAccountDiagnosticReport extends BaseDTO
{
    /**
     * @param  array<string, int>  $totals
     * @param  array<string, array<int, array<string, mixed>>>  $conflicts
     * @param  list<array<string, mixed>>  $preview
     */
    public function __construct(
        public string $connection,
        public string $driver,
        public string $database,
        public string $source,
        public ?string $sourceNote,
        public int $chunkSize,
        public ?int $limit,
        public bool $truncated,
        public string $generatedAt,
        public array $totals,
        public array $conflicts,
        public array $preview,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'driver' => $this->driver,
            'database' => $this->database,
            'source' => $this->source,
            'source_note' => $this->sourceNote,
            'chunk_size' => $this->chunkSize,
            'limit' => $this->limit,
            'truncated' => $this->truncated,
            'generated_at' => $this->generatedAt,
            'read_only' => true,
            'totals' => $this->totals,
            'conflicts' => $this->conflicts,
            'preview' => $this->preview,
        ];
    }
}
