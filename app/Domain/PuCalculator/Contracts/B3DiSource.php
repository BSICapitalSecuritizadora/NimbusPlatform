<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Contracts;

use App\Domain\PuCalculator\DTOs\CdiSourceDataset;
use Carbon\CarbonImmutable;

interface B3DiSource
{
    public function fetch(CarbonImmutable $from, CarbonImmutable $to): CdiSourceDataset;
}
