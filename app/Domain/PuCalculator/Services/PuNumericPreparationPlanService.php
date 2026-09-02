<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineReadinessReport;
use App\Domain\PuCalculator\DTOs\PuBaselineRequirement;
use App\Domain\PuCalculator\DTOs\PuNumericPreparationPlan;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

final class PuNumericPreparationPlanService
{
    public const STATE_FINANCIAL_PREPARATION_NOT_READY = 'financial_preparation_not_ready';

    public const STATE_RATES_MISSING = 'rates_missing';

    public const STATE_EVENTS_MISSING = 'events_missing';

    public const STATE_RATES_AND_EVENTS_MISSING = 'rates_and_events_missing';

    public const STATE_CONFLICT = 'conflict';

    public const STATE_READY = 'ready_for_numeric_homologation';

    public const ACTION_CONFIGURATION_CONFLICT = 'configuration_conflict';

    public const ACTION_EXISTING_FINANCIAL_EFFECTS = 'existing_financial_effects';

    public function __construct(
        private readonly PuBaselineReadinessService $readiness,
        private readonly PuIndexSnapshotPlanService $indexSnapshots,
        private readonly PuEventPlanService $events,
    ) {}

    public function plan(
        Emission $emission,
        ?CarbonImmutable $asOf = null,
    ): PuNumericPreparationPlan {
        $asOf = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $freshEmission = $emission->fresh() ?? $emission;
        $report = $this->readiness->evaluate($freshEmission, $asOf);
        $parameter = EmissionPuParameter::query()->whereBelongsTo($freshEmission)->first();
        $financialEffects = $this->financialEffects($freshEmission);

        if (! $parameter instanceof EmissionPuParameter) {
            return $this->blockedPlan(
                emission: $freshEmission,
                report: $report,
                financialEffects: $financialEffects,
                reason: 'EmissionPuParameter ausente; a preparação numérica não pode usar o candidato diagnóstico.',
            );
        }

        $curveStartDate = CarbonImmutable::instance($parameter->curve_start_date);
        $curveEndDate = CarbonImmutable::instance($parameter->curve_end_date);
        $effectiveEndDate = $asOf->min($curveEndDate);
        $parameterProvenance = $this->parameterProvenance($freshEmission, $parameter, $report);

        try {
            $this->readiness->assertCandidateCanBePersisted(
                $freshEmission,
                $this->parameterCandidatePayload($parameter),
                $asOf,
            );
        } catch (ValidationException $exception) {
            return $this->blockedPlan(
                emission: $freshEmission,
                report: $report,
                financialEffects: $financialEffects,
                reason: 'EmissionPuParameter diverge do baseline documental vigente: '.collect($exception->errors())
                    ->flatten()
                    ->implode(' '),
                parameter: $parameter,
                parameterProvenance: $parameterProvenance,
                action: self::ACTION_CONFIGURATION_CONFLICT,
            );
        }

        $blockingRequirements = $this->preparationBlockingRequirements($report);

        if ($report->pendingFields !== []
            || $blockingRequirements !== []
            || $effectiveEndDate->lt($curveStartDate)) {
            return $this->blockedPlan(
                emission: $freshEmission,
                report: $report,
                financialEffects: $financialEffects,
                reason: $effectiveEndDate->lt($curveStartDate)
                    ? 'A data de corte da homologação precede a curve_start_date persistida.'
                    : 'O readiness possui pré-condições materiais pendentes além de snapshots e eventos.',
                parameter: $parameter,
                parameterProvenance: $parameterProvenance,
                blockingRequirements: $blockingRequirements,
            );
        }

        $rateSourceApproval = Arr::only($report->indexSourceDiagnostics, [
            'source_code',
            'source_label',
            'reference_index_label',
            'rate_source_value',
            'technical_homologation_satisfied',
            'approved',
            'classification',
            'artifact_checksum',
            'report_checksum',
            'review',
        ]);
        $rateSource = is_string($report->indexSourceDiagnostics['rate_source_value'] ?? null)
            ? $report->indexSourceDiagnostics['rate_source_value']
            : null;
        $seriesCode = $parameter->indexer_enum->requiresIndexRates()
            ? (string) config('pu_indexes.bcb.series.'.mb_strtolower($parameter->indexer_enum->value).'.code')
            : null;
        $rateSourceReference = $rateSource !== null && filled($seriesCode)
            ? sprintf('%s:%s', $rateSource, $seriesCode)
            : null;
        $ratePlan = $this->indexSnapshots->inspect(
            parameter: $parameter,
            curveStartDate: $curveStartDate,
            homologationEndDate: $effectiveEndDate,
            source: $rateSource,
            seriesCode: $seriesCode,
            sourceReference: $rateSourceReference,
        );
        $eventPlan = $this->events->inspect(
            emission: $freshEmission,
            requirements: $report->eventDiagnostics['required_events'] ?? [],
            homologationEndDate: $effectiveEndDate,
        );
        $hasRateConflicts = $ratePlan['conflicting_rates'] !== [];
        $hasEventConflicts = $eventPlan['conflicting_events'] !== [];
        $hasMissingRates = $ratePlan['missing_rate_dates'] !== [];
        $hasMissingEvents = $eventPlan['missing_events'] !== [];
        $hasFinancialEffects = (bool) $financialEffects['guard_blocking'];
        $parameterFingerprint = $parameterProvenance['candidate_fingerprint'] ?? null;
        $approvedRateSourceCode = $report->indexSourceDiagnostics['source_code'] ?? null;

        [$state, $action, $reason] = match (true) {
            $hasRateConflicts || $hasEventConflicts => [
                self::STATE_CONFLICT,
                self::STATE_CONFLICT,
                'Há snapshots ou eventos incompatíveis; nenhuma linha existente pode ser sobrescrita.',
            ],
            $hasFinancialEffects && ($hasMissingRates || $hasMissingEvents) => [
                self::STATE_CONFLICT,
                self::ACTION_EXISTING_FINANCIAL_EFFECTS,
                'Existem efeitos financeiros que impedem alterar pré-requisitos já consumidos.',
            ],
            $hasMissingRates && $hasMissingEvents => [
                self::STATE_RATES_AND_EVENTS_MISSING,
                self::STATE_RATES_AND_EVENTS_MISSING,
                'Snapshots exatos e eventos contratuais ainda precisam ser preparados.',
            ],
            $hasMissingRates => [
                self::STATE_RATES_MISSING,
                self::STATE_RATES_MISSING,
                'Snapshots exatos ainda precisam ser preparados.',
            ],
            $hasMissingEvents => [
                self::STATE_EVENTS_MISSING,
                self::STATE_EVENTS_MISSING,
                'Eventos contratuais ainda precisam ser preparados.',
            ],
            default => [
                self::STATE_READY,
                self::STATE_READY,
                'Todos os snapshots exatos e eventos contratuais requeridos estão presentes.',
            ],
        };

        // Diagnóstico: qual seria o readiness SE esta preparação inserisse todos
        // os snapshots exatos e eventos contratuais ausentes. Efeitos financeiros
        // só invalidam a hipótese quando ela exigiria mutação material — uma
        // configuração já consistente permanece elegível mesmo com curva gerada.
        $preparationWouldMutate = $hasMissingRates || $hasMissingEvents;
        $hypotheticalBlocked = $hasRateConflicts
            || $hasEventConflicts
            || ($hasFinancialEffects && $preparationWouldMutate);
        $readinessAfterHypothetical = $hypotheticalBlocked
            ? $report->status->value
            : PuBaselineReadinessStatus::ReadyForNumericHomologation->value;

        return new PuNumericPreparationPlan(
            emissionId: $freshEmission->id,
            state: $state,
            action: $action,
            reason: $reason,
            readinessBefore: $report->status->value,
            readinessAfterHypothetical: $readinessAfterHypothetical,
            financialPreparationReady: true,
            parameterId: $parameter->id,
            parameterFingerprint: is_string($parameterFingerprint) ? $parameterFingerprint : null,
            parameterProvenance: $parameterProvenance,
            curveStartDate: $curveStartDate->toDateString(),
            curveEndDate: $curveEndDate->toDateString(),
            homologationEndDate: $effectiveEndDate->toDateString(),
            calendarWindow: $this->calendarWindow($report),
            rateWindow: [
                'derived_with' => PuIndexRateRequirementResolver::class,
                'from' => $ratePlan['required_rate_dates'][0] ?? null,
                'to' => $ratePlan['required_rate_dates'] === []
                    ? null
                    : $ratePlan['required_rate_dates'][array_key_last($ratePlan['required_rate_dates'])],
                'financial_requirement_start_date' => $ratePlan['financial_requirement_start_date'],
            ],
            rateSource: is_string($approvedRateSourceCode) ? $approvedRateSourceCode : null,
            rateSourceReference: $rateSourceReference,
            rateSourceApproval: $rateSourceApproval,
            requiredRateDates: $ratePlan['required_rate_dates'],
            presentRates: $ratePlan['present_rates'],
            missingRateDates: $ratePlan['missing_rate_dates'],
            conflictingRates: $ratePlan['conflicting_rates'],
            eventRequirements: $eventPlan['requirements'],
            existingEvents: $eventPlan['existing_events'],
            presentEvents: $eventPlan['present_events'],
            missingEvents: $eventPlan['missing_events'],
            conflictingEvents: $eventPlan['conflicting_events'],
            financialEffects: $financialEffects,
            blockingRequirements: [],
        );
    }

