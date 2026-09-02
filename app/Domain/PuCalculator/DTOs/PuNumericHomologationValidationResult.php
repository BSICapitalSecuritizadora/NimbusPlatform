<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuNumericHomologationValidationResult
{
    /**
     * @param  list<array<string, mixed>>  $blockingFailures
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<array<string, mixed>>  $information
     * @param  list<array<string, mixed>>  $rateSamples
     */
    public function __construct(
        public string $status,
        public int $validationCount,
        public array $blockingFailures,
        public array $warnings,
        public array $information,
        public array $rateSamples,
    ) {}

    public function passed(): bool
    {
        return $this->status === 'passed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'validation_count' => $this->validationCount,
            'blocking_failures' => $this->blockingFailures,
            'warnings' => $this->warnings,
            'information' => $this->information,
            'rate_samples' => $this->rateSamples,
        ];
    }
}
