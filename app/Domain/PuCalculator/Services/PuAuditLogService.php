<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuCurvePrerequisiteCheckResult;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationComparisonResult;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationValidationResult;
use App\Domain\PuCalculator\DTOs\PuValidationFieldDifference;
use App\Domain\PuCalculator\DTOs\PuValidationReport;
use App\Domain\PuCalculator\DTOs\PuValidationRowResult;
use App\Domain\PuCalculator\Enums\PuCandidateReviewDecision;
use App\Domain\PuCalculator\Enums\PuCurvePromotionDecision;
use App\Domain\PuCalculator\Enums\PuExternalValidationDecision;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuParameter;
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

    public function logHomologation(Emission $emission, ?string $calculationVersion, ?int $requestedByUserId): void
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

        $logger->event('homologated')->log('pu_curve_homologated');
    }

    public function logInvalidation(Emission $emission, ?string $calculationVersion, ?int $requestedByUserId): void
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
     */
    public function logContractualScheduleGenerated(
        Emission $emission,
        User $actor,
        array $insertedEventIds,
        array $missingInCalculatedPeriod,
        array $conflicts,
        ?string $lastCalculatedDate,
    ): void {
        activity(self::LOG_NAME)
            ->performedOn($emission)
            ->causedBy($actor)
            ->withProperties([
                'engine_version' => self::ENGINE_VERSION,
                'action' => 'contractual_schedule_generated',
                'inserted_event_ids' => $insertedEventIds,
                'missing_in_calculated_period' => $missingInCalculatedPeriod,
                'conflicts' => $conflicts,
                'last_calculated_date' => $lastCalculatedDate,
            ])
            ->event('event_changed')
            ->log('pu_event_changed');
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