    /** @return list<string> */
    private function preparationBlockingRequirements(PuBaselineReadinessReport $report): array
    {
        return collect($report->requirements)
            ->reject(fn (PuBaselineRequirement $requirement, string $key): bool => in_array(
                $key,
                ['index_snapshots_loaded', 'pu_events_loaded', 'integralized_quantity', 'external_independent_validation'],
                true,
            ))
            ->filter(fn (PuBaselineRequirement $requirement): bool => ! $requirement->isSatisfied()
                && (in_array('candidate_configuration', $requirement->blocks, true)
                    || in_array('numeric_homologation', $requirement->blocks, true)))
            ->keys()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function parameterCandidatePayload(EmissionPuParameter $parameter): array
    {
        return [
            'curve_start_date' => $parameter->curve_start_date?->toDateString(),
            'curve_end_date' => $parameter->curve_end_date?->toDateString(),
            'initial_unit_value' => (string) $parameter->initial_unit_value,
            'spread_rate' => $parameter->spread_rate !== null ? (string) $parameter->spread_rate : null,
            'indexer' => $parameter->indexer,
            'business_day_basis' => (int) $parameter->business_day_basis,
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode,
            'index_rate_lag_business_days' => (int) $parameter->index_rate_lag_business_days,
            'first_coupon_pre_integralization_premium_enabled' => (bool) $parameter->first_coupon_pre_integralization_premium_enabled,
            'first_coupon_pre_integralization_business_days' => $parameter->first_coupon_pre_integralization_business_days,
            'first_coupon_pre_integralization_apply_index_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_index_factor,
            'first_coupon_pre_integralization_apply_spread_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor,
            'legacy_projection_enabled' => (bool) $parameter->legacy_projection_enabled,
        ];
    }

    /** @return array<string, int|bool> */
    private function financialEffects(Emission $emission): array
    {
        $curveCount = $emission->puDailyCurves()->count();
        $curveVersionCount = $emission->puCurveVersions()->count();
        $validatedOrHomologatedCurveVersionCount = EmissionPuCurveVersion::query()
            ->whereBelongsTo($emission)
            ->whereIn('status', [
                PuCurveStatus::Validated->value,
                PuCurveStatus::Homologated->value,
            ])
            ->count();
        $historyCount = $emission->puHistories()->count();
        $paymentCount = $emission->payments()->count();
        $guardBlocking = $curveCount > 0
            || $curveVersionCount > 0
            || $historyCount > 0
            || $paymentCount > 0;

        return [
            'guard_blocking' => $guardBlocking,
            'curve_count' => $curveCount,
            'curve_version_count' => $curveVersionCount,
            'validated_or_homologated_curve_version_count' => $validatedOrHomologatedCurveVersionCount,
            'pu_history_count' => $historyCount,
            'payment_count' => $paymentCount,
        ];
    }

    /** @return array<string, mixed> */
    private function parameterProvenance(
        Emission $emission,
        EmissionPuParameter $parameter,
        PuBaselineReadinessReport $report,
    ): array {
        /** @var Activity|null $creationActivity */
        $creationActivity = Activity::query()
            ->where('log_name', PuAuditLogService::LOG_NAME)
            ->where('subject_type', $emission::class)
            ->where('subject_id', $emission->id)
            ->where('description', 'pu_candidate_configuration_created')
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(fn (Activity $activity): bool => (int) $activity->properties->get('parameter_id') === $parameter->id);

        return [
            'recognition' => $creationActivity instanceof Activity
                ? 'controlled_candidate_persistence'
                : 'equivalent_valid_configuration',
            'candidate_fingerprint' => $creationActivity?->properties->get('candidate_fingerprint'),
            'candidate_source' => $creationActivity?->properties->get('candidate_source'),
            'persistence_activity_id' => $creationActivity?->id,
            'calendar_confirmation' => $this->calendarWindow($report),
            'rate_source_approval' => Arr::only($report->indexSourceDiagnostics, [
                'source_code', 'classification', 'artifact_checksum', 'report_checksum', 'review',
            ]),
            'event_baseline_sources' => collect($report->candidateFields)
                ->whereIn('field', [
                    'first_interest_payment_date',
                    'interest_payment_frequency',
                    'amortization',
                    'payment_convention',
                ])
                ->map(fn (array $field): array => Arr::only($field, [
                    'field', 'value', 'document_id', 'reference', 'reviewer', 'reviewed_at', 'status',
                ]))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function calendarWindow(PuBaselineReadinessReport $report): array
    {
        return [
            'calendar_code' => $report->calendarDiagnostics['calendar_code'] ?? null,
            'from' => $report->calendarDiagnostics['required_from'] ?? null,
            'to' => $report->calendarDiagnostics['required_to'] ?? null,
            'technical_coverage_satisfied' => $report->calendarDiagnostics['technical_coverage_satisfied'] ?? false,
            'administratively_confirmed' => $report->calendarDiagnostics['administratively_confirmed'] ?? false,
            'years' => collect($report->calendarDiagnostics['years'] ?? [])
                ->map(fn (array $year): array => Arr::only($year, [
                    'year', 'coverage_status', 'governance_status', 'checksum', 'review_state',
                ]))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, int|bool>  $financialEffects
     * @param  array<string, mixed>  $parameterProvenance
     * @param  list<string>  $blockingRequirements
     */
    private function blockedPlan(
        Emission $emission,
        PuBaselineReadinessReport $report,
        array $financialEffects,
        string $reason,
        ?EmissionPuParameter $parameter = null,
        array $parameterProvenance = [],
        string $action = self::STATE_FINANCIAL_PREPARATION_NOT_READY,
        array $blockingRequirements = [],
    ): PuNumericPreparationPlan {
        $candidateCurveStartDate = $report->candidateConfiguration['curve_start_date'] ?? null;
        $parameterFingerprint = $parameterProvenance['candidate_fingerprint'] ?? null;
        $approvedRateSourceCode = $report->indexSourceDiagnostics['source_code'] ?? null;

        return new PuNumericPreparationPlan(
            emissionId: $emission->id,
            state: $action === self::ACTION_CONFIGURATION_CONFLICT
                ? self::STATE_CONFLICT
                : self::STATE_FINANCIAL_PREPARATION_NOT_READY,
            action: $action,
            reason: $reason,
            readinessBefore: $report->status->value,
            readinessAfterHypothetical: $report->status->value,
            financialPreparationReady: false,
            parameterId: $parameter?->id,
            parameterFingerprint: is_string($parameterFingerprint) ? $parameterFingerprint : null,
            parameterProvenance: $parameterProvenance,
            curveStartDate: $parameter?->curve_start_date?->toDateString()
                ?? (is_string($candidateCurveStartDate) ? $candidateCurveStartDate : null),
            curveEndDate: $parameter?->curve_end_date?->toDateString(),
            homologationEndDate: null,
            calendarWindow: $parameter instanceof EmissionPuParameter
                ? $this->calendarWindow($report)
                : $this->unresolvedFinancialCalendarWindow($report),
            rateWindow: [
                'derived_with' => PuIndexRateRequirementResolver::class,
                'from' => null,
                'to' => null,
                'financial_requirement_start_date' => null,
            ],
            rateSource: is_string($approvedRateSourceCode) ? $approvedRateSourceCode : null,
            rateSourceReference: null,
            rateSourceApproval: Arr::only($report->indexSourceDiagnostics, [
                'source_code', 'technical_homologation_satisfied', 'approved', 'classification', 'review',
            ]),
            requiredRateDates: [],
            presentRates: [],
            missingRateDates: [],
            conflictingRates: [],
            eventRequirements: [],
            existingEvents: [],
            presentEvents: [],
            missingEvents: [],
            conflictingEvents: [],
            financialEffects: $financialEffects,
            blockingRequirements: $blockingRequirements !== []
                ? $blockingRequirements
                : $this->candidateBlockingRequirements($report),
        );
    }

    /** @return list<string> */
    private function candidateBlockingRequirements(PuBaselineReadinessReport $report): array
    {
        return collect($report->requirements)
            ->filter(fn (PuBaselineRequirement $requirement): bool => ! $requirement->isSatisfied()
                && in_array('candidate_configuration', $requirement->blocks, true))
            ->keys()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function unresolvedFinancialCalendarWindow(PuBaselineReadinessReport $report): array
    {
        return [
            ...$this->calendarWindow($report),
            'from' => null,
            'to' => null,
        ];
    }
}
