<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuNumericHomologationComparisonResult
{
    /**
     * @param  array<string, mixed>  $provenance
     * @param  list<array<string, mixed>>  $differences
     */
    public function __construct(
        public string $status,
        public string $reason,
        public array $provenance,
        public array $differences,
        public ?string $maximumAbsoluteDifference = null,
        public ?string $maximumRelativeDifference = null,
        public ?string $tolerancePolicy = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'provenance' => $this->provenance,
            'differences' => $this->differences,
            'maximum_absolute_difference' => $this->maximumAbsoluteDifference,
            'maximum_relative_difference' => $this->maximumRelativeDifference,
            'tolerance_policy' => $this->tolerancePolicy,
        ];
    }
}
