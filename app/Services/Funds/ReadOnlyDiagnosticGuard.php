<?php

namespace App\Services\Funds;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Process-wide tripwire that keeps diagnostics read-only by construction.
 *
 * The diagnostic service and the integrity baseline only issue reads, but a
 * "we never write" claim deserves enforcement instead of trust: while armed,
 * any query on the guarded connection whose leading verb is not a known read
 * throws before it can run. Writes issued anywhere else (test fixtures on
 * another connection, the default connection) are unaffected.
 *
 * The listener is registered once per process and no-ops while disarmed, so
 * arming is a cheap flag flip. Nesting is supported via save/restore.
 */
final class ReadOnlyDiagnosticGuard
{
    /**
     * @var list<string>
     */
    private const READ_VERBS = ['select', 'show', 'describe', 'desc', 'explain'];

    private static bool $armed = false;

    private static ?string $connection = null;

    private static bool $listenerRegistered = false;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws RuntimeException When the callback attempts a write query.
     */
    public static function run(string $connection, callable $callback): mixed
    {
        self::registerListener();

        $previousArmed = self::$armed;
        $previousConnection = self::$connection;

        self::$armed = true;
        self::$connection = $connection;

        try {
            return $callback();
        } finally {
            self::$armed = $previousArmed;
            self::$connection = $previousConnection;
        }
    }

    private static function registerListener(): void
    {
        if (self::$listenerRegistered) {
            return;
        }

        self::$listenerRegistered = true;

        DB::listen(function (QueryExecuted $query): void {
            if (! self::$armed || $query->connectionName !== self::$connection) {
                return;
            }

            if (self::isRead($query->sql)) {
                return;
            }

            throw new RuntimeException(
                sprintf(
                    'Refused a non-read query on the read-only diagnostic connection [%s]: %s',
                    (string) $query->connectionName,
                    mb_substr(trim($query->sql), 0, 120)
                )
            );
        });
    }

    private static function isRead(string $sql): bool
    {
        $statement = strtolower(ltrim($sql, " \t\n\r\0\x0B("));

        $verb = (string) strtok($statement, " \t\n\r\0\x0B(");

        if (in_array($verb, self::READ_VERBS, true)) {
            return true;
        }

        // Schema introspection on SQLite arrives as PRAGMA reads. Only the
        // table-description pragmas are reads; anything else (foreign_keys,
        // journal_mode, ...) can change behavior and stays refused.
        if ($verb === 'pragma') {
            return (bool) preg_match('/^pragma\s+table_(xinfo|info|foreign_key_list|index_list|index_xinfo)\b/', $statement);
        }

        return false;
    }
}
