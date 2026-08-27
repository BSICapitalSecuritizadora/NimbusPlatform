<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use Carbon\CarbonImmutable;

final readonly class PuBaselineCandidate
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, PuBaselineRequirement>  $contractRequirements
     * @param  array<string, mixed>  $contractualSchedule
     * @param  list<string>  $calendarWindowLimitations
     */
    public function __construct(
        public array $configuration,
        public array $fields,
        public array $contractRequirements,
        public array $contractualSchedule,
        public ?PuIndexer $indexer,
        public ?PuIndexRateLookupMode $lookupMode,
        public ?CarbonImmutable $calendarFromDate,
        public ?CarbonImmutable $curveEndDate,
        public ?CarbonImmutable $calendarToDate = null,
        public array $calendarWindowLimitations = [],
    ) {}

    /**
     * Fim da janela de calendário exigida.
     *
     * Cai no vencimento quando o deslocamento para o próximo Dia Útil não pôde
     * ser medido — nunca em uma data estimada.
     */
    public function calendarToDate(): ?CarbonImmutable
    {
        return $this->calendarToDate ?? $this->curveEndDate;
    }

    /** @return list<string> */
    public function pendingFields(): array
    {
        return collect($this->configuration)
            ->filter(fn (mixed $value): bool => $value === 'PENDING')
            ->keys()
            ->all();
    }
}
