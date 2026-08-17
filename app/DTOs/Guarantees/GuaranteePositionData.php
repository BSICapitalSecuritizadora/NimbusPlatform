<?php

declare(strict_types=1);

namespace App\DTOs\Guarantees;

use App\DTOs\BaseDTO;
use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Models\Guarantee;

/**
 * Posição apurada de uma garantia numa competência: quanto vale, quanto é
 * elegível, quanto é exigido e se ela está enquadrada.
 */
readonly class GuaranteePositionData extends BaseDTO
{
    public function __construct(
        public Guarantee $guarantee,
        public string $referenceMonth,
        public ResolvedGuaranteeValue $value,
        public ResolvedGuaranteeRequirement $requirement,
        public ?float $eligibleValue,
        public float $eligibilityFactor,
        public ?float $coverageRatio,
        public ?float $surplusDeficit,
        public GuaranteeCoverageStatus $coverageStatus,
        public GuaranteeLegalStatus $legalStatus,
        public bool $contributesToCoverage,
    ) {}

    public function currentValue(): ?float
    {
        return $this->value->amount;
    }

    public function requiredValue(): ?float
    {
        return $this->requirement->amount;
    }

    public function isPending(): bool
    {
        return $this->value->isPending();
    }

    public function hasDeficit(): bool
    {
        return $this->surplusDeficit !== null && $this->surplusDeficit < 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshotAttributes(): array
    {
        return [
            'current_value' => $this->value->amount,
            'eligible_value' => $this->eligibleValue,
            'required_value' => $this->requirement->amount,
            'eligibility_factor' => $this->eligibilityFactor,
            'coverage_ratio' => $this->coverageRatio,
            'surplus_deficit' => $this->surplusDeficit,
            'value_source' => $this->value->source,
            'value_status' => $this->value->status,
            'coverage_status' => $this->coverageStatus,
            'legal_status' => $this->legalStatus,
            'metadata' => [
                'value' => $this->value->metadata,
                'requirement' => $this->requirement->metadata,
                'requirement_description' => $this->requirement->description,
                'contributes_to_coverage' => $this->contributesToCoverage,
            ],
        ];
    }
}
