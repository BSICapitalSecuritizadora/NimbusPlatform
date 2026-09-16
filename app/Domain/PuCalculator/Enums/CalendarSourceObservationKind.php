<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * O QUE uma fonte financeira afirmou sobre uma data. É a distinção que impede o erro central desta
 * integração: nem tudo que a FEBRABAN publica numa página de "feriados bancários" é dia não útil de
 * mercado.
 *
 * A FEBRABAN expõe duas tabelas distintas para o mesmo ano:
 *
 * - a primeira, sob a Resolução CMN 4.880/2020, lista datas que "não são consideradas dias úteis para
 *   fins de operações praticadas no mercado financeiro e de prestação de informações ao Banco Central" —
 *   {@see self::FinancialNonBusinessDay};
 * - a segunda trata de ATENDIMENTO: quarta-feira de cinzas (horário recomendado às agências) e o último
 *   dia útil do ano, em que "não haverá expediente ao público, admitindo-se apenas operações entre
 *   instituições financeiras e serviços de compensação" — {@see self::SpecialBankingHours}.
 *
 * Fechamento de agência não é feriado financeiro: a segunda tabela descreve dias em que o mercado
 * OPERA. Observações desse tipo são registradas como evidência e nunca viram `is_business_day = false`.
 */
enum CalendarSourceObservationKind: string
{
    case FinancialNonBusinessDay = 'financial_non_business_day';

    case SpecialBankingHours = 'special_banking_hours';

    public function label(): string
    {
        return match ($this) {
            self::FinancialNonBusinessDay => 'Dia não útil de mercado',
            self::SpecialBankingHours => 'Expediente especial de agência',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FinancialNonBusinessDay => 'danger',
            self::SpecialBankingHours => 'warning',
        };
    }

    /**
     * Somente observações de mercado são elegíveis a virar decisão de calendário. Expediente especial
     * entra no dossiê de evidência e para por aí.
     */
    public function affectsBusinessDayDecision(): bool
    {
        return $this === self::FinancialNonBusinessDay;
    }
}
