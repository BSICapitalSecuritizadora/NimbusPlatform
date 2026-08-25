<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class CdiSourceRecord
{
    public function __construct(
        public string $source,
        public ?CarbonImmutable $referenceDate,
        public string $sourceReference,
        public string $rawValue,
        public ?string $normalizedValue,
        public ?string $issue = null,
        public ?string $payloadSha256 = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'reference_date' => $this->referenceDate?->toDateString(),
            'source_reference' => $this->sourceReference,
            'raw_value' => $this->rawValue,
            'normalized_value' => $this->normalizedValue,
            'issue' => $this->issue,
            'payload_sha256' => $this->payloadSha256,
        ];
    }
}
