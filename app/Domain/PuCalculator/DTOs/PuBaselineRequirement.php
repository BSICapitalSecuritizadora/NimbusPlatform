<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuBaselineRequirementCategory;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementSeverity;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;

final readonly class PuBaselineRequirement
{
    /**
     * @param  array<string, mixed>|null  $evidence
     * @param  list<string>  $blocks
     */
    public function __construct(
        public string $code,
        public string $name,
        public PuBaselineRequirementCategory $category,
        public PuBaselineRequirementStatus $status,
        public PuBaselineRequirementSeverity $severity,
        public string $reason,
        public ?array $evidence = null,
        public mixed $expected = null,
        public mixed $found = null,
        public array $blocks = [],
    ) {}

    public function isSatisfied(): bool
    {
        return $this->status === PuBaselineRequirementStatus::Satisfied;
    }

    public function symbol(): string
    {
        return $this->status->symbol();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'severity' => $this->severity->value,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
            'expected' => $this->expected,
            'found' => $this->found,
            'blocks' => $this->blocks,
            'symbol' => $this->symbol(),
        ];
    }
}
