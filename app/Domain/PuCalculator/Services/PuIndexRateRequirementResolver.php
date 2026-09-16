<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PuIndexRateRequirementResolver
{
    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
        private readonly IndexRateProvider $indexRateProvider,
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
     *    divulgada, e portanto para onde o lag de `BusinessDayLagExact` aponta.
     *
     * Eles coincidem em toda a produção: `$indexRateCalendarCode` nulo mantém o
     * comportamento atual, byte a byte. Separá-los só faz sentido quando o
     * calendário do contrato e o do divulgador do índice divergem -- é o caso
     * de um feriado bancário que não é feriado nacional legal, em que não há
     * publicação de taxa num dia que o contrato conta como útil.
     */
    public function resolve(
        EmissionPuParameter $parameter,
        CarbonImmutable $curveDate,
        ?string $indexRateCalendarCode = null,
        ?string $accrualCalendarCode = null,
    ): PuIndexRateRequirement {
        $lookupMode = $parameter->index_rate_lookup_mode_enum;
        $calendarCode = $this->accrualCalendarCode($parameter, $accrualCalendarCode);
        $rateCalendarCode = $this->rateCalendarCode($parameter, $indexRateCalendarCode);
        $businessDayLag = (int) $parameter->index_rate_lag_business_days;
        // Acúmulo de juros segue o calendário de accrual: contratual por padrão.
        $isBusinessDay = $this->businessDayCalendar->isBusinessDay($curveDate, $calendarCode);

        $lookupDate = match ($lookupMode) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $isBusinessDay ? $curveDate : null,
            PuIndexRateLookupMode::PreviousCalendarDayExact => $curveDate->subDay(),
            // Deslocamento até a data de DIVULGAÇÃO: calendário de observação.
            PuIndexRateLookupMode::BusinessDayLagExact => $this->businessDayCalendar->shiftBusinessDays(
                $curveDate,
                $businessDayLag,
                $rateCalendarCode,
            ),
        };

        $rate = match ($lookupMode) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $lookupDate !== null
                ? $this->indexRateProvider->rateForDate($parameter->indexer_enum, $lookupDate)
                : null,
            PuIndexRateLookupMode::PreviousCalendarDayExact,
            PuIndexRateLookupMode::BusinessDayLagExact => $this->indexRateProvider->exactRateForDate(
                $parameter->indexer_enum,
                $lookupDate,
            ),
        };

        return new PuIndexRateRequirement(
            curveDate: $curveDate,
            lookupMode: $lookupMode,
            calendarCode: $calendarCode,
            businessDayLag: $businessDayLag,
            isBusinessDay: $isBusinessDay,
            lookupDate: $lookupDate,
            rate: $rate,
        );
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
     * Calendário efetivo de observação do índice: o informado, quando houver, e
     * o contratual em qualquer outro caso. Um código vazio é tratado como
     * ausência de override -- nunca como calendário inválido.
     */
    private function rateCalendarCode(
        EmissionPuParameter $parameter,
        ?string $indexRateCalendarCode,
    ): string {
        $override = $indexRateCalendarCode !== null ? trim($indexRateCalendarCode) : '';

        return $override !== '' ? $override : (string) $parameter->calendar_code;
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

    public function isAwaitingPublication(
        EmissionPuParameter $parameter,
        PuIndexRateRequirement $requirement,
        bool $hasPreviouslyResolvedRate,
    ): bool {
        $requiredRateDate = $requirement->requiredRateDate();

        if (
            ! $hasPreviouslyResolvedRate
            || $requirement->lookupMode !== PuIndexRateLookupMode::BusinessDayLagExact
            || $requiredRateDate === null
        ) {
            return false;
        }

        $lastAvailableRateDate = IndexRate::query()
            ->forIndexer($parameter->indexer_enum)
            ->max('rate_date');

        return $lastAvailableRateDate === null
            || $requiredRateDate->gt(CarbonImmutable::parse((string) $lastAvailableRateDate));
    }
}
