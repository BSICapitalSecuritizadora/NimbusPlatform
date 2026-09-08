<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;

/**
 * Elegibilidade de promoção operacional: recomputa, sem escrever nada, todo o
 * dossiê persistido da candidate e do seu dossiê externo.
 *
 * `external_validation_status = validated` no header da versão nunca basta: o
 * artefato externo correspondente precisa existir e continuar íntegro. As três
 * canonicalizações já homologadas são reutilizadas — não existe uma segunda
 * forma de calcular checksum nesta fase.
 */
final class PuCurvePromotionEligibilityService
{
    public const ACTION_NOT_READY = 'promotion_not_ready';

    public const ACTION_INTEGRITY_FAILURE = 'promotion_integrity_failure';

    public const ACTION_READY = 'promotion_candidate_eligible';

    /**
     * Estados de lifecycle admissíveis para uma candidate promovível. `Validated`
     * é o estado em que a candidate nasce e permanece durante toda a governança
     * (2B.5.16/2B.5.17); `Homologated` continua sendo lifecycle de conteúdo e não
     * sinônimo de operacional, então não é exigido nem proibido aqui — apenas
     * aceito quando já presente.
     *
     * @var list<PuCurveStatus>
     */
    private const ADMISSIBLE_LIFECYCLE_STATES = [
        PuCurveStatus::Validated,
        PuCurveStatus::Homologated,
    ];

    public function __construct(
        private readonly PuPersistedCurveChecksumService $checksums,
        private readonly PuExternalBenchmarkIntegrityService $benchmarkIntegrity,
        private readonly PuExternalComparisonIntegrityService $comparisonIntegrity,
    ) {}

