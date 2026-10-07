<?php

namespace Tests\Support;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * As recusas do banco que as telas tratam como "tente de novo", e uma que não
 * é, para contraste.
 *
 * - `deadlock`: o MySQL matou a transação num ciclo de locks (1213, SQLSTATE
 *   40001), como chega na transação de topo;
 * - `lock wait timeout`: a espera por um lock passou do
 *   `innodb_lock_wait_timeout` (1205);
 * - `deadlock inside an outer transaction`: o mesmo 1213 dentro de outra
 *   transação, que o Laravel embrulha numa `DeadlockException`;
 * - `integrity violation`: violação de chave única (23000) -- erro da
 *   aplicação, não de concorrência; segue para o tratamento padrão.
 */
final class DatabaseConcurrencyFailure
{
    public const CASES = ['deadlock', 'lock wait timeout', 'deadlock inside an outer transaction', 'integrity violation'];

    public static function make(string $failure): Throwable
    {
        $deadlock = new QueryException(
            'mysql',
            'update `measurements` set `notes` = ? where `id` = ?',
            ['x', 1],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
        );

        return match ($failure) {
            'deadlock' => $deadlock,
            'lock wait timeout' => new QueryException(
                'mysql',
                'select * from `operations` where `operations`.`id` = ? limit 1 for update',
                [1],
                new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
            ),
            'deadlock inside an outer transaction' => new DeadlockException($deadlock->getMessage(), 0, $deadlock),
            'integrity violation' => new QueryException(
                'mysql',
                'insert into `operation_user` (`operation_id`, `user_id`) values (?, ?)',
                [1, 1],
                new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
            ),
        };
    }

    public static function isConcurrency(string $failure): bool
    {
        return $failure !== 'integrity violation';
    }
}
