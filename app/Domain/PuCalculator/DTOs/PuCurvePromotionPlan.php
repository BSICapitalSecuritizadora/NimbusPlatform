<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * Plano read-only de promoção operacional. Não escreve nada e não recalcula a
 * curva: descreve o que a promoção faria com o artefato já persistido.
 */
final readonly class PuCurvePromotionPlan
{
    public const ACTION_NOT_READY = 'promotion_not_ready';

    public const ACTION_INTEGRITY_FAILURE = 'promotion_integrity_failure';

    public const ACTION_READY_TO_REQUEST = 'ready_to_request';

    public const ACTION_ALREADY_REQUESTED = 'promotion_request_already_exists';

    public const ACTION_ALREADY_APPROVED = 'promotion_already_approved';

    public const ACTION_ALREADY_REJECTED = 'promotion_already_rejected';

    public const ACTION_ALREADY_EXECUTED = 'promotion_already_executed';

    public const ACTION_BASELINE_CONFLICT = 'promotion_baseline_conflict';

    public function __construct(
        public string $action,
        public string $reason,
        public int $emissionId,
        public ?int $candidateVersionId = null,
        public ?string $calculationVersion = null,
        public ?string $candidateChecksum = null,
        public ?string $inputFingerprint = null,
        public ?int $rowsCount = null,
        public ?int $externalValidationId = null,
        public ?int $benchmarkId = null,
        public ?string $benchmarkChecksum = null,
        public ?string $comparisonChecksum = null,
        public ?int $currentOperationalVersionId = null,
        public ?string $currentOperationalCalculationVersion = null,
        public ?int $promotionId = null,
        public ?string $promotionStatus = null,
    ) {}

    public function readyToRequest(): bool
    {
        return $this->action === self::ACTION_READY_TO_REQUEST;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'emission_id' => $this->emissionId,
            'candidate_version_id' => $this->candidateVersionId,
            'calculation_version' => $this->calculationVersion,
            'candidate_checksum' => $this->candidateChecksum,
            'input_fingerprint' => $this->inputFingerprint,
            'rows_count' => $this->rowsCount,
            'external_validation_id' => $this->externalValidationId,
            'benchmark_id' => $this->benchmarkId,
            'benchmark_dataset_sha256' => $this->benchmarkChecksum,
            'comparison_sha256' => $this->comparisonChecksum,
            'current_operational_version_id' => $this->currentOperationalVersionId,
            'current_operational_calculation_version' => $this->currentOperationalCalculationVersion,
            'promotion_id' => $this->promotionId,
            'promotion_status' => $this->promotionStatus,
        ];
    }
}
