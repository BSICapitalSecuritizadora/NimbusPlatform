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

    /**
     * The only columns compare() will ever bless as authorized changes.
     *
     * Phase 1B.3's backfill writes exactly these two nullable fund references;
     * everything else — emission_id, balances, Conta Azul identifiers,
     * histories, guarantees, expenses — stays strictly compared. Asking to
     * allow anything else throws instead of silently widening the gate.
     *
     * `bank_application_identity` rides along because it is a database-
     * generated column (COALESCE(bank_application_id, 0)) backing the
     * application-aware fund identity: it cannot be written directly and
     * moves if and only if bank_application_id moves, so blessing it widens
     * nothing the gate protects.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWABLE_BACKFILL_CHANGES = [
        'funds' => ['bank_account_id', 'bank_application_id', 'bank_application_identity'],
    ];

    private const CHUNK_SIZE = 1000;

    private const NULL_MARKER = "\0NULL";

    /**
     * Capture a snapshot of the financial tables on the given connection.
     *
     * All tables are read inside one transaction so concurrent writes cannot
     * tear the picture: under MySQL's REPEATABLE READ every SELECT sees the
     * same consistent view, and on SQLite the DEFERRED transaction holds a
     * shared view for the short read. The transaction issues no writes, so
     * the read-only guard stays satisfied.
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
            $database = DB::connection($connection);

            $fingerprints = $database->transaction(function () use ($connection, $tables): array {
                $fingerprints = [];

                foreach ($tables as $table) {
                    $fingerprints[$table] = $this->fingerprintTable($connection, $table);
                }

                return $fingerprints;
            });

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
     *
     * Strict by default: any digest difference lands in added/removed/changed
     * ids. The optional $allowedChanges declares the future backfill's
     * authorized writes — see {@see self::ALLOWABLE_BACKFILL_CHANGES} — and
     * rows differing solely in allowed columns move to allowed_only_ids
     * instead of changed_ids. Every table echoes the exclusions applied to it
     * in allowed_columns, so an authorized difference is always explicit and
     * never silent. Added and removed rows are never allow-listable: the
     * backfill updates references in place and creates no history.
     *
     * @param  array<string, list<string>>  $allowedChanges
     *
     * @throws InvalidArgumentException When a table is unknown or a column is not an authorized backfill target.
     */
    public function compare(
        FinancialIntegritySnapshot $before,
        FinancialIntegritySnapshot $after,
        array $allowedChanges = []
    ): FinancialIntegrityComparison {
        $allowed = $this->normalizeAllowedChanges($allowedChanges);

        $matches = true;
        $tables = [];

        foreach ($before->tables as $name => $beforeTable) {
            $afterTable = $after->tables[$name] ?? null;
            $allowedColumns = $allowed[$name] ?? [];

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
                    'allowed_columns' => $allowedColumns,
                    'allowed_only_ids' => [],
                ];

                continue;
            }

            $tableMatches = $beforeTable['digest'] === $afterTable['digest']
                && $beforeTable['columns'] === $afterTable['columns'];

            $added = [];
            $removed = [];
            $changed = [];
            $allowedOnly = [];

            if (! $tableMatches) {
                $matches = false;

                foreach ($afterTable['rows'] as $id => $digest) {
                    if (! array_key_exists($id, $beforeTable['rows'])) {
                        $added[] = $id;
                    } elseif ($beforeTable['rows'][$id] !== $digest) {
                        if ($this->differsOnlyInAllowedColumns($beforeTable, $afterTable, $id, $allowedColumns)) {
                            $allowedOnly[] = $id;
                        } else {
                            $changed[] = $id;
                        }
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
                sort($allowedOnly);
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
                'allowed_columns' => $allowedColumns,
                'allowed_only_ids' => $allowedOnly,
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
                'allowed_columns' => $allowed[$name] ?? [],
                'allowed_only_ids' => [],
            ];
        }

        ksort($tables);

        return new FinancialIntegrityComparison($matches, $tables);
    }

    /**
     * Validate a caller's allowance request against the backfill allow-list.
     *
     * @param  array<string, list<string>>  $allowedChanges
     * @return array<string, list<string>>
     *
     * @throws InvalidArgumentException When a table is unknown or a column is not an authorized backfill target.
     */
    private function normalizeAllowedChanges(array $allowedChanges): array
    {
        $normalized = [];

        foreach ($allowedChanges as $table => $columns) {
            if (! in_array($table, self::TABLES, true)) {
                throw new InvalidArgumentException(
                    "Cannot allow changes on unknown baseline table [{$table}]."
                );
            }

            $allowable = self::ALLOWABLE_BACKFILL_CHANGES[$table] ?? [];

            foreach ($columns as $column) {
                if (! in_array($column, $allowable, true)) {
                    throw new InvalidArgumentException(
                        "Column [{$table}.{$column}] is not an authorized backfill target."
                    );
                }

                $normalized[$table][] = $column;
            }

            $normalized[$table] = array_values(array_unique($normalized[$table] ?? []));
        }

        return $normalized;
    }

    /**
     * Whether a row present in both snapshots differs solely in allowed columns.
     *
     * A row whose digest moved but whose every changed column is allowed is an
     * authorized backfill write; anything else fails strict. Snapshots taken
     * before per-column digests existed carry no cells, and those rows fail
     * strict as well — the gate never assumes an unseen difference is safe.
     *
     * @param  array{columns: list<string>, row_count: int, digest: string, rows: array<int, string>, cells?: array<int, array<string, string>>}  $beforeTable
     * @param  array{columns: list<string>, row_count: int, digest: string, rows: array<int, string>, cells?: array<int, array<string, string>>}  $afterTable
     * @param  list<string>  $allowedColumns
     */
    private function differsOnlyInAllowedColumns(array $beforeTable, array $afterTable, int|string $id, array $allowedColumns): bool
    {
        if ($allowedColumns === []) {
            return false;
        }

        $beforeCells = $beforeTable['cells'][$id] ?? null;
        $afterCells = $afterTable['cells'][$id] ?? null;

        if ($beforeCells === null || $afterCells === null) {
            return false;
        }

        $changedColumns = [];

        foreach (array_unique([...array_keys($beforeCells), ...array_keys($afterCells)]) as $column) {
            $beforeDigest = $beforeCells[$column] ?? self::nullCellDigest();
            $afterDigest = $afterCells[$column] ?? self::nullCellDigest();

            if ($beforeDigest !== $afterDigest) {
                $changedColumns[] = $column;
            }
        }

        return $changedColumns !== [] && array_diff($changedColumns, $allowedColumns) === [];
    }

    private static function nullCellDigest(): string
    {
        return hash('sha256', self::NULL_MARKER);
    }

    /**
     * @return array{columns: list<string>, row_count: int, digest: string, rows: array<int, string>, cells: array<int, array<string, string>>}
     */
    private function fingerprintTable(string $connection, string $table): array
    {
        $columns = Schema::connection($connection)->getColumnListing($table);

        sort($columns);

        if (! in_array('id', $columns, true)) {
            throw new InvalidArgumentException("Table [{$table}] has no [id] column to order by.");
        }

        $rows = [];
        $cells = [];

        DB::connection($connection)
            ->table($table)
            ->select($columns)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($records) use ($columns, &$rows, &$cells): void {
                foreach ($records as $record) {
                    $id = (int) $record->id;
                    $serialized = [];
                    $cellDigests = [];

                    foreach ($columns as $column) {
                        $canonical = self::canonicalValue($record->{$column} ?? null);
                        $serialized[$column] = $canonical;
                        $cellDigests[$column] = hash('sha256', $canonical);
                    }

                    $rows[$id] = hash(
                        'sha256',
                        (string) json_encode(
                            [$id, $serialized],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )
                    );
                    $cells[$id] = $cellDigests;
                }
            }, 'id');

        ksort($rows);
        ksort($cells);

        return [
            'columns' => $columns,
            'row_count' => count($rows),
            'digest' => hash('sha256', implode('', $rows)),
            'rows' => $rows,
            'cells' => $cells,
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
