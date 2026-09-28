<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

/**
 * Um PU lido para uma data, com a fonte de onde ele veio.
 */
final readonly class PuReading
{
    public const SOURCE_OFFICIAL_CURVE = 'official_curve';

    public const SOURCE_PU_HISTORY = 'pu_history';

    public function __construct(
        public CarbonImmutable $date,
        public string $unitValue,
        public string $source,
        public ?string $calculationVersion = null,
    ) {}

    public function fromOfficialCurve(): bool
    {
        return $this->source === self::SOURCE_OFFICIAL_CURVE;
    }
}
