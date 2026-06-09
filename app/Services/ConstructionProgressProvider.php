<?php

namespace App\Services;

use App\DTOs\ConstructionProgressData;
use App\Models\Construction;
use App\Models\Emission;
use Carbon\CarbonInterface;

interface ConstructionProgressProvider
{
    /**
     * Returns the construction progress (Evolução da Obra) for a given emission
     * and reference month, optionally narrowed to a specific development.
     *
     * Implementations return null when there is no progress data for the period.
     */
    public function forEmission(
        Emission $emission,
        CarbonInterface $referenceMonth,
        ?Construction $construction = null,
    ): ?ConstructionProgressData;
}
