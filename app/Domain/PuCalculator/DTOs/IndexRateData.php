<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use Carbon\CarbonImmutable;

final readonly class IndexRateData
{
    public function __construct(
        public CarbonImmutable $date,
        public string $value,
        public bool $isProjected = false,
        public ?string $source = null,
        public ?string $projectionSource = null,
        public ?CarbonImmutable $projectionReferenceDate = null,
        public ?int $projectionSeriesId = null,
        public ?string $projectionSeriesStatus = null,
    ) {}

    public function reportedDate(): ?CarbonImmutable
    {
        return $this->isProjected ? null : $this->date;
    }

    public function reportedValue(): ?string
    {
        return $this->isProjected ? null : $this->value;
    }

    /**
     * Projeção utilizável operacionalmente: índice publicado (não projetado) ou projetado vinculado a
     * uma série APROVADA. Projeção sem série vinculada não é considerada aprovada.
     */
    public function isApprovedForOperationalUse(): bool
    {
        if (! $this->isProjected) {
            return true;
        }

        return $this->projectionSeriesStatus === IndexProjectionSeriesStatus::Approved->value;
    }
}
