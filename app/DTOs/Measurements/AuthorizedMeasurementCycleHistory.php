<?php

namespace App\DTOs\Measurements;

use App\Models\Measurement;

final readonly class AuthorizedMeasurementCycleHistory
{
    public function __construct(
        public Measurement $measurement,
        public MeasurementCycleHistory $history,
    ) {}
}
