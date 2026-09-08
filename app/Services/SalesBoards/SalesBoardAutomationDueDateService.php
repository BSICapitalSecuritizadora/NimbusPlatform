<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Quando uma competência passa a ser devida.
 *
 * A regra é uma só: **no dia 13 do mês seguinte, a competência do mês anterior
 * fica devida**. Dia 13 do calendário civil, no fuso de negócio -- não 13º dia
 * útil, não deslocado por fim de semana, não deslocado por feriado. Nada no
 * código nem na operação define outra coisa, e inventar dia útil aqui mudaria
 * silenciosamente a data de fechamento de todo mês com feriado no começo.
 *
 * A decisão é de **data**, não de horário. Modelá-la como "às 08:00 do dia 13"
 * faria a competência inteira depender de o processo estar de pé naquele
 * minuto; modelada como data, um scheduler que passou três dias fora volta e
 * encontra a competência ainda devida. É o que torna o catch-up consequência da
 * regra, e não um mecanismo à parte.
 *
 * O fuso importa e não é decoração: em UTC−3, entre 21h e 23h59 locais o dia UTC
 * já virou. Perguntar `now()->day` em UTC anteciparia todo fechamento em três
 * horas, e uma vez por mês isso significa gerar a competência um dia antes do
 * que o negócio combinou.
 */
class SalesBoardAutomationDueDateService
{
    /**
     * O dia civil a partir do qual a competência anterior fica devida.
     */
    public const DUE_DAY_OF_MONTH = 13;

    /**
     * A data de negócio de um instante -- ou de agora.
     *
     * Todo o resto do serviço parte daqui, e é isto que torna a regra testável
     * com relógio controlado: nenhum outro ponto chama `now()`.
     */
    public function businessDate(?DateTimeInterface $instant = null): CarbonImmutable
    {
        return CarbonImmutable::parse(BusinessTime::dateString($instant))->startOfDay();
    }

    /**
     * A competência mais recente já devida na data indicada.
     *
     * Em 12/09 a resposta é julho: agosto ainda não venceu. Em 13/09 passa a ser
     * agosto. A virada de ano cai fora do caso especial sozinha -- `subMonths()`
     * leva 13/01/2027 a dezembro/2026 sem que exista aritmética de ano aqui.
     */
    public function latestDueReferenceMonth(CarbonImmutable $businessDate): CarbonImmutable
    {
        $month = $businessDate->startOfMonth();

        return $businessDate->day >= self::DUE_DAY_OF_MONTH
            ? $month->subMonth()
            : $month->subMonths(2);
    }

    /**
     * A data em que uma competência fica devida: dia 13 do mês seguinte.
     */
    public function dueDateFor(CarbonImmutable $referenceMonth): CarbonImmutable
    {
        return $referenceMonth->startOfMonth()
            ->addMonth()
            ->setDay(self::DUE_DAY_OF_MONTH)
            ->startOfDay();
    }

    public function isDue(CarbonImmutable $referenceMonth, CarbonImmutable $businessDate): bool
    {
        return $businessDate->greaterThanOrEqualTo($this->dueDateFor($referenceMonth));
    }

    /**
     * Todas as competências devidas desde a ativação, da mais antiga para a mais
     * recente.
     *
     * É aqui que o catch-up entre meses acontece: um alvo ativado em agosto e
     * observado em 15/11 devolve agosto, setembro e outubro -- as três já
     * vencidas -- e não novembro, que ainda não venceu.
     *
     * E é aqui que o backfill não acontece: a varredura **começa** na ativação.
     * Sem esse piso, ligar a automação de um empreendimento com três anos de
     * histórico dispararia trinta e seis derivações completas de competências
     * que ninguém pediu.
     *
     * @return list<CarbonImmutable> primeiro dia de cada competência devida
     */
    public function dueReferenceMonths(
        CarbonImmutable $startReferenceMonth,
        CarbonImmutable $businessDate,
    ): array {
        $start = $startReferenceMonth->startOfMonth();
        $latest = $this->latestDueReferenceMonth($businessDate);

        if ($start->greaterThan($latest)) {
            return [];
        }

        $months = [];

        for ($month = $start; $month->lessThanOrEqualTo($latest); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $months;
    }
}
