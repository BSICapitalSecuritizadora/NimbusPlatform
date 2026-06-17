<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuValidationFieldDifference
{
    public function __construct(
        public string $field,
        public string $label,
        public ?string $actual,
        public ?string $expected,
        public ?string $absoluteDifference,
        public ?string $percentageDifference,
        public string $relatedRule,
        public ?string $possibleCause,
    ) {}

    public function summary(): string
    {
        $parts = [
            $this->label,
            sprintf('calculado=%s', $this->actual ?? 'null'),
            sprintf('esperado=%s', $this->expected ?? 'null'),
        ];

        if ($this->absoluteDifference !== null) {
            $parts[] = sprintf('dif=%s', $this->absoluteDifference);
        }

        if ($this->percentageDifference !== null) {
            $parts[] = sprintf('dif%%=%s', $this->percentageDifference);
        }

        if ($this->possibleCause !== null) {
            $parts[] = sprintf('causa=%s', $this->possibleCause);
        }

        return implode(' | ', $parts);
    }
}
