<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Contracts;

use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use Carbon\CarbonImmutable;

interface IndexRateProvider
{
    public function rateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData;
}
