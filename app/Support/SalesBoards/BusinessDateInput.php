<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use Carbon\CarbonImmutable;

/**
 * Interpreta a data de negócio forçada na linha de comando (`--as-of`).
 *
 * Só `aaaa-mm-dd`, com o dia conferido por `checkdate`. Uma data de negócio
 * forçada decide quais competências estão devidas, e não se adivinha: o
 * `Carbon::parse()` aceitava `now`, `last month` e `07/25`, e um engano de
 * digitação virava uma execução de outro dia. Entrada inválida devolve `null`,
 * e quem chama responde com uma mensagem antes de qualquer escrita.
 */
final class BusinessDateInput
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
            return null;
        }

        [, $year, $month, $day] = array_map('intval', $matches);

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
