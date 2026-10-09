<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Quando uma observação do CDI passa a ser ESPERADA no banco (Fase 3, centralizado
 * na Fase 6).
 *
 * A observação do dia útil D é divulgada no dia útil D + `publication_lag_business_days`
 * do calendário de divulgação, a partir de `available_after` (horário de Brasília).
 * Antes disso a falta dela é "ainda não divulgada", nunca "atrasada". É a mesma
 * regra para a atualidade da curva oficial
 * ({@see PuOfficialCurveFreshnessService}) e para o monitor -- uma interpretação só.
 * Feriado e fim de semana saem do calendário: no primeiro dia útil depois de um
 * feriado, a observação esperada é a do último dia útil antes dele.
 */
final class PuIndexPublicationPolicy
{
    public function __construct(
        private readonly BusinessDayCalendar $calendar,
    ) {}

    public function lagBusinessDays(): int
    {
        return max(0, (int) config('pu_indexes.bcb.series.cdi.publication_lag_business_days', 1));
    }

    public function availableAfter(): string
    {
        return (string) config('pu_indexes.bcb.series.cdi.available_after', '07:00');
    }

    /**
     * A observação mais recente que já deveria estar no banco: a do dia útil de
     * divulgação cuja hora já passou, recuado da defasagem de divulgação.
     */
    public function expectedLatestRateDate(string $calendarCode, CarbonInterface $now): CarbonImmutable
    {
        $local = BusinessTime::at($now);
        $today = CarbonImmutable::parse($local->toDateString())->startOfDay();
        $publishedToday = $local->format('H:i') >= $this->availableAfter()
            && $this->calendar->isBusinessDay($today, $calendarCode);
        $publicationDay = $publishedToday ? $today : $this->calendar->shiftBusinessDays($today, -1, $calendarCode);
        $lag = $this->lagBusinessDays();

        return $lag === 0
            ? $publicationDay
            : $this->calendar->shiftBusinessDays($publicationDay, -$lag, $calendarCode);
    }

    /**
     * O instante (fuso técnico da aplicação) a partir do qual a observação de
     * `$observationDate` é esperada no banco.
     */
    public function expectedAvailabilityAt(CarbonImmutable $observationDate, string $calendarCode): CarbonImmutable
    {
        $lag = $this->lagBusinessDays();
        $observation = $observationDate->startOfDay();
        $publicationDay = $lag === 0
            ? ($this->calendar->isBusinessDay($observation, $calendarCode) ? $observation : $this->calendar->nextBusinessDay($observation, $calendarCode))
            : $this->calendar->shiftBusinessDays($observation, $lag, $calendarCode);

        return BusinessTime::toApplication(
            CarbonImmutable::parse($publicationDay->toDateString().' '.$this->availableAfter(), BusinessTime::timezone()),
        );
    }
}
