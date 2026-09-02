<?php

namespace App\DTOs\Measurements;

final readonly class MeasurementHistoricalCoverage
{
    public function __construct(
        public int $complete,
        public int $partial,
        public int $insufficient,
    ) {}

    public function total(): int
    {
        return $this->complete + $this->partial + $this->insufficient;
    }
}
