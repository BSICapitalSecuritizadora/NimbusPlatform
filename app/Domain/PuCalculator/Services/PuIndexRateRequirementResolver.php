<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\RealizedIndexRateProvider;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PuIndexRateRequirementResolver
{
    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
        private readonly RealizedIndexRateProvider $realizedRates,
    ) {}

    /**
     * Requisito de taxa de um dia da curva.
     *
     * Dois calendários distintos convivem aqui, e a distinção é semântica:
     *
     *  - o calendário de ACCRUAL decide se o dia da curva é dia útil, isto é,
     *    se ele acumula juros. Por padrão é o CONTRATUAL (`$parameter->calendar_code`);
     *    `$accrualCalendarCode` só o substitui sob hipótese explícita de simulação,
     *    e jamais alcança eventos, convenção Following ou datas de pagamento;
     *  - o calendário de OBSERVAÇÃO do índice decide em que dia a taxa foi
     *    divulgada: para onde o lag de `BusinessDayLagExact` aponta, qual é o
     *    último dia de divulgação de `PreviousAvailableBusinessDay` e se o dia
     *    anterior de `PreviousCalendarDayExact` teve CDI
     *    ({@see self::observationCalendarCode()}).
     *
     * Na curva oficial eles coincidem: o contrato segue o calendário bancário,
     * que é o de divulgação. Divergem quando o calendário do contrato não sabe
     * de um feriado bancário que não é feriado nacional legal, em que não há
     * publicação de taxa num dia que o contrato conta como útil.
     *
     * A data de observação sai SEMPRE da regra e do calendário, nunca dos dados:
     * a taxa devolvida é a observação realizada exatamente nessa data, ou nula.
     * Não há busca para trás pela "última taxa conhecida" -- foi ela que, no modo
     * `PreviousAvailableBusinessDay`, repetia o último CDI até o vencimento e
     * fazia o futuro parecer realizado. Projeção também nunca responde aqui.
     *
     * Esta é a ÚNICA regra de data de observação: engine, pré-requisitos,
     * cobertura, extensão e atualidade da curva oficial perguntam aqui, e por
     * isso nunca discordam sobre qual observação uma data exige.
     */
    public function resolve(
        EmissionPuParameter $parameter,
        CarbonImmutable $curveDate,
        ?string $indexRateCalendarCode = null,
        ?string $accrualCalendarCode = null,
    ): PuIndexRateRequirement {
        $lookupMode = $parameter->index_rate_lookup_mode_enum;
        $calendarCode = $this->accrualCalendarCode($parameter, $accrualCalendarCode);
        $rateCalendarCode = $this->observationCalendarCode($parameter, $indexRateCalendarCode);
        $businessDayLag = (int) $parameter->index_rate_lag_business_days;
        // Acúmulo de juros segue o calendário de accrual: contratual por padrão.
        $isBusinessDay = $this->businessDayCalendar->isBusinessDay($curveDate, $calendarCode);

        $lookupDate = match ($lookupMode) {
            // Último dia de DIVULGAÇÃO igual ou anterior à data da curva: a própria
            // data quando o divulgador também a conta como útil; o dia útil anterior
            // do calendário de observação quando o contrato acumula num dia em que o
            // índice não é divulgado. O recuo é limitado pelo calendário.
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $isBusinessDay
                ? $this->latestBusinessDayOnOrBefore($curveDate, $rateCalendarCode)
                : null,
            // A observação do dia CALENDÁRIO anterior -- quando ele foi dia de
            // divulgação. Sábado, domingo e feriado bancário não têm CDI: a data
            // seguinte a eles não exige observação e o Fator DI dela é 1. Exigir o
            // CDI de sábado no domingo não tem sentido financeiro e bloqueava o modo.
            PuIndexRateLookupMode::PreviousCalendarDayExact => $this->businessDayCalendar->isBusinessDay(
                $curveDate->subDay(),
                $rateCalendarCode,
            ) ? $curveDate->subDay() : null,
            // Deslocamento até a data de DIVULGAÇÃO: calendário de observação.
            PuIndexRateLookupMode::BusinessDayLagExact => $this->businessDayCalendar->shiftBusinessDays(
                $curveDate,
                $businessDayLag,
                $rateCalendarCode,
            ),
        };

        $indexer = $parameter->indexer_enum;
        $rate = $lookupDate !== null
            ? $this->realizedRates->realizedRateForDate($indexer, $lookupDate)
            : null;

        return new PuIndexRateRequirement(
            curveDate: $curveDate,
            lookupMode: $lookupMode,
            calendarCode: $calendarCode,
            businessDayLag: $businessDayLag,
            isBusinessDay: $isBusinessDay,
            lookupDate: $lookupDate,
            rate: $rate,
            rateCalendarCode: $rateCalendarCode,
            latestRealizedRateDate: $this->realizedRates->latestRealizedRateDate($indexer),
        );
    }

    private function latestBusinessDayOnOrBefore(CarbonImmutable $date, string $calendarCode): CarbonImmutable
    {
        return $this->businessDayCalendar->isBusinessDay($date, $calendarCode)
            ? $date
            : $this->businessDayCalendar->shiftBusinessDays($date, -1, $calendarCode);
    }

    /** @return list<CarbonImmutable> */
    public function firstCouponPreIntegralizationAccrualDates(EmissionPuParameter $parameter): array
    {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()) {
            return [];
        }

        $businessDays = (int) $parameter->first_coupon_pre_integralization_business_days;

        if ($businessDays <= 0) {
            throw new InvalidArgumentException(
                'O prêmio pré-integralização do primeiro cupom exige quantidade de Dias Úteis maior que zero.',
            );
        }

        if ($parameter->curve_start_date === null) {
            throw new InvalidArgumentException(
                'O prêmio pré-integralização do primeiro cupom exige a data inicial/integralização da curva.',
            );
        }

        $startDate = CarbonImmutable::instance($parameter->curve_start_date);
        $calendarCode = (string) $parameter->calendar_code;
        $dates = [];

        for ($offset = $businessDays; $offset >= 1; $offset--) {
            $dates[] = $this->businessDayCalendar->shiftBusinessDays(
                $startDate,
                -$offset,
                $calendarCode,
            );
        }

        return $dates;
    }

    /**
     * As datas de acúmulo do prêmio continuam no calendário CONTRATUAL -- são
     * Dias Úteis do instrumento. Só a taxa observada em cada uma delas segue o
     * calendário de observação.
     *
     * @return list<PuIndexRateRequirement>
     */
    public function firstCouponPreIntegralizationRateRequirements(
        EmissionPuParameter $parameter,
        ?string $indexRateCalendarCode = null,
    ): array {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()
            || ! (bool) $parameter->first_coupon_pre_integralization_apply_index_factor) {
            return [];
        }

        return array_map(
            fn (CarbonImmutable $accrualDate): PuIndexRateRequirement => $this->resolve(
                $parameter,
                $accrualDate,
                $indexRateCalendarCode,
            ),
            $this->firstCouponPreIntegralizationAccrualDates($parameter),
        );
    }

    public function firstCouponPreIntegralizationFinancialCalendarStartDate(
        EmissionPuParameter $parameter,
        ?string $indexRateCalendarCode = null,
    ): ?CarbonImmutable {
        $dates = $this->firstCouponPreIntegralizationAccrualDates($parameter);

        foreach ($this->firstCouponPreIntegralizationRateRequirements($parameter, $indexRateCalendarCode) as $requirement) {
            if ($requirement->lookupDate !== null) {
                $dates[] = $requirement->lookupDate;
            }
        }

        if ($dates === []) {
            return null;
        }

        usort(
            $dates,
            fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp(),
        );

        return $dates[0];
    }

    /**
     * Calendário efetivo de OBSERVAÇÃO do índice -- em que dias ele é divulgado.
     * Ordem, da mais explícita para o padrão:
     *
     *  1. a hipótese informada (simulação);
     *  2. o calendário de divulgação gravado na configuração
     *     (`index_rate_calendar_code`), preservado como foi escolhido;
     *  3. para o CDI, o calendário do contrato quando ele É de divulgação
     *     (bancário ANBIMA ou o legado com os dados da ANBIMA) -- o caso da curva
     *     oficial, que segue o calendário de mercado desde 28/09/2026, e o
     *     resultado fica byte a byte igual;
     *  4. para o CDI, o calendário canônico de divulgação
     *     ({@see BusinessCalendarRegistry::CDI_PUBLICATION_CALENDAR}). Um contrato
     *     em feriados nacionais legais, sessões da B3 ou calendário próprio não
     *     sabe que não há CDI no Carnaval nem em Corpus Christi.
     *
     * Fora do CDI o padrão continua sendo o contratual. Código vazio é ausência,
     * nunca calendário inválido. Se o calendário escolhido não cobrir a data, o
     * {@see BusinessDayCalendar} recusa decidir e a falta aparece como erro de
     * configuração -- nunca como dia útil presumido de segunda a sexta.
     */
    public function observationCalendarCode(
        EmissionPuParameter $parameter,
        ?string $indexRateCalendarCode = null,
    ): string {
        foreach ([$indexRateCalendarCode, $parameter->index_rate_calendar_code] as $candidate) {
            $code = $candidate !== null ? trim((string) $candidate) : '';

            if ($code !== '') {
                return $code;
            }
        }

        $contractualCalendarCode = trim((string) $parameter->calendar_code);

        if ($parameter->indexer_enum !== PuIndexer::Cdi
            || ($contractualCalendarCode !== '' && BusinessCalendarRegistry::isCdiPublicationCalendar($contractualCalendarCode))) {
            return $contractualCalendarCode;
        }

        return BusinessCalendarRegistry::CDI_PUBLICATION_CALENDAR;
    }

    /**
     * Calendário que decide o Dia Útil de accrual. Nulo — e toda a produção — devolve o contratual,
     * de modo que a curva permanece byte a byte idêntica enquanto ninguém informar a hipótese.
     */
    private function accrualCalendarCode(
        EmissionPuParameter $parameter,
        ?string $accrualCalendarCode,
    ): string {
        $override = $accrualCalendarCode !== null ? trim($accrualCalendarCode) : '';

        return $override !== '' ? $override : (string) $parameter->calendar_code;
    }

    /**
     * A observação exigida ainda não podia existir: a curva realizada termina
     * antes desta data e a parte seguinte entra quando o índice for divulgado.
     *
     * Exige que a curva já tenha resolvido alguma taxa antes: sem nenhum dia
     * realizado, não há curva realizada a gerar e a falta continua bloqueando.
     * Vale para todos os modos -- nenhum deles completa o futuro com a última
     * taxa conhecida.
     */
    public function isAwaitingPublication(
        PuIndexRateRequirement $requirement,
        bool $hasPreviouslyResolvedRate,
    ): bool {
        return $hasPreviouslyResolvedRate && $requirement->isAwaitingPublication();
    }
}
