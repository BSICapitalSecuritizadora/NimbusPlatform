<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\CalendarSourceObservationKind;
use Carbon\CarbonImmutable;

/**
 * Uma linha publicada pela FEBRABAN, já datada e já CLASSIFICADA quanto ao efeito de mercado.
 *
 * A classificação vem da tabela de origem, não do nome da data: a própria fonte separa o que é dia não
 * útil de mercado do que é apenas expediente especial de agência.
 */
final class FebrabanHolidayObservation
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $name,
        public readonly CalendarSourceObservationKind $kind,
        public readonly ?string $weekdayLabel = null,
    ) {}

    public function dateKey(): string
    {
        return $this->date->toDateString();
    }

    public function affectsBusinessDayDecision(): bool
    {
        return $this->kind->affectsBusinessDayDecision();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->dateKey(),
            'name' => $this->name,
            'kind' => $this->kind->value,
            'weekday_label' => $this->weekdayLabel,
        ];
    }
}
