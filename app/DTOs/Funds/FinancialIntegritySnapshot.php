<?php

declare(strict_types=1);

namespace App\DTOs\Funds;

use App\DTOs\BaseDTO;
use App\Services\Funds\FinancialIntegrityBaseline;

/**
 * A reproducible read-only fingerprint of the financial tables.
 *
 * Each table contributes its serialized column list, its row count, one
 * SHA-256 digest over the per-row digests in primary-key order, and the
 * per-row digests themselves, so a later comparison can name exactly which
 * rows changed instead of only reporting that something did.
 *
 * @see FinancialIntegrityBaseline
 */
readonly class FinancialIntegritySnapshot extends BaseDTO
{
    /**
     * @param  array<string, array{columns: list<string>, row_count: int, digest: string, rows: array<int, string>, cells: array<int, array<string, string>>}>  $tables
     */
    public function __construct(
        public string $connection,
        public string $driver,
        public string $database,
        public string $generatedAt,
        public array $tables,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withRowDigests = false): array
    {
        $tables = [];

        foreach ($this->tables as $name => $table) {
            $tables[$name] = [
                'columns' => $table['columns'],
                'row_count' => $table['row_count'],
                'digest' => $table['digest'],
            ];

            if ($withRowDigests) {
                $tables[$name]['rows'] = $table['rows'];
            }
        }

        return [
            'connection' => $this->connection,
            'driver' => $this->driver,
            'database' => $this->database,
            'generated_at' => $this->generatedAt,
            'read_only' => true,
            'tables' => $tables,
        ];
    }
}
