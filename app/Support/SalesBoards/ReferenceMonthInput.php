<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Interpreta a competência informada por uma pessoa -- e devolve sempre o
 * primeiro dia do mês. Uma competência é o mês inteiro; guardar 15/07 como
 * "julho" convidaria toda comparação seguinte a errar por dia.
 *
 * Dois caminhos, porque são duas entradas diferentes:
 *
 * - **a linha de comando** ({@see self::parse()}) aceita só `mm/aaaa` -- como
 *   o operador escreve -- e `aaaa-mm`. Nada é adivinhado: `07/25`, `7/2026`,
 *   `now` e `last month` eram aceitos pelo `Carbon::parse()`, e um ciclo gerado
 *   por engano só sai por cancelamento;
 * - **o DatePicker da tela** ({@see self::fromDateState()}) entrega uma data
 *   completa, `aaaa-mm-dd hh:mm:ss`. Tornar o leitor da linha de comando
 *   estrito sem separar os caminhos quebraria o "Congelar competência".
 *
 * Os dois devolvem `null` para entrada inválida em vez de lançar: quem chama é
 * um comando de terminal ou uma ação de tela, e a resposta certa ali é uma
 * mensagem, não um stack trace.
 */
final class ReferenceMonthInput
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('#^(\d{2})/(\d{4})$#', $value, $matches) === 1) {
            return self::firstDay((int) $matches[2], (int) $matches[1]);
        }

        if (preg_match('#^(\d{4})-(\d{2})$#', $value, $matches) === 1) {
            return self::firstDay((int) $matches[1], (int) $matches[2]);
        }

        return null;
    }

    /**
     * A competência que o DatePicker escolheu: o primeiro dia do mês da data.
     *
     * Aceita a instância de data, `aaaa-mm-dd` e `aaaa-mm-dd hh:mm:ss` -- o
     * formato em que o DatePicker não nativo guarda o estado --, sempre com o
     * dia conferido por `checkdate`. Qualquer outra coisa é `null`.
     */
    public static function fromDateState(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return self::firstDay((int) $value->format('Y'), (int) $value->format('n'));
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', trim($value), $matches) !== 1) {
            return null;
        }

        [, $year, $month, $day] = array_map('intval', $matches);

        return checkdate($month, $day, $year) ? self::firstDay($year, $month) : null;
    }

    /**
     * A competência informada, ou o mês anterior.
     *
     * O padrão é o mês fechado, nunca o corrente: uma posição do mês em curso
     * seria tirada antes de o mês acabar. "Mês anterior" é o do calendário de
     * negócio, e quem responde é o {@see CompetenceCalendar} -- sem o
     * transbordo do dia 31 e sem o dia UTC que já virou às 21h de Brasília.
     */
    public static function parseOrPreviousMonth(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return CompetenceCalendar::lastClosedMonth();
        }

        return self::parse($value);
    }

    private static function firstDay(int $year, int $month): ?CarbonImmutable
    {
        return checkdate($month, 1, $year)
            ? CarbonImmutable::create($year, $month, 1)
            : null;
    }
}
