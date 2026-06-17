<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class IndexRateData
{
    public function __construct(
        public CarbonImmutable $date,
        public string $value,
        public bool $isProjected = false,
    ) {}

    public function reportedDate(): ?CarbonImmutable
    {
        return $this->isProjected ? null : $this->date;
    }

    public function reportedValue(): ?string
    {
        return $this->isProjected ? null : $this->value;
    }
}
