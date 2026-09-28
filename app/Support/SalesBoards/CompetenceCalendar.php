<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Qual competência já terminou no calendário de negócio.
 *
 * Uma competência só está encerrada depois que o seu último dia passou **no dia
 * civil de Brasília**, não no dia UTC. Entre 21h e 23h59 do último dia do mês o
 * relógio da aplicação já está no mês seguinte; perguntar a ele faria o mês ainda
 * aberto parecer fechado por três horas.
 *
 * A aritmética parte sempre do primeiro dia do mês. `now()->subMonth()` no dia 31
 * cai num dia que não existe no mês anterior, transborda e devolve o próprio mês
 * -- em 31/10 o "mês anterior" seria outubro. Partindo do dia 1º não há dia para
 * transbordar.
 */
final class CompetenceCalendar
{
    /**
     * A competência encerrada mais recente: o mês anterior ao mês de negócio de
     * hoje (ou do instante informado).
     */
    public static function lastClosedMonth(?DateTimeInterface $instant = null): CarbonImmutable
    {
        return CarbonImmutable::parse(BusinessTime::dateString($instant))
            ->startOfMonth()
            ->subMonthNoOverflow();
    }

    /**
     * O último dia da competência já passou no calendário de negócio?
     */
    public static function isClosed(CarbonInterface $referenceMonth, ?DateTimeInterface $instant = null): bool
    {
        return CarbonImmutable::parse($referenceMonth->toDateString())
            ->startOfMonth()
            ->lessThanOrEqualTo(self::lastClosedMonth($instant));
    }
}
