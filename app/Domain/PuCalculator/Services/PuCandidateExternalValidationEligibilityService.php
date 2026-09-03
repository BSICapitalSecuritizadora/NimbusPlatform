<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\EmissionPuCurveVersion;

final class PuCandidateExternalValidationEligibilityService
{
    public const ACTION_NOT_READY = 'external_validation_not_ready';

    public const ACTION_INTEGRITY_FAILURE = 'external_validation_integrity_failure';

    public const ACTION_READY = 'ready_for_external_validation';

    public function __construct(private readonly PuPersistedCurveChecksumService $checksums) {}

    /** @return array{ready:bool,action:string,reason:string} */
    public function inspect(?EmissionPuCurveVersion $candidate): array
    {
        if (! $candidate instanceof EmissionPuCurveVersion) {
            return $this->failure(self::ACTION_NOT_READY, 'No internally approved candidate exists.');
        }

        if (! $candidate->isCandidate()
            || $candidate->status !== PuCurveStatus::Validated
            || $candidate->internal_validation_status !== PuCurveInternalValidationStatus::Passed
            || $candidate->review_status !== PuCurveReviewStatus::Approved
            || $candidate->external_validation_status !== PuCurveExternalValidationStatus::Pending) {
            return $this->failure(
                self::ACTION_NOT_READY,
                'External validation requires a pending candidate with passed internal validation and approved internal review.',
            );
        }

        if ($candidate->curve_checksum === null
            || $candidate->input_fingerprint === null
            || $candidate->dailyCurves()->count() !== $candidate->rows_count
            || ! hash_equals($candidate->curve_checksum, $this->checksums->checksum($candidate))) {
            return $this->failure(
                self::ACTION_INTEGRITY_FAILURE,
                'The persisted candidate row count, checksum or input fingerprint is inconsistent.',
            );
        }

        return [
            'ready' => true,
            'action' => self::ACTION_READY,
            'reason' => 'The internally approved candidate is intact and ready for external validation.',
        ];
    }

    /** @return array{ready:false,action:string,reason:string} */
    private function failure(string $action, string $reason): array
    {
        return ['ready' => false, 'action' => $action, 'reason' => $reason];
    }
}
