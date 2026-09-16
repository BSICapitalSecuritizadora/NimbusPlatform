<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Support;

use Carbon\CarbonImmutable;

/**
 * Datas móveis derivadas da Páscoa, calculadas pelo algoritmo gregoriano anônimo (Meeus/Jones/Butcher).
 *
 * Existe para uma finalidade defensiva, não para gerar feriados: o endpoint da FEBRABAN responde HTTP
 * 200 para QUALQUER ano, devolvendo apenas os feriados de data fixa quando o ano não está na curadoria
 * publicada. Um ano assim parece válido — o JSON é bem formado e os dias da semana estão corretos —
 * mas silenciosamente omite Carnaval, Sexta-feira da Paixão e Corpus Christi. Importar esse ano
 * marcaria essas datas como dias ÚTEIS.
 *
 * Conferir a presença das datas móveis é o teste que separa um ano curado de um ano inventado pelo
 * fallback da fonte. Nenhuma data é cadastrada a partir daqui: o cálculo só valida a completude do
 * que a fonte devolveu.
 */
final class EasterMovableFeasts
{
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0);
    }

    public static function carnivalMonday(int $year): CarbonImmutable
    {
        return self::easterSunday($year)->subDays(48);
    }

    public static function carnivalTuesday(int $year): CarbonImmutable
    {
        return self::easterSunday($year)->subDays(47);
    }

    public static function ashWednesday(int $year): CarbonImmutable
    {
        return self::easterSunday($year)->subDays(46);
    }

    public static function goodFriday(int $year): CarbonImmutable
    {
        return self::easterSunday($year)->subDays(2);
    }

    public static function corpusChristi(int $year): CarbonImmutable
    {
        return self::easterSunday($year)->addDays(60);
    }

    /**
     * Datas móveis que uma publicação de feriados bancários de âmbito nacional precisa conter para que
     * o ano seja considerado efetivamente publicado. A quarta-feira de cinzas fica de fora de
     * propósito: ela não é feriado de mercado.
     *
     * @return array<string, string> `YYYY-MM-DD` => rótulo
     */
    public static function financialMarketFeasts(int $year): array
    {
        return [
            self::carnivalMonday($year)->toDateString() => 'Carnaval (segunda-feira)',
            self::carnivalTuesday($year)->toDateString() => 'Carnaval (terça-feira)',
            self::goodFriday($year)->toDateString() => 'Sexta-feira da Paixão',
            self::corpusChristi($year)->toDateString() => 'Corpus Christi',
        ];
    }
}
