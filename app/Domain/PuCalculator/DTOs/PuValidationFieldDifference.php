<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuValidationSeverity;

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
        public ?string $comparisonMode = null,
        public ?string $actualRaw = null,
        public ?string $expectedRaw = null,
        public ?string $actualDisplay = null,
        public ?string $expectedDisplay = null,
        public ?string $spreadsheetCell = null,
        public ?string $spreadsheetFormula = null,
        public ?int $displayScale = null,
        public ?string $numberFormatCode = null,
        public ?PuValidationSeverity $severity = null,
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

        if ($this->comparisonMode !== null) {
            $parts[] = sprintf('modo=%s', $this->comparisonMode);
        }

        if ($this->severity !== null) {
            $parts[] = sprintf('severidade=%s', $this->severity->value);
        }

        if ($this->displayScale !== null) {
            $parts[] = sprintf('casas=%d', $this->displayScale);
        }

        if ($this->spreadsheetCell !== null) {
            $parts[] = sprintf('celula=%s', $this->spreadsheetCell);
        }

        if ($this->spreadsheetFormula !== null) {
            $parts[] = sprintf('formula=%s', $this->spreadsheetFormula);
        }

        return implode(' | ', $parts);
    }
}
