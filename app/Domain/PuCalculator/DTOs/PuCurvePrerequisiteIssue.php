<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCurvePrerequisiteIssue
{
    public function __construct(
        public string $key,
        public string $message,
        public bool $blocking = true,
    ) {}

    public static function blocking(string $key, string $message): self
    {
        return new self($key, $message, true);
    }

    public static function warning(string $key, string $message): self
    {
        return new self($key, $message, false);
    }
}
