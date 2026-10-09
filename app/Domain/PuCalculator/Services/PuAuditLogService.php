<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\DTOs\PuCurveChangeAssessment;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuCurvePrerequisiteCheckResult;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationComparisonResult;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationValidationResult;
use App\Domain\PuCalculator\DTOs\PuObligationRefreshOutcome;
use App\Domain\PuCalculator\DTOs\PuObligationRefreshResult;
use App\Domain\PuCalculator\DTOs\PuValidationFieldDifference;
use App\Domain\PuCalculator\DTOs\PuValidationReport;
use App\Domain\PuCalculator\DTOs\PuValidationRowResult;
use App\Domain\PuCalculator\Enums\PuCandidateReviewDecision;
use App\Domain\PuCalculator\Enums\PuCurvePromotionDecision;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationDecision;
use App\Enums\BusinessArea;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuParameter;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\IndexRateCorrection;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class PuAuditLogService
{
    public const LOG_NAME = 'pu-calculation';

    /**
     * Versão do MOTOR CONTRATUAL de PU. Muda sempre que o resultado numérico muda.
     *
     * `phase1-cdi-v2` fecha duas alterações reais de algoritmo sobre a `v1`:
     *  1. o produtório do Fator DI passou a ser truncado progressivamente em 16 casas;
     *  2. VNb, J, AMi e SDa passaram a ser quantizados em 8 casas SEM arredondamento,
     *     dentro da engine -- e não mais só na apresentação.
     *
     * Fingerprints e homologações gravados sob `v1` ficam STALE de propósito: uma troca
     * de algoritmo tem de ser detectável pela auditoria, nunca reetiquetada.
     */
    public const ENGINE_VERSION = 'phase1-cdi-v2';

    private const MAX_STORED_DIFFERENCES = 150;

    public function logGenerationCompleted(
        Emission $emission,
        PuCurveGenerationResult $result,
        ?int $requestedByUserId,
        PuCurvePrerequisiteCheckResult $prerequisiteCheck,
        bool $syncLegacyProjections,
        bool $reprocessed = false,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'calculation_version' => $result->calculationVersion,
                'rows_count' => count($result->rows),
                'sync_legacy_projections' => $syncLegacyProjections,
                'reprocessed' => $reprocessed,
                'parameter_snapshot' => $this->parameterSnapshot($emission),
                'prerequisites' => $prerequisiteCheck->toArray(),
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event($reprocessed ? 'reprocessed' : 'generated')->log($reprocessed ? 'pu_curve_reprocessed' : 'pu_curve_generated');
    }

    public function logGenerationFailed(
        Emission $emission,
        string $errorMessage,
        ?int $requestedByUserId,
        ?PuCurvePrerequisiteCheckResult $prerequisiteCheck = null,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'error_message' => $errorMessage,
                'parameter_snapshot' => $this->parameterSnapshot($emission),
                'prerequisites' => $prerequisiteCheck?->toArray(),
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('failed')->log('pu_curve_generation_failed');
    }

    public function logValidation(
        Emission $emission,
        PuValidationReport $report,
        string $spreadsheetPath,
        ?int $requestedByUserId,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'spreadsheet_path' => $spreadsheetPath,
                'spreadsheet_name' => basename($spreadsheetPath),
                'sheet_name' => $report->sheetName,
                'calculation_version' => $report->calculationVersion,
                'mode' => $report->mode->value,
                'status' => $report->status->value,
                'total_rows_compared' => $report->totalRowsCompared,
                'total_divergences' => $report->totalDivergences,
                'total_field_divergences' => $report->totalFieldDivergences,
                'first_divergence_date' => $report->firstDivergenceDate?->toDateString(),
                'largest_pu_difference' => $report->largestPuDifference,
                'largest_total_value_difference' => $report->largestTotalValueDifference,
                'largest_payment_difference' => $report->largestPaymentDifference,
                'largest_differences_by_field' => $this->largestDifferencesByField($report),
                'divergence_count_by_field' => $report->divergenceCountByField,
                'divergence_count_by_cause' => $report->divergenceCountByCause,
                'severity_count_by_level' => $this->severityCountByLevel($report),
                'sample_differences' => $this->sampleDifferences($emission, $report),
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('validated')->log('pu_curve_validated');
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logParametersUpdated(Emission $emission, array $before, array $after, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'before' => $before,
                'after' => $after,
                'changed_keys' => $this->changedKeys($before, $after),
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('parameters_updated')->log('pu_parameters_updated');
    }

    /**
     * @param  array<string, mixed>  $persistedFields
     * @param  list<array<string, mixed>>  $nonPersistedProvenFields
     * @param  array<string, mixed>  $provenance
     */
    public function logCandidateConfigurationCreated(
        Emission $emission,
        EmissionPuParameter $parameter,
        User $actor,
        string $readinessStatus,
        array $persistedFields,
        array $nonPersistedProvenFields,
        string $candidateFingerprint,
        array $provenance,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($emission)
            ->causedBy($actor)
            ->withProperties([
                'emission_id' => $emission->id,
                'parameter_id' => $parameter->id,
                'actor_id' => $actor->id,
                'candidate_source' => $provenance['candidate_source'] ?? null,
                'readiness_status' => $readinessStatus,
                'persisted_fields' => $persistedFields,
                'non_persisted_proven_fields' => $nonPersistedProvenFields,
                'candidate_fingerprint' => $candidateFingerprint,
                'provenance' => $provenance,
                'persisted_at' => now()->toIso8601String(),
            ])
            ->event('candidate_configuration_created')
            ->log('pu_candidate_configuration_created');
    }

    public function logCandidateCurvePersisted(
        Emission $emission,
        EmissionPuCurveVersion $version,
        User $actor,
        PuNumericHomologationValidationResult $validation,
        ?PuNumericHomologationComparisonResult $externalComparison,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($emission)
            ->causedBy($actor)
            ->withProperties([
                'emission_id' => $emission->id,
                'candidate_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'curve_role' => $version->curve_role->value,
                'as_of' => $version->candidate_as_of?->toDateString(),
                'input_fingerprint' => $version->input_fingerprint,
                'curve_checksum' => $version->curve_checksum,
                'maker_id' => $actor->id,
                'rows_count' => $version->rows_count,
                'internal_validation' => $validation->toArray(),
                'external_comparison' => $externalComparison?->toArray(),
                'external_validation_status' => $version->external_validation_status?->value,
                'persisted_at' => now()->toIso8601String(),
            ])
            ->event('candidate_curve_persisted')
            ->log('pu_candidate_curve_persisted');
    }

    /**
     * Registra a decisão final do maker-checker sobre uma candidate persistida.
     */
    public function logCandidateCurveReview(
        EmissionPuCurveVersion $version,
        User $reviewer,
        PuCandidateReviewDecision $decision,
        ?string $reason,
    ): void {
        $description = match ($decision) {
            PuCandidateReviewDecision::Approve => 'pu_candidate_curve_approved',
            PuCandidateReviewDecision::Reject => 'pu_candidate_curve_rejected',
        };

        activity(self::LOG_NAME)
            ->performedOn($version->emission)
            ->causedBy($reviewer)
            ->withProperties([
                'emission_id' => $version->emission_id,
                'candidate_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'curve_role' => $version->curve_role->value,
                'maker_id' => $version->generated_by,
                'reviewer_id' => $reviewer->id,
                'decision' => $decision->value,
                'reason' => $reason,
                'reviewed_at' => $version->reviewed_at?->toIso8601String(),
            ])
            ->event($description)
            ->log($description);
    }

    public function logExternalBenchmarkImported(
        EmissionPuCurveVersion $candidate,
        EmissionPuExternalBenchmark $benchmark,
        User $actor,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($candidate->emission)
            ->causedBy($actor)
            ->withProperties([
                'emission_id' => $candidate->emission_id,
                'candidate_version_id' => $candidate->id,
                'benchmark_id' => $benchmark->id,
                'source_type' => $benchmark->source_type,
                'source_name' => $benchmark->source_name,
                'source_document_id' => $benchmark->source_document_id,
                'source_evidence_id' => $benchmark->source_evidence_id,
                'reference_as_of' => $benchmark->reference_as_of?->toDateString(),
                'input_file_name' => $benchmark->input_file_name,
                'file_sha256' => $benchmark->file_sha256,
                'dataset_sha256' => $benchmark->dataset_sha256,
                'row_count' => $benchmark->row_count,
                'from_date' => $benchmark->from_date?->toDateString(),
                'to_date' => $benchmark->to_date?->toDateString(),
                'actor_id' => $actor->id,
                'imported_at' => $benchmark->created_at?->toIso8601String(),
            ])
            ->event('external_benchmark_imported')
            ->log('pu_external_benchmark_imported');
    }

    public function logExternalComparisonCreated(
        EmissionPuExternalValidation $validation,
        User $actor,
    ): void {
        $candidate = $validation->candidate()->with('emission')->firstOrFail();

        activity(self::LOG_NAME)
            ->performedOn($candidate->emission)
            ->causedBy($actor)
            ->withProperties([
                'emission_id' => $candidate->emission_id,
                'candidate_version_id' => $candidate->id,
                'candidate_checksum' => $validation->candidate_checksum,
                'benchmark_id' => $validation->benchmark_id,
                'benchmark_dataset_sha256' => $validation->benchmark_dataset_sha256,
                'external_validation_id' => $validation->id,
                'comparison_algorithm_version' => $validation->comparison_algorithm_version,
                'comparison_sha256' => $validation->comparison_sha256,
                'coverage_status' => $validation->coverage_status->value,
                'compared_rows' => $validation->compared_rows,
                'candidate_dates_without_reference' => $validation->candidate_dates_without_reference,
                'reference_dates_without_candidate' => $validation->reference_dates_without_candidate,
                'actor_id' => $actor->id,
                'generated_at' => $validation->created_at?->toIso8601String(),
            ])
            ->event('external_comparison_created')
            ->log('pu_external_comparison_created');
    }

    public function logCandidateExternalValidationDecision(
        EmissionPuExternalValidation $validation,
        User $reviewer,
        PuExternalValidationDecision $decision,
    ): void {
        $candidate = $validation->candidate()->with('emission')->firstOrFail();
        $description = $decision === PuExternalValidationDecision::Validate
            ? 'pu_candidate_external_validation_validated'
            : 'pu_candidate_external_validation_rejected';

        activity(self::LOG_NAME)
            ->performedOn($candidate->emission)
            ->causedBy($reviewer)
            ->withProperties([
                'emission_id' => $candidate->emission_id,
                'candidate_version_id' => $candidate->id,
                'benchmark_id' => $validation->benchmark_id,
                'external_validation_id' => $validation->id,
                'candidate_checksum' => $validation->candidate_checksum,
                'benchmark_dataset_sha256' => $validation->benchmark_dataset_sha256,
                'comparison_sha256' => $validation->comparison_sha256,
                'reviewer_id' => $reviewer->id,
                'decision' => $decision->value,
                'reason' => $validation->review_reason,
                'reviewed_at' => $validation->reviewed_at?->toIso8601String(),
            ])
            ->event($description)
            ->log($description);
    }

    /**
     * Auditoria complementar do pedido de promoção. A decisão de domínio vive no
     * dossiê `EmissionPuCurvePromotion`; aqui fica a trilha para a emissão.
     *
     * Nada de curva bruta, linha de benchmark, caminho de arquivo ou segredo
     * entra nas propriedades: só identidades e checksums.
     */
    public function logCurvePromotionRequested(EmissionPuCurvePromotion $promotion, User $requester): void
    {
        activity(self::LOG_NAME)
            ->performedOn($promotion->emission()->firstOrFail())
            ->causedBy($requester)
            ->withProperties([
                'emission_id' => $promotion->emission_id,
                'promotion_id' => $promotion->id,
                'candidate_version_id' => $promotion->candidate_curve_version_id,
                'calculation_version' => $promotion->calculation_version,
                'candidate_checksum' => $promotion->candidate_checksum,
                'input_fingerprint' => $promotion->input_fingerprint,
                'rows_count' => $promotion->rows_count,
                'previous_operational_version_id' => $promotion->previous_operational_curve_version_id,
                'external_validation_id' => $promotion->external_validation_id,
                'benchmark_dataset_sha256' => $promotion->benchmark_dataset_sha256,
                'comparison_sha256' => $promotion->comparison_sha256,
                'requester_id' => $requester->id,
                'requested_at' => $promotion->requested_at?->toIso8601String(),
            ])
            ->event('pu_curve_promotion_requested')
            ->log('pu_curve_promotion_requested');
    }

    public function logCurvePromotionReviewed(
        EmissionPuCurvePromotion $promotion,
        User $reviewer,
        PuCurvePromotionDecision $decision,
    ): void {
        $description = $decision === PuCurvePromotionDecision::Approve
            ? 'pu_curve_promotion_approved'
            : 'pu_curve_promotion_rejected';

        activity(self::LOG_NAME)
            ->performedOn($promotion->emission()->firstOrFail())
            ->causedBy($reviewer)
            ->withProperties([
                'emission_id' => $promotion->emission_id,
                'promotion_id' => $promotion->id,
                'candidate_version_id' => $promotion->candidate_curve_version_id,
                'calculation_version' => $promotion->calculation_version,
                'candidate_checksum' => $promotion->candidate_checksum,
                'external_validation_id' => $promotion->external_validation_id,
                'requester_id' => $promotion->requested_by,
                'reviewer_id' => $reviewer->id,
                'decision' => $decision->value,
                'reason' => $promotion->review_reason,
                'reviewed_at' => $promotion->reviewed_at?->toIso8601String(),
            ])
            ->event($description)
            ->log($description);
    }

    public function logCurvePromotionExecuted(EmissionPuCurvePromotion $promotion, User $executor): void
    {
        activity(self::LOG_NAME)
            ->performedOn($promotion->emission()->firstOrFail())
            ->causedBy($executor)
            ->withProperties([
                'emission_id' => $promotion->emission_id,
                'promotion_id' => $promotion->id,
                'previous_operational_version_id' => $promotion->previous_operational_curve_version_id,
                'new_operational_version_id' => $promotion->candidate_curve_version_id,
                'calculation_version' => $promotion->calculation_version,
                'candidate_checksum' => $promotion->candidate_checksum,
                'input_fingerprint' => $promotion->input_fingerprint,
                'rows_count' => $promotion->rows_count,
                'external_validation_id' => $promotion->external_validation_id,
                'benchmark_dataset_sha256' => $promotion->benchmark_dataset_sha256,
                'comparison_sha256' => $promotion->comparison_sha256,
                'requester_id' => $promotion->requested_by,
                'reviewer_id' => $promotion->reviewed_by,
                'executor_id' => $executor->id,
                'promoted_at' => $promotion->promoted_at?->toIso8601String(),
            ])
            ->event('pu_curve_promoted_operational')
            ->log('pu_curve_promoted_operational');
    }

    public function logEventChange(Emission $emission, string $action, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'action' => $action,
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('event_changed')->log('pu_event_changed');
    }

    public function logExport(Emission $emission, ?string $calculationVersion, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'calculation_version' => $calculationVersion,
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('exported')->log('pu_curve_exported');
    }

    /**
     * Na auto-homologação quem homologa é o próprio maker, autorizado como
     * responsável pela área Curva de PU; o registro guarda isso e a justificativa.
     */
    public function logHomologation(
        Emission $emission,
        ?string $calculationVersion,
        ?int $requestedByUserId,
        bool $selfHomologated = false,
        ?string $justification = null,
        ?int $curveVersionId = null,
        ?string $previousStatus = null,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'calculation_version' => $calculationVersion,
                'curve_version_id' => $curveVersionId,
                'previous_status' => $previousStatus,
                'new_status' => PuCurveStatus::Homologated->value,
                'self_homologated' => $selfHomologated,
                'justification' => $justification,
                'authorized_by_area' => $selfHomologated ? BusinessArea::PuCurve->value : null,
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('homologated')->log('pu_curve_homologated');
    }

    public function logInvalidation(
        Emission $emission,
        ?string $calculationVersion,
        ?int $requestedByUserId,
        ?int $curveVersionId = null,
        ?string $previousStatus = null,
        ?string $reason = null,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'calculation_version' => $calculationVersion,
                'curve_version_id' => $curveVersionId,
                'previous_status' => $previousStatus,
                'new_status' => PuCurveStatus::Obsolete->value,
                'obsolete_reason' => 'invalidated',
                'reason' => $reason,
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('invalidated')->log('pu_curve_invalidated');
    }

    public function logIndexSync(IndexRateSyncResult $result, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
            ] + $result->toArray());

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('index_synced')->log('pu_index_synced');
    }

    /**
     * Correção de observação histórica: valor e origem anteriores e novos, motivo,
     * ator e as versões de curva que usavam a observação. O registro durável é o
     * livro `index_rate_corrections`; esta é a trilha na linha do tempo do PU.
     */
    public function logIndexRateCorrected(IndexRateCorrection $correction): void
    {
        $logger = activity(self::LOG_NAME)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'index_rate_correction_id' => $correction->id,
                'index_rate_id' => $correction->index_rate_id,
                'indexer' => $correction->indexer,
                'rate_date' => $correction->rate_date?->toDateString(),
                'origin' => $correction->origin,
                'previous_rate_value' => (string) $correction->previous_rate_value,
                'new_rate_value' => (string) $correction->new_rate_value,
                'previous_source' => $correction->previous_source,
                'new_source' => $correction->new_source,
                'reason' => $correction->reason,
                'affected_curve_versions' => $correction->affected_curve_versions ?? [],
            ]);

        if ($correction->indexRate !== null) {
            $logger->performedOn($correction->indexRate);
        }

        if (($causer = $this->causer($correction->corrected_by)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('index_rate_corrected')->log('pu_index_rate_corrected');
    }

    /**
     * Importação de observações por planilha: quem importou, de que origem, e o
     * desfecho de cada data (criada, idêntica, em conflito, recusada).
     *
     * @param  array<string, mixed>  $summary
     */
    public function logIndexRatesImported(array $summary, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
            ] + $summary);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('index_rates_imported')->log('pu_index_rates_imported');
    }

    /**
     * @param  array{from:?string,to:?string}  $requestedWindow
     * @param  list<string>  $requiredDates
     * @param  list<string>  $insertedDates
     * @param  list<string>  $alreadyExistingDates
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<string>  $payloadChecksums
     */
    public function logNumericSnapshotPreparation(
        Emission $emission,
        EmissionPuParameter $parameter,
        User $actor,
        string $source,
        string $series,
        array $requestedWindow,
        array $requiredDates,
        array $insertedDates,
        array $alreadyExistingDates,
        array $conflicts,
        array $payloadChecksums,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($emission)
            ->causedBy($actor)
            ->withProperties([
                'emission_id' => $emission->id,
                'parameter_id' => $parameter->id,
                'actor_id' => $actor->id,
                'source' => $source,
                'series' => $series,
                'requested_window' => $requestedWindow,
                'required_dates' => $requiredDates,
                'inserted_dates' => $insertedDates,
                'already_existing_dates' => $alreadyExistingDates,
                'conflicts' => $conflicts,
                'payload_checksums' => $payloadChecksums,
                'prepared_at' => now()->toIso8601String(),
            ])
            ->event('numeric_snapshots_prepared')
            ->log('pu_numeric_snapshots_prepared');
    }

    /**
     * @param  list<array<string, mixed>>  $requiredEvents
     * @param  list<int>  $insertedEventIds
     * @param  list<int>  $alreadyExistingEventIds
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, mixed>  $baselineSources
     */
    public function logNumericEventPreparation(
        Emission $emission,
        EmissionPuParameter $parameter,
        User $actor,
        array $requiredEvents,
        array $insertedEventIds,
        array $alreadyExistingEventIds,
        array $conflicts,
        array $baselineSources,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($emission)
            ->causedBy($actor)
            ->withProperties([
                'emission_id' => $emission->id,
                'parameter_id' => $parameter->id,
                'actor_id' => $actor->id,
                'required_events' => $requiredEvents,
                'inserted_event_ids' => $insertedEventIds,
                'already_existing_event_ids' => $alreadyExistingEventIds,
                'conflicts' => $conflicts,
                'baseline_sources' => $baselineSources,
                'prepared_at' => now()->toIso8601String(),
            ])
            ->event('numeric_events_prepared')
            ->log('pu_numeric_events_prepared');
    }

    /**
     * @param  list<int>  $insertedEventIds
     * @param  list<array<string, mixed>>  $missingInCalculatedPeriod
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<int>  $justifiedEventIds  eventos já cadastrados que ganharam o motivo da data efetiva
     */
    public function logContractualScheduleGenerated(
        Emission $emission,
        User $actor,
        array $insertedEventIds,
        array $missingInCalculatedPeriod,
        array $conflicts,
        ?string $lastCalculatedDate,
        array $justifiedEventIds = [],
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($emission)
            ->causedBy($actor)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'action' => 'contractual_schedule_generated',
                'inserted_event_ids' => $insertedEventIds,
                'justified_event_ids' => $justifiedEventIds,
                'missing_in_calculated_period' => $missingInCalculatedPeriod,
                'conflicts' => $conflicts,
                'last_calculated_date' => $lastCalculatedDate,
            ])
            ->event('event_changed')
            ->log('pu_event_changed');
    }

    /**
     * Atualização das obrigações pela curva oficial (Fase 5): contagens, datas do
     * cronograma informado sem obrigação e obrigações liquidadas cujo esperado mudou.
     */
    public function logObligationsRefreshed(Emission $emission, PuObligationRefreshResult $result, string $trigger, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'action' => 'obligations_refreshed',
                'trigger' => $trigger,
                'calculation_version' => $result->calculationVersion,
                ...$result->counts,
                'unmatched_informed_dates' => $result->unmatchedInformedDates,
                'settled_obligations_with_changed_calculation' => $result->settledWithChangedCalculation,
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('obligations_refreshed')->log('pu_obligations_refreshed');
    }

    /**
     * O valor esperado oficial de uma obrigação JÁ liquidada mudou (nova versão
     * homologada, invalidação, cronograma). A liquidação continua a mesma.
     */
    public function logSettledObligationCalculationChanged(
        EmissionPuObligation $obligation,
        EmissionPuSettlement $settlement,
        ?EmissionPuObligationCalculation $before,
        ?EmissionPuObligationCalculation $after,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($obligation)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'emission_id' => $obligation->emission_id,
                'obligation' => $obligation->key(),
                'settlement_id' => $settlement->id,
                'settled_amount' => (string) $settlement->amount,
                'previous_calculation_id' => $before?->id,
                'previous_calculation_version' => $before?->calculation_version,
                'previous_expected_total' => $before?->total_amount !== null ? (string) $before->total_amount : null,
                'current_calculation_id' => $after?->id,
                'current_calculation_version' => $after?->calculation_version,
                'current_expected_total' => $after?->total_amount !== null ? (string) $after->total_amount : null,
            ])
            ->event('settled_obligation_calculation_changed')
            ->log('pu_settled_obligation_calculation_changed');
    }

    public function logSettlementRecorded(EmissionPuSettlement $settlement): void
    {
        $this->settlementActivity($settlement->obligation, $settlement->recorded_by, 'settlement_recorded', [
            ...$this->settlementFacts($settlement),
        ]);
    }

    public function logSettlementCorrected(EmissionPuSettlement $previous, EmissionPuSettlement $correction): void
    {
        $this->settlementActivity($correction->obligation, $correction->recorded_by, 'settlement_corrected', [
            'previous' => $this->settlementFacts($previous),
            'correction' => $this->settlementFacts($correction),
            'reason' => $correction->reason,
        ]);
    }

    public function logSettlementReversed(EmissionPuSettlement $previous, EmissionPuSettlement $reversal): void
    {
        $this->settlementActivity($reversal->obligation, $reversal->recorded_by, 'settlement_reversed', [
            'reversed' => $this->settlementFacts($previous),
            'reversal_id' => $reversal->id,
            'reason' => $reversal->reason,
        ]);
    }

    public function logSettlementConflictDetected(EmissionPuSettlementConflict $conflict): void
    {
        $this->settlementActivity($conflict->obligation, $conflict->detected_by, 'settlement_conflict_detected', [
            'conflict_id' => $conflict->id,
            'kind' => $conflict->kind->value,
            'existing_settlement_id' => $conflict->existing_settlement_id,
            'source' => $conflict->source->value,
            'external_reference' => $conflict->external_reference,
            'incoming' => $conflict->incoming_payload,
            'detected_via' => $conflict->detected_via,
        ]);
    }

    public function logSettlementConflictResolved(EmissionPuSettlementConflict $conflict): void
    {
        $this->settlementActivity($conflict->obligation, $conflict->resolved_by, 'settlement_conflict_resolved', [
            'conflict_id' => $conflict->id,
            'status' => $conflict->status->value,
            'resolution_settlement_id' => $conflict->resolution_settlement_id,
            'reason' => $conflict->resolution_reason,
        ]);
    }

    /**
     * Fato de liquidação recusado antes de gravar (obrigação inexistente, dados
     * inválidos, liquidação não vigente): fica a tentativa, com o que chegou.
     *
     * @param  array<string, mixed>  $payload
     */
    public function logSettlementRejected(?Emission $emission, string $reason, array $payload, string $via, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'reason' => $reason,
                'payload' => $payload,
                'via' => $via,
            ]);

        if ($emission instanceof Emission) {
            $logger->performedOn($emission);
        }

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('settlement_rejected')->log('pu_settlement_rejected');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function settlementActivity(?EmissionPuObligation $obligation, ?int $causerId, string $event, array $properties): void
    {
        $logger = activity(self::LOG_NAME)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'emission_id' => $obligation?->emission_id,
                'obligation' => $obligation?->key(),
                ...$properties,
            ]);

        if ($obligation instanceof EmissionPuObligation) {
            $logger->performedOn($obligation);
        }

        if (($causer = $this->causer($causerId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event($event)->log('pu_'.$event);
    }

    /**
     * @return array<string, mixed>
     */
    private function settlementFacts(EmissionPuSettlement $settlement): array
    {
        return [
            'settlement_id' => $settlement->id,
            'entry_type' => $settlement->entry_type->value,
            'status' => $settlement->status->value,
            'settlement_date' => $settlement->settlement_date?->toDateString(),
            'amount' => $settlement->amount !== null ? (string) $settlement->amount : null,
            'currency' => $settlement->currency,
            'components' => $settlement->components,
            'source' => $settlement->source->value,
            'external_reference' => $settlement->external_reference,
            'expected_calculation_id' => $settlement->expected_calculation_id,
            'recorded_via' => $settlement->recorded_via,
            'predecessor_id' => $settlement->predecessor_id,
        ];
    }

    public function logCurveExtended(
        Emission $emission,
        EmissionPuCurveVersion $version,
        int $appendedRows,
        string $fromDate,
        string $toDate,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'curve_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'appended_rows' => $appendedRows,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'extended_rows_count' => $version->extended_rows_count,
            ])
            ->event('curve_extended')
            ->log('pu_curve_extended');
    }

    public function logCurveExtensionDiverged(
        EmissionPuCurveVersion $version,
        ?string $firstDivergentDate,
        string $reason,
        bool $governed,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($version->emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'curve_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'first_divergent_date' => $firstDivergentDate,
                'reason' => $reason,
                'governed' => $governed,
            ])
            ->event('curve_extension_diverged')
            ->log('pu_curve_extension_diverged');
    }

    /**
     * A extensão percebeu que os insumos contratuais vivos já não são os aprovados
     * na versão: o que mudou, desde quando, e o que isso exige (versão nova antes
     * da data afetada, ou reprocessamento do passado).
     */
    public function logContractualChangeDetected(EmissionPuCurveVersion $version, PuCurveChangeAssessment $assessment): void
    {
        activity(self::LOG_NAME)
            ->performedOn($version->emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'curve_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'version_status' => $version->status->value,
                ...$assessment->toArray(),
            ])
            ->event('contractual_change_detected')
            ->log('pu_curve_contractual_change_detected');
    }

    /**
     * Mudança de um insumo contratual da curva (evento, integralização ou
     * parâmetros): quem, o que, valor anterior e novo, desde quando vale, o motivo
     * e documento quando informados, e o impacto em cada versão viva.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<array<string, mixed>>  $affectedVersions
     */
    public function logContractualInputChanged(
        Emission $emission,
        string $input,
        ?int $inputId,
        string $action,
        array $before,
        array $after,
        ?string $effectiveDate,
        ?string $reason,
        ?string $documentReference,
        array $affectedVersions,
        ?int $requestedByUserId,
    ): void {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'input' => $input,
                'input_id' => $inputId,
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'effective_date' => $effectiveDate,
                'reason' => $reason,
                'document_reference' => $documentReference,
                'affected_versions' => $affectedVersions,
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('contractual_input_changed')->log('pu_contractual_input_changed');
    }

    /**
     * A extensão diária não rodou sobre a versão: pré-requisito bloqueado ou erro
     * de cálculo. Fica separada da divergência -- aqui nada mudou no passado.
     */
    public function logCurveExtensionFailed(
        EmissionPuCurveVersion $version,
        string $action,
        string $reason,
        string $purpose,
        ?string $category = null,
        int $consecutiveFailures = 1,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($version->emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'curve_version_id' => $version->id,
                'calculation_version' => $version->calculation_version,
                'purpose' => $purpose,
                'action' => $action,
                'reason' => $reason,
                'failure_category' => $category,
                'consecutive_failures' => $consecutiveFailures,
            ])
            ->event('curve_extension_failed')
            ->log('pu_curve_extension_failed');
    }

    public function logHomologationReportDownloaded(Emission $emission, ?string $calculationVersion, ?int $requestedByUserId): void
    {
        $logger = activity(self::LOG_NAME)
            ->performedOn($emission)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'calculation_version' => $calculationVersion,
            ]);

        if (($causer = $this->causer($requestedByUserId)) !== null) {
            $logger->causedBy($causer);
        }

        $logger->event('homologation_report_downloaded')->log('pu_homologation_report_downloaded');
    }

    /**
     * Recomposição das obrigações retomada pela varredura, pela execução manual
     * ou depois de falhas (Fase 6). Diagnóstico: o fato financeiro já está nas
     * tabelas da Fase 5; aqui fica como a recuperação aconteceu.
     */
    public function logObligationRefreshRecovered(int $emissionId, PuObligationRefreshOutcome $outcome): void
    {
        $this->obligationRefreshActivity($emissionId, null, 'obligation_refresh_recovered', $outcome->toArray());
    }

    /**
     * Tentativa de recompor as obrigações que falhou, com a categoria e o que
     * acontece a seguir (nova tentativa, esgotada, bloqueada). Diagnóstico.
     */
    public function logObligationRefreshFailed(int $emissionId, PuObligationRefreshOutcome $outcome): void
    {
        $this->obligationRefreshActivity($emissionId, null, 'obligation_refresh_failed', $outcome->toArray());
    }

    /**
     * Retomada autorizada de uma recomposição esgotada ou bloqueada: quem pediu,
     * por quê, quais pedidos e como terminou. Evidência protegida (ação humana
     * sobre o estado financeiro derivado).
     *
     * @param  list<int>  $requestIds
     * @param  list<array<string, mixed>>  $previous
     */
    public function logObligationRefreshRetried(int $emissionId, User $actor, string $reason, array $requestIds, array $previous, PuObligationRefreshOutcome $outcome): void
    {
        $this->obligationRefreshActivity($emissionId, $actor, 'obligation_refresh_retried', [
            'reason' => $reason,
            'request_ids' => $requestIds,
            'previous' => $previous,
            'outcome' => $outcome->toArray(),
        ]);
    }

    /**
     * Incidente operacional reconhecido. Reconhecer não corrige nada financeiro:
     * só registra quem assumiu e o que disse. Evidência protegida.
     */
    public function logIncidentAcknowledged(PuOperationalIncident $incident, User $actor): void
    {
        $logger = activity(self::LOG_NAME)
            ->causedBy($actor)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'incident_id' => $incident->id,
                'incident_key' => $incident->incident_key,
                'type' => $incident->type->value,
                'severity' => $incident->severity->value,
                'emission_id' => $incident->emission_id,
                'obligation_id' => $incident->obligation_id,
                'settlement_conflict_id' => $incident->settlement_conflict_id,
                'note' => $incident->acknowledgement_note,
            ]);

        if ($incident->emission_id !== null && ($emission = Emission::query()->find($incident->emission_id)) instanceof Emission) {
            $logger->performedOn($emission);
        }

        $logger->event('operational_incident_acknowledged')->log('pu_operational_incident_acknowledged');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function obligationRefreshActivity(int $emissionId, ?User $actor, string $event, array $properties): void
    {
        $logger = activity(self::LOG_NAME)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'emission_id' => $emissionId,
                ...$properties,
            ]);

        if (($emission = Emission::query()->find($emissionId)) instanceof Emission) {
            $logger->performedOn($emission);
        }

        if ($actor instanceof User) {
            $logger->causedBy($actor);
        }

        $logger->event($event)->log('pu_'.$event);
    }

    /**
     * Atividades da calculadora de PU de uma emissao, mais recentes primeiro.
     *
     * @return Collection<int, Activity>
     */
    public function activitiesFor(Emission $emission, int $limit = 50): Collection
    {
        return Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('subject_type', $emission::class)
            ->where('subject_id', $emission->id)
            ->with('causer')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function describeEvent(string $description): string
    {
        return match ($description) {
            'pu_curve_generated' => 'Curva gerada',
            'pu_curve_reprocessed' => 'Curva reprocessada',
            'pu_curve_generation_failed' => 'Falha na geracao',
            'pu_curve_validated' => 'Curva validada',
            'pu_curve_exported' => 'Curva exportada',
            'pu_curve_homologated' => 'Curva homologada',
            'pu_curve_invalidated' => 'Curva invalidada',
            'pu_homologation_report_downloaded' => 'PDF de homologacao baixado',
            'pu_index_synced' => 'Indices sincronizados (Banco Central)',
            'pu_index_rates_imported' => 'Indices importados (CSV)',
            'pu_index_rate_corrected' => 'Indice historico corrigido',
            'pu_curve_extension_failed' => 'Falha na extensao diaria',
            'pu_numeric_snapshots_prepared' => 'Snapshots numéricos preparados',
            'pu_numeric_events_prepared' => 'Eventos numéricos preparados',
            'pu_parameters_updated' => 'Parametros atualizados',
            'pu_candidate_configuration_created' => 'Configuração candidata criada',
            'pu_candidate_curve_persisted' => 'Curva candidata persistida',
            'pu_candidate_curve_approved' => 'Curva candidata aprovada internamente',
            'pu_candidate_curve_rejected' => 'Curva candidata rejeitada',
            'pu_curve_promotion_requested' => 'Promoção operacional solicitada',
            'pu_curve_promotion_approved' => 'Promoção operacional aprovada',
            'pu_curve_promotion_rejected' => 'Promoção operacional rejeitada',
            'pu_curve_promoted_operational' => 'Curva promovida a operacional',
            'pu_event_changed' => 'Evento de PU alterado',
            'pu_obligation_refresh_recovered' => 'Obrigações recompostas pela recuperação',
            'pu_obligation_refresh_failed' => 'Falha ao recompor as obrigações',
            'pu_obligation_refresh_retried' => 'Recomposição das obrigações retomada manualmente',
            'pu_operational_incident_acknowledged' => 'Incidente operacional reconhecido',
            default => $description,
        };
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changedKeys(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null),
        ));
    }

    public function latestValidationActivity(Emission $emission): ?Activity
    {
        /** @var Activity|null $activity */
        $activity = Activity::query()
            ->where('log_name', self::LOG_NAME)
            ->where('subject_type', $emission::class)
            ->where('subject_id', $emission->id)
            ->where('description', 'pu_curve_validated')
            ->latest('id')
            ->first();

        return $activity;
    }

    private function causer(?int $requestedByUserId): ?User
    {
        if ($requestedByUserId === null) {
            return null;
        }

        return User::query()->find($requestedByUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterSnapshot(Emission $emission): array
    {
        $parameter = $emission->puParameter;

        if ($parameter === null) {
            return [];
        }

        return [
            'curve_start_date' => $parameter->curve_start_date?->toDateString(),
            'curve_end_date' => $parameter->curve_end_date?->toDateString(),
            'initial_unit_value' => $parameter->getRawOriginal('initial_unit_value'),
            'spread_rate' => $parameter->getRawOriginal('spread_rate'),
            'annual_rate' => $parameter->getRawOriginal('annual_rate'),
            'indexer' => $parameter->indexer,
            'calculation_method' => $parameter->resolvedCalculationMethod()->value,
            'method_version' => $parameter->resolvedCalculationMethod()->engineVersion(),
            'business_day_basis' => $parameter->business_day_basis,
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode,
            'index_rate_lag_business_days' => $parameter->index_rate_lag_business_days,
            'first_coupon_pre_integralization_premium_enabled' => $parameter->first_coupon_pre_integralization_premium_enabled,
            'first_coupon_pre_integralization_business_days' => $parameter->first_coupon_pre_integralization_business_days,
            'first_coupon_pre_integralization_apply_index_factor' => $parameter->first_coupon_pre_integralization_apply_index_factor,
            'first_coupon_pre_integralization_apply_spread_factor' => $parameter->first_coupon_pre_integralization_apply_spread_factor,
            'legacy_projection_enabled' => $parameter->legacy_projection_enabled,
            ...(filled($parameter->index_rate_calendar_code)
                ? ['index_rate_calendar_code' => $parameter->index_rate_calendar_code]
                : []),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function largestDifferencesByField(PuValidationReport $report): array
    {
        $payload = [];

        foreach ($report->largestDifferencesByField as $field => $difference) {
            $payload[$field] = $this->differencePayload(null, $difference);
        }

        return $payload;
    }

    /**
     * @return array<string, int>
     */
    private function severityCountByLevel(PuValidationReport $report): array
    {
        $counts = [];

        foreach ($report->rows as $row) {
            foreach ($row->differences as $difference) {
                $level = $difference->severity?->value ?? 'alta';
                $counts[$level] = ($counts[$level] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleDifferences(Emission $emission, PuValidationReport $report): array
    {
        $payload = [];

        foreach ($report->rows as $row) {
            foreach ($row->differences as $difference) {
                $payload[] = $this->differencePayload($row, $difference) + [
                    'operation' => $emission->name,
                ];

                if (count($payload) >= self::MAX_STORED_DIFFERENCES) {
                    return $payload;
                }
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function differencePayload(?PuValidationRowResult $row, PuValidationFieldDifference $difference): array
    {
        return [
            'date' => $row?->date->toDateString(),
            'field' => $difference->field,
            'column' => $difference->label,
            'actual' => $difference->actual,
            'expected' => $difference->expected,
            'absolute_difference' => $difference->absoluteDifference,
            'percentage_difference' => $difference->percentageDifference,
            'mode' => $difference->comparisonMode,
            'severity' => $difference->severity?->value,
            'related_rule' => $difference->relatedRule,
            'possible_cause' => $difference->possibleCause,
            'spreadsheet_cell' => $difference->spreadsheetCell,
            'spreadsheet_formula' => $difference->spreadsheetFormula,
        ];
    }
}
