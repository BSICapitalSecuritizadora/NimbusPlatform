<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use Carbon\CarbonImmutable;

/**
 * O que uma data da curva exige do índice.
 *
 * `lookupDate` é a data de observação que a regra contratual pede -- resolvida
 * pelo calendário, nunca pelos dados. `rate` é a observação REALIZADA exatamente
 * nessa data, ou nula: projeção nunca aparece aqui. Quando falta, a requisição
 * diz por quê:
 *
 *  - aguardando publicação: a data pedida está além da última observação
 *    realizada do indexador -- é futuro, e a curva realizada termina antes dela;
 *  - observação ausente: a data pedida está dentro do histórico já divulgado e
 *    mesmo assim não há taxa -- é buraco, e bloqueia.
 */
final readonly class PuIndexRateRequirement
{
    public function __construct(
        public CarbonImmutable $curveDate,
        public PuIndexRateLookupMode $lookupMode,
        public string $calendarCode,
        public int $businessDayLag,
        public bool $isBusinessDay,
        public ?CarbonImmutable $lookupDate,
        public ?IndexRateData $rate,
        public ?string $rateCalendarCode = null,
        public ?CarbonImmutable $latestRealizedRateDate = null,
    ) {}

    /**
     * A data exige observação do índice.
     *
     * No D-1 calendário a exigência não depende de a data da curva ser útil: o
     * CDI do dia anterior incide sobre ela sempre que o dia anterior foi de
     * divulgação -- e só então. O resolvedor deixa `lookupDate` nulo quando o dia
     * anterior não teve divulgação (fim de semana, feriado bancário), e aí o
     * Fator DI do dia é 1 sem que nada falte.
     */
    public function isRequiredForCalculation(): bool
    {
        return match ($this->lookupMode) {
            PuIndexRateLookupMode::PreviousCalendarDayExact => $this->lookupDate !== null,
            PuIndexRateLookupMode::PreviousAvailableBusinessDay,
            PuIndexRateLookupMode::BusinessDayLagExact => $this->isBusinessDay,
        };
    }

    public function shouldApplyRate(): bool
    {
        return $this->isRequiredForCalculation() && $this->rate !== null;
    }

    /**
     * A data exige taxa e a observação realizada exigida não existe: a linha não
     * pode ser calculada como realizada.
     */
    public function lacksRealizedObservation(): bool
    {
        return $this->isRequiredForCalculation() && $this->rate === null;
    }

    /**
     * Falta a observação, mas ela ainda não podia existir: a data pedida está
     * além da última observação realizada do indexador.
     */
    public function isAwaitingPublication(): bool
    {
        if (! $this->lacksRealizedObservation()) {
            return false;
        }

        $requiredRateDate = $this->requiredRateDate();

        if ($requiredRateDate === null) {
            return false;
        }

        return $this->latestRealizedRateDate === null
            || $requiredRateDate->gt($this->latestRealizedRateDate);
    }

    /**
     * Falta a observação dentro do histórico já divulgado: buraco nos dados.
     */
    public function isMissingHistoricalObservation(): bool
    {
        return $this->lacksRealizedObservation() && ! $this->isAwaitingPublication();
    }

    /**
     * A linha desta data não pode ser calculada como realizada: a curva realizada
     * termina antes dela.
     *
     * Vale igual para todos os modos: a observação exigida ainda não divulgada
     * (futuro) ou ausente dentro do histórico (buraco) encerra a curva -- nenhum
     * modo completa uma data com a última taxa conhecida, nem com Fator DI 1. No
     * D-1 calendário, o dia anterior sem divulgação não é falta: nada é exigido
     * ({@see self::isRequiredForCalculation()}).
     */
    public function endsRealizedCurve(): bool
    {
        return $this->lacksRealizedObservation();
    }

    public function requiredRateDate(): ?CarbonImmutable
    {
        return $this->rate?->date ?? $this->lookupDate;
    }

    public function ruleDescription(): string
    {
        return match ($this->lookupMode) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => sprintf(
                'taxa do último dia útil de divulgação (calendário %s) igual ou anterior à data da curva, consultada somente quando a data da curva é útil',
                $this->rateCalendarCode ?? $this->calendarCode,
            ),
            PuIndexRateLookupMode::PreviousCalendarDayExact => sprintf(
                'D-1 calendário: taxa do dia anterior quando ele é dia útil de divulgação (calendário %s); sem divulgação no dia anterior, nenhuma taxa é exigida e o Fator DI do dia é 1',
                $this->rateCalendarCode ?? $this->calendarCode,
            ),
            PuIndexRateLookupMode::BusinessDayLagExact => sprintf(
                'lag de %d dia(s) útil(eis) no calendário %s, com contagem exclusiva da data da curva',
                $this->businessDayLag,
                $this->rateCalendarCode ?? $this->calendarCode,
            ),
        };
    }

    public function missingRateMessage(): string
    {
        $requiredRateDate = $this->requiredRateDate()?->toDateString() ?? 'não resolvida';

        return sprintf(
            "Taxa DI ausente para %s.\n\nData da curva: %s\nModo: %s\nRegra: %s\nData requerida: %s",
            $requiredRateDate,
            $this->curveDate->toDateString(),
            $this->lookupMode->name,
            $this->ruleDescription(),
            $requiredRateDate,
        );
    }
}
