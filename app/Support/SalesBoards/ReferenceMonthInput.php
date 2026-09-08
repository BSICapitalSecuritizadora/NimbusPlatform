<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Interpreta a competência informada na linha de comando.
 *
 * Aceita `mm/aaaa` -- como o operador escreve -- e `aaaa-mm`, e devolve sempre o
 * primeiro dia do mês. Uma competência é o mês inteiro; guardar 15/07 como
 * "julho" convidaria toda comparação seguinte a errar por dia.
 *
 * Devolve `null` para entrada inválida em vez de lançar: quem chama é um comando
 * de terminal, e a resposta certa ali é uma mensagem, não um stack trace.
 */
final class ReferenceMonthInput
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('#^(\d{2})/(\d{4})$#', $value, $matches) === 1) {
            return checkdate((int) $matches[1], 1, (int) $matches[2])
                ? CarbonImmutable::create((int) $matches[2], (int) $matches[1], 1)
                : null;
        }

        if (preg_match('#^(\d{4})-(\d{2})$#', $value, $matches) === 1) {
            return checkdate((int) $matches[2], 1, (int) $matches[1])
                ? CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1)
                : null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfMonth();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A competência informada, ou o mês anterior.
     *
     * O padrão é o mês fechado, nunca o corrente: uma posição do mês em curso
     * seria tirada antes de o mês acabar.
     */
    public static function parseOrPreviousMonth(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return CarbonImmutable::now()->subMonth()->startOfMonth();
        }

        return self::parse($value);
    }
}
