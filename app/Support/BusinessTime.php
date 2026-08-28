<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;

/**
 * Ponto único de tradução entre o instante técnico (UTC, como a aplicação persiste
 * e compara) e a data civil de negócio (America/Sao_Paulo, como o calendário
 * corporativo, os feriados e os cortes diários são definidos).
 *
 * A aplicação continua inteiramente em UTC: nada aqui altera persistência. O que
 * esta classe resolve é a pergunta "a que dia de negócio pertence este instante?",
 * que em UTC−3 difere do dia UTC entre 21:00 e 23:59 locais.
 */
final class BusinessTime
{
    public static function timezone(): string
    {
        $timezone = Config::get('measurements.business_timezone');

        return filled($timezone) ? (string) $timezone : 'America/Sao_Paulo';
    }

    /**
     * O mesmo instante, relido no fuso de negócio. A conversão não desloca o
     * instante: `startOfDay()`, `endOfDay()`, `year` e `toDateString()` passam a
     * responder pelo dia civil brasileiro.
     */
    public static function at(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone());
    }

    /**
     * O instante de volta ao fuso da aplicação, usado nas bordas de saída para que
     * nada além do cálculo interno enxergue o fuso de negócio.
     */
    public static function toApplication(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(Config::get('app.timezone', 'UTC'));
    }

    /**
     * Data civil de negócio (Y-m-d) do instante — ou de agora, quando omitido.
     */
    public static function dateString(?DateTimeInterface $instant = null): string
    {
        return self::at($instant ?? CarbonImmutable::now())->toDateString();
    }
}
