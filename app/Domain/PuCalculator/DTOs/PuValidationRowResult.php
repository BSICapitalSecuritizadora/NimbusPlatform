<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class PuValidationRowResult
{
    /**
     * @param  array<string, string>  $differences
     */
    public function __construct(
        public CarbonImmutable $date,
        public bool $approved,
        public array $differences,
    ) {}
}