    /**
     * @return array{
     *     ready:bool,
     *     action:string,
     *     reason:string,
     *     candidate:?EmissionPuCurveVersion,
     *     externalValidation:?EmissionPuExternalValidation,
     *     benchmarkId:?int,
     *     candidateChecksum:?string,
     *     inputFingerprint:?string,
     *     rowsCount:?int,
     *     benchmarkChecksum:?string,
     *     comparisonChecksum:?string,
     * }
     */
    public function inspect(?EmissionPuCurveVersion $candidate): array
    {
        if (! $candidate instanceof EmissionPuCurveVersion) {
            return $this->failure(
                self::ACTION_NOT_READY,
                'No externally validated candidate exists.',
            );
        }

        if ($candidate->curve_role !== PuCurveRole::Candidate) {
            return $this->failure(
                self::ACTION_NOT_READY,
                'Only a candidate can be promoted; an operational version never re-enters the promotion flow.',
                $candidate,
            );
        }

        if (! in_array($candidate->status, self::ADMISSIBLE_LIFECYCLE_STATES, true)
            || $candidate->internal_validation_status !== PuCurveInternalValidationStatus::Passed
            || $candidate->review_status !== PuCurveReviewStatus::Approved
            || $candidate->external_validation_status !== PuCurveExternalValidationStatus::Validated) {
            return $this->failure(
                self::ACTION_NOT_READY,
                'Promotion requires a candidate with an admissible lifecycle state, passed internal validation, approved internal review and validated external validation.',
                $candidate,
            );
        }

        if ($candidate->curve_checksum === null
            || $candidate->input_fingerprint === null
            || ($candidate->rows_count ?? 0) < 1) {
            return $this->failure(
                self::ACTION_NOT_READY,
                'Promotion requires a candidate with checksum, input fingerprint and a non-empty row count.',
                $candidate,
            );
        }

        if ($candidate->dailyCurves()->count() !== $candidate->rows_count
            || ! hash_equals($candidate->curve_checksum, $this->checksums->checksum($candidate))) {
            return $this->failure(
                self::ACTION_INTEGRITY_FAILURE,
                'The persisted candidate row count or curve checksum no longer matches the candidate dossier.',
                $candidate,
            );
        }

        $validation = $candidate->externalValidations()
            ->where('status', PuCurveExternalValidationStatus::Validated->value)
            ->latest('id')
            ->first();

        if (! $validation instanceof EmissionPuExternalValidation) {
            return $this->failure(
                self::ACTION_NOT_READY,
                'No validated external validation dossier is linked to this candidate.',
                $candidate,
            );
        }

        if ($validation->reviewed_by === null) {
            return $this->failure(
                self::ACTION_INTEGRITY_FAILURE,
                'The external validation dossier has no independent reviewer recorded.',
                $candidate,
                $validation,
            );
        }

        if (! hash_equals($validation->candidate_checksum, $candidate->curve_checksum)) {
            return $this->failure(
                self::ACTION_INTEGRITY_FAILURE,
                'The candidate checksum used at external validation time no longer matches the candidate dossier.',
                $candidate,
                $validation,
            );
        }

        $benchmark = $validation->relationLoaded('benchmark')
            ? $validation->benchmark
            : $validation->benchmark()->first();

        if (! $benchmark instanceof EmissionPuExternalBenchmark) {
            return $this->failure(
                self::ACTION_INTEGRITY_FAILURE,
                'The benchmark linked to the external validation no longer exists.',
                $candidate,
                $validation,
            );
        }

        $benchmarkIntegrity = $this->benchmarkIntegrity->inspect($benchmark);

        if (! $benchmarkIntegrity['valid']
            || ! hash_equals($validation->benchmark_dataset_sha256, $benchmark->dataset_sha256)) {
            return $this->failure(
                self::ACTION_INTEGRITY_FAILURE,
                'The external benchmark dataset checksum no longer matches the validated dossier.',
                $candidate,
                $validation,
                $benchmarkIntegrity['checksum'],
            );
        }

        $comparisonIntegrity = $this->comparisonIntegrity->inspect($validation);

        if (! $comparisonIntegrity['valid']) {
            return $this->failure(
                self::ACTION_INTEGRITY_FAILURE,
                $comparisonIntegrity['reason'],
                $candidate,
                $validation,
                $benchmark->dataset_sha256,
                $comparisonIntegrity['checksum'],
            );
        }

        return [
            'ready' => true,
            'action' => self::ACTION_READY,
            'reason' => 'The externally validated candidate and its full persisted dossier are intact.',
            'candidate' => $candidate,
            'externalValidation' => $validation,
            'benchmarkId' => $benchmark->id,
            'candidateChecksum' => $candidate->curve_checksum,
            'inputFingerprint' => $candidate->input_fingerprint,
            'rowsCount' => $candidate->rows_count,
            'benchmarkChecksum' => $benchmark->dataset_sha256,
            'comparisonChecksum' => $comparisonIntegrity['checksum'],
        ];
    }

    /**
     * @return array{
     *     ready:false,
     *     action:string,
     *     reason:string,
     *     candidate:?EmissionPuCurveVersion,
     *     externalValidation:?EmissionPuExternalValidation,
     *     benchmarkId:?int,
     *     candidateChecksum:?string,
     *     inputFingerprint:?string,
     *     rowsCount:?int,
     *     benchmarkChecksum:?string,
     *     comparisonChecksum:?string,
     * }
     */
    private function failure(
        string $action,
        string $reason,
        ?EmissionPuCurveVersion $candidate = null,
        ?EmissionPuExternalValidation $validation = null,
        ?string $benchmarkChecksum = null,
        ?string $comparisonChecksum = null,
    ): array {
        return [
            'ready' => false,
            'action' => $action,
            'reason' => $reason,
            'candidate' => $candidate,
            'externalValidation' => $validation,
            'benchmarkId' => $validation?->benchmark_id,
            'candidateChecksum' => $candidate?->curve_checksum,
            'inputFingerprint' => $candidate?->input_fingerprint,
            'rowsCount' => $candidate?->rows_count,
            'benchmarkChecksum' => $benchmarkChecksum,
            'comparisonChecksum' => $comparisonChecksum,
        ];
    }
}
