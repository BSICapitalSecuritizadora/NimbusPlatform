<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

final readonly class BcbSgsRawPayload
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $url,
        public string $body,
        public string $sha256,
        public CarbonImmutable $capturedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'url' => $this->url,
            'sha256' => $this->sha256,
            'captured_at' => $this->capturedAt->toIso8601String(),
            'body_base64' => base64_encode($this->body),
        ];
    }
}
