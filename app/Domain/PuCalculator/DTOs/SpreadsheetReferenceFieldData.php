<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class SpreadsheetReferenceFieldData
{
    public function __construct(
        public string $field,
        public string $cellReference,
        public ?string $rawValue,
        public ?string $displayValue,
        public ?string $formula = null,
        public ?int $displayScale = null,
        public ?string $numberFormatCode = null,
    ) {}
}
