<?php

namespace App\Services\Funds;

use App\DTOs\Funds\FinancialIntegrityComparison;
use App\DTOs\Funds\FinancialIntegritySnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Reusable read-only fingerprint of the financial tables.
 *
 * Phase 1B.1 needs a "before" picture it can trust, and the future 1B
 * migration needs the same code to take the "after" picture: any divergence
 * outside the migration's declared writes is then visible row by row.
 *
 * Deliberately not a BIT_XOR(CRC32(...)) checksum. A 32-bit xor folds
 * collisions into silence and says nothing about which row moved. Each row
 * here gets its own SHA-256 over an explicit, ordered, null-aware
 * serialization, and each table digest chains those in primary-key order.
 *
 * Rules that keep the output reproducible:
 *
 * - Columns come from the schema introspection and are serialized in
 *   alphabetical order, so physical column order never matters.
 * - Rows are read in primary-key order (`chunkById`), so insertion order
 *   never matters.
 * - NULL serializes as an explicit marker that no string value can collide
 *   with; booleans become "0"/"1"; every other scalar becomes its string
 *   form without trimming, so a stray space still changes the digest.
 * - Datetimes serialize from the raw stored value, not from a cast, so the
 *   fingerprint sees what the database holds.
 *
 * All reads run under {@see ReadOnlyDiagnosticGuard}.
 */
class FinancialIntegrityBaseline
{
    /**
     * Financial tables covered by the baseline: funds, their balances and
     * histories, guarantees and their records, expenses and their histories,
     * including every Conta Azul identifier column.
     *
     * @var list<string>
     */
    public const TABLES = [
        'funds',
        'fund_balance_histories',
        'guarantees',
        'guarantee_events',
        'guarantee_valuations',
        'guarantee_monthly_positions',
        'expenses',
        'expense_histories',
    ];

    private const CHUNK_SIZE = 1000;

    private const NULL_MARKER = "\0NULL";

    /**
     * Capture a snapshot of the financial tables on the given connection.
     *
     * @param  list<string>|null  $tables
     *
     * @throws InvalidArgumentException When a requested table is unknown.
     */
    public function snapshot(string $connection, ?array $tables = null): FinancialIntegritySnapshot
    {
        $tables ??= self::TABLES;

        $unknown = array_values(array_diff($tables, self::TABLES));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown baseline tables: '.implode(', ', $unknown).'.'
            );
        }

        return ReadOnlyDiagnosticGuard::run($connection, function () use ($connection, $tables): FinancialIntegritySnapshot {
            $fingerprints = [];

            foreach ($tables as $table) {
                $fingerprints[$table] = $this->fingerprintTable($connection, $table);
            }

            $database = DB::connection($connection);

            return new FinancialIntegritySnapshot(
                connection: $connection,
                driver: $database->getDriverName(),
                database: (string) $database->getDatabaseName(),
                generatedAt: now()->toIso8601String(),
                tables: $fingerprints,
            );
        });
    }

    /**
     * Compare two snapshots of the same database, row by row.
     */
    public function compare(
        FinancialIntegritySnapshot $before,
        FinancialIntegritySnapshot $after
    ): FinancialIntegrityComparison {
        $matches = true;
        $tables = [];

        foreach ($before->tables as $name => $beforeTable) {
            $afterTable = $after->tables[$name] ?? null;

            if ($afterTable === null) {
                $matches = false;

                $tables[$name] = [
                    'matches' => false,
                    'digest_before' => $beforeTable['digest'],
                    'digest_after' => null,
                    'row_count_before' => $beforeTable['row_count'],
                    'row_count_after' => 0,
                    'added_ids' => [],
                    'removed_ids' => array_keys($beforeTable['rows']),
                    'changed_ids' => [],
                ];

                continue;
            }

            $tableMatches = $beforeTable['digest'] === $afterTable['digest']
                && $beforeTable['columns'] === $afterTable['columns'];

            $added = [];
            $removed = [];
            $changed = [];

            if (! $tableMatches) {
                $matches = false;

                foreach ($afterTable['rows'] as $id => $digest) {
                    if (! array_key_exists($id, $beforeTable['rows'])) {
                        $added[] = $id;
                    } elseif ($beforeTable['rows'][$id] !== $digest) {
                        $changed[] = $id;
                    }
                }

                foreach ($beforeTable['rows'] as $id => $digest) {
                    if (! array_key_exists($id, $afterTable['rows'])) {
                        $removed[] = $id;
                    }
                }

                sort($added);
                sort($removed);
                sort($changed);
            }

            $tables[$name] = [
                'matches' => $tableMatches,
                'digest_before' => $beforeTable['digest'],
                'digest_after' => $afterTable['digest'],
                'row_count_before' => $beforeTable['row_count'],
                'row_count_after' => $afterTable['row_count'],
                'added_ids' => $added,
                'removed_ids' => $removed,
                'changed_ids' => $changed,
            ];
        }

        foreach ($after->tables as $name => $afterTable) {
            if (array_key_exists($name, $tables)) {
                continue;
            }

            $matches = false;

            $tables[$name] = [
                'matches' => false,
                'digest_before' => null,
                'digest_after' => $afterTable['digest'],
                'row_count_before' => 0,
                'row_count_after' => $afterTable['row_count'],
                'added_ids' => array_keys($afterTable['rows']),
                'removed_ids' => [],
                'changed_ids' => [],
            ];
        }

        ksort($tables);

        return new FinancialIntegrityComparison($matches, $tables);
    }

    /**
     * @return array{columns: list<string>, row_count: int, digest: string, rows: array<int, string>}
     */
    private function fingerprintTable(string $connection, string $table): array
    {
        $columns = Schema::connection($connection)->getColumnListing($table);

        sort($columns);

        if (! in_array('id', $columns, true)) {
            throw new InvalidArgumentException("Table [{$table}] has no [id] column to order by.");
        }

        $rows = [];

        DB::connection($connection)
            ->table($table)
            ->select($columns)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($records) use ($columns, &$rows): void {
                foreach ($records as $record) {
                    $id = (int) $record->id;
                    $serialized = [];

                    foreach ($columns as $column) {
                        $serialized[$column] = self::canonicalValue($record->{$column} ?? null);
                    }

                    $rows[$id] = hash(
                        'sha256',
                        (string) json_encode(
                            [$id, $serialized],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )
                    );
                }
            }, 'id');

        ksort($rows);

        return [
            'columns' => $columns,
            'row_count' => count($rows),
            'digest' => hash('sha256', implode('', $rows)),
            'rows' => $rows,
        ];
    }

    private static function canonicalValue(mixed $value): string
    {
        if ($value === null) {
            return self::NULL_MARKER;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u P');
        }

        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
