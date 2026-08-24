<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use Carbon\CarbonImmutable;

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
    ) {}

    public function isRequiredForCalculation(): bool
    {
        return match ($this->lookupMode) {
            PuIndexRateLookupMode::PreviousCalendarDayExact => true,
            PuIndexRateLookupMode::PreviousAvailableBusinessDay,
            PuIndexRateLookupMode::BusinessDayLagExact => $this->isBusinessDay,
        };
    }

    public function shouldApplyRate(): bool
    {
        return $this->isRequiredForCalculation() && $this->rate !== null;
    }

    public function requiredRateDate(): ?CarbonImmutable
    {
        return $this->rate?->date ?? $this->lookupDate;
    }

    public function ruleDescription(): string
    {
        return match ($this->lookupMode) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => 'último snapshot disponível em data igual ou anterior à data da curva, consultado somente quando a data da curva é útil',
            PuIndexRateLookupMode::PreviousCalendarDayExact => 'D-1 calendário',
            PuIndexRateLookupMode::BusinessDayLagExact => sprintf(
                'lag de %d dia(s) útil(eis) no calendário %s, com contagem exclusiva da data da curva',
                $this->businessDayLag,
                $this->calendarCode,
            ),
        };
    }

    public function missingRateMessage(): string
    {
        $requiredRateDate = $this->requiredRateDate()?->toDateString() ?? 'não resolvida';
        $headline = $this->lookupMode === PuIndexRateLookupMode::PreviousAvailableBusinessDay
            ? sprintf('Nenhuma Taxa DI disponível em data igual ou anterior a %s.', $requiredRateDate)
            : sprintf('Taxa DI ausente para %s.', $requiredRateDate);

        return sprintf(
            "%s\n\nData da curva: %s\nModo: %s\nRegra: %s\nData requerida: %s",
            $headline,
            $this->curveDate->toDateString(),
            $this->lookupMode->name,
            $this->ruleDescription(),
            $requiredRateDate,
        );
    }
}
