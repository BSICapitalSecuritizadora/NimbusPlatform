<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class MeasurementPauseInterval implements Arrayable
{
    /** @param list<string> $missingReasons */
    public function __construct(
        public ?int $sourcePauseId,
        public MeasurementHistorySourceType $sourceType,
        public int $measurementId,
        public int $stage,
        public ?CarbonImmutable $pausedAt,
        public ?CarbonImmutable $resumedAt,
        public ?int $pausedById,
        public ?int $resumedById,
        public ?string $reason,
        public MeasurementHistoryCompleteness $completeness,
        public array $missingReasons,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_pause_id' => $this->sourcePauseId,
            'source_type' => $this->sourceType->value,
            'measurement_id' => $this->measurementId,
            'stage' => $this->stage,
            'paused_at' => $this->pausedAt?->toIso8601String(),
            'resumed_at' => $this->resumedAt?->toIso8601String(),
            'paused_by_id' => $this->pausedById,
            'resumed_by_id' => $this->resumedById,
            'reason' => $this->reason,
            'completeness' => $this->completeness->value,
            'missing_reasons' => $this->missingReasons,
        ];
    }
}
