<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineCandidate;
use App\Domain\PuCalculator\DTOs\PuBaselineReadinessReport;
use App\Domain\PuCalculator\DTOs\PuBaselineRequirement;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementCategory;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementSeverity;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PuBaselineReadinessService
{
    public function __construct(
        private readonly PuBaselineCandidateFactory $candidateFactory,
        private readonly PuBaselineCalendarReadinessService $calendarReadiness,
        private readonly PuBaselineIndexSourceReadinessService $indexSourceReadiness,
        private readonly PuBaselineEngineCapabilityService $engineCapabilities,
        private readonly PuBaselineEventRequirementService $eventRequirements,
        private readonly PuIndexRateRequirementResolver $rateRequirementResolver,
        private readonly IndexRateLookupService $indexRateLookup,
    ) {}

    public function supports(Emission $emission): bool
    {
        return $this->candidateFactory->supports($emission);
    }

    public function evaluate(
        Emission $emission,
        ?CarbonImmutable $homologationEndDate = null,
    ): PuBaselineReadinessReport {
        $homologationEndDate ??= CarbonImmutable::today();
        $emission->loadMissing([
            'integralizationHistories',
            'puBaselineEvidences.document',
            'puBaselineEvidences.reviewedBy',
            'puEvents',
        ]);
        $approvedEvidences = $emission->puBaselineEvidences
            ->filter(fn (EmissionPuBaselineEvidence $evidence): bool => $evidence->status === PuBaselineEvidenceStatus::Approved
                && in_array($evidence->confidence, ['high', 'medium'], true));
        $dateEvidence = $this->latestEvidence($approvedEvidences, PuBaselineEvidenceType::FirstIntegralizationDate);

        if ($this->evidencedDate($dateEvidence) === null) {
            $dateEvidence = null;
        }

        $quantityEvidence = $this->latestEvidence($approvedEvidences, PuBaselineEvidenceType::IntegralizedQuantity);
        $externalEvidence = $this->latestEvidence($approvedEvidences, PuBaselineEvidenceType::ExternalPuReference);
        $candidate = $this->candidateFactory->make($emission, $dateEvidence, $homologationEndDate);
        $calendarDiagnostics = $this->calendarReadiness->evaluate(
            $this->candidateString($candidate, 'calendar_code'),
            $candidate->calendarFromDate,
            $candidate->calendarToDate(),
        );
        $indexSourceDiagnostics = $this->indexSourceReadiness->evaluate($candidate->indexer);
        $rateWindow = $this->rateWindow(
            $candidate,
            $homologationEndDate,
            $calendarDiagnostics['technical_coverage_satisfied'],
            $indexSourceDiagnostics,
        );
        $eventDiagnostics = $this->eventRequirements->evaluate(
            $emission,
            $candidate,
            $homologationEndDate,
            $calendarDiagnostics['technical_coverage_satisfied'],
        );
        $externalValidated = $externalEvidence?->evidenced_value === 'matched';
        $requirements = [
            ...$candidate->contractRequirements,
            ...$this->engineCapabilities->requirements($candidate),
            ...$this->readinessRequirements(
                $calendarDiagnostics,
                $indexSourceDiagnostics,
                $dateEvidence,
                $quantityEvidence,
                $rateWindow,
                $eventDiagnostics,
                $externalEvidence,
                $externalValidated,
            ),
        ];
        $status = $this->resolveStatus($requirements, $externalValidated);

        return new PuBaselineReadinessReport(
            emissionId: $emission->id,
            status: $status,
            requirements: $requirements,
            candidateConfiguration: $candidate->configuration,
            candidateFields: $candidate->fields,
            pendingFields: $candidate->pendingFields(),
            calendarDiagnostics: $calendarDiagnostics,
            indexSourceDiagnostics: $indexSourceDiagnostics,
            integralizationDiagnostics: $this->integralizationDiagnostics($emission, $dateEvidence),
            quantityDiagnostics: $this->quantityDiagnostics($emission, $quantityEvidence),
            rateWindow: $rateWindow,
            eventDiagnostics: $eventDiagnostics,
            limitations: [
                ...($externalValidated ? [] : ['Homologação externa independente pendente.']),
                ...$candidate->calendarWindowLimitations,
            ],
            nextActions: $this->nextActions($requirements, $calendarDiagnostics, $indexSourceDiagnostics),
        );
    }

    /** @param array<string, mixed> $submitted */
    public function assertCandidateCanBePersisted(
        Emission $emission,
        array $submitted,
        ?CarbonImmutable $asOf = null,
    ): void {
        $report = $this->evaluate($emission, $asOf);

        if ($report->status === PuBaselineReadinessStatus::Blocked) {
            throw ValidationException::withMessages([
                'baseline_readiness' => implode("\n", collect($report->requirements)
                    ->filter(fn (PuBaselineRequirement $requirement): bool => in_array(
                        'candidate_configuration',
                        $requirement->blocks,
                        true,
                    ) && ! $requirement->isSatisfied())
                    ->map(fn (PuBaselineRequirement $requirement): string => '• '.$requirement->reason)
                    ->all()),
            ]);
        }

        $expected = collect($report->candidateConfiguration)->only([
            'curve_start_date',
            'curve_end_date',
            'initial_unit_value',
            'spread_rate',
            'indexer',
            'business_day_basis',
            'calendar_code',
            'index_rate_lookup_mode',
            'index_rate_lag_business_days',
            'first_coupon_pre_integralization_premium_enabled',
            'first_coupon_pre_integralization_business_days',
            'first_coupon_pre_integralization_apply_index_factor',
            'first_coupon_pre_integralization_apply_spread_factor',
            'legacy_projection_enabled',
        ]);
        $differentFields = $expected
            ->filter(fn (mixed $value, string $key): bool => $this->normalizedValue($submitted[$key] ?? null)
                !== $this->normalizedValue($value))
            ->keys()
            ->all();

        if ($differentFields !== []) {
            throw ValidationException::withMessages([
                'baseline_candidate' => sprintf(
                    'A configuração informada diverge do candidato comprovado nos campos: %s.',
                    implode(', ', $differentFields),
                ),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @param  array<string, mixed>  $indexSource
     * @param  array<string, mixed>  $rateWindow
     * @param  array<string, mixed>  $events
     * @return array<string, PuBaselineRequirement>
     */
    private function readinessRequirements(
        array $calendar,
        array $indexSource,
        ?EmissionPuBaselineEvidence $dateEvidence,
        ?EmissionPuBaselineEvidence $quantityEvidence,
        array $rateWindow,
        array $events,
        ?EmissionPuBaselineEvidence $externalEvidence,
        bool $externalValidated,
    ): array {
        $calendarCode = $calendar['calendar_code'] ?? 'PENDING';
        $calendarPeriod = $this->periodLabel($calendar['required_from'] ?? null, $calendar['required_to'] ?? null);
        $sourceLabel = $indexSource['source_label'] ?? 'fonte ainda não resolvida';
        $sourceRequired = (bool) ($indexSource['required'] ?? true);
        $calendarTechnical = (bool) ($calendar['technical_coverage_satisfied'] ?? false);
        $calendarConfirmed = (bool) ($calendar['administratively_confirmed'] ?? false);
        $sourceTechnical = (bool) ($indexSource['technical_homologation_satisfied'] ?? false);
        $sourceApproved = (bool) ($indexSource['approved'] ?? false);
        $scheduleSupported = (bool) ($events['contractual_schedule_known'] ?? false)
            && (bool) ($events['schedule_supported'] ?? false);

        return [
            'engine_contractual_schedule_capability' => $this->requirement(
                code: 'engine_contractual_schedule_capability',
                name: 'Cronograma contratual representável',
                category: PuBaselineRequirementCategory::EngineCapability,
                status: $scheduleSupported
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Blocking,
                reason: $scheduleSupported
                    ? 'A engine de eventos reconhece a frequência, a amortização e a convenção comprovadas.'
                    : 'O cronograma está incompleto ou contém uma convenção ainda não reconhecida pelo gate.',
                expected: 'schedule_supported',
                found: $events['schedule'] ?? null,
                blocks: ['candidate_configuration', 'numeric_homologation'],
            ),
            'calendar_technical_coverage' => $this->requirement(
                code: 'calendar_technical_coverage',
                name: 'Cobertura técnica do calendário',
                category: PuBaselineRequirementCategory::Governance,
                status: $calendarTechnical
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Blocking,
                reason: $calendarTechnical
                    ? sprintf('%s possui cobertura técnica completa para %s.', $calendarCode, $calendarPeriod)
                    : sprintf('%s não possui cobertura técnica completa para %s.', $calendarCode, $calendarPeriod),
                evidence: [
                    'calendar_code' => $calendarCode,
                    'years' => collect($calendar['years'] ?? [])->map(fn (array $year): array => Arr::only(
                        $year,
                        ['year', 'coverage_status', 'governance_status', 'checksum', 'review_state'],
                    ))->all(),
                ],
                expected: 'complete',
                found: $calendarTechnical ? 'complete' : 'incomplete_or_unresolved',
                blocks: ['candidate_configuration', 'numeric_homologation'],
            ),
            'calendar_administrative_confirmation' => $this->requirement(
                code: 'calendar_administrative_confirmation',
                name: 'Confirmação administrativa do calendário',
                category: PuBaselineRequirementCategory::Governance,
                status: $calendarConfirmed
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::AdministrativePending,
                reason: $calendarConfirmed
                    ? sprintf('%s está confirmado administrativamente para %s.', $calendarCode, $calendarPeriod)
                    : sprintf('%s aguarda confirmação administrativa para %s.', $calendarCode, $calendarPeriod),
                expected: 'confirmed',
                found: $calendarConfirmed ? 'confirmed' : 'provisional_or_unresolved',
                blocks: ['candidate_configuration', 'numeric_homologation'],
            ),
            'index_source_technical_homologation' => $this->requirement(
                code: 'index_source_technical_homologation',
                name: 'Homologação técnica da fonte do índice',
                category: PuBaselineRequirementCategory::Governance,
                status: ! $sourceRequired || $sourceTechnical
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Blocking,
                reason: ! $sourceRequired
                    ? 'O indexador não exige snapshots externos.'
                    : ($sourceTechnical
                        ? sprintf('%s possui homologação técnica conclusiva.', $sourceLabel)
                        : sprintf('%s ainda não possui homologação técnica conclusiva.', $sourceLabel)),
                evidence: Arr::only($indexSource, [
                    'source_code', 'source_label', 'reference_index_label', 'artifact_path',
                    'artifact_checksum', 'report_checksum', 'workflow_status', 'classification',
                    'executor', 'executed_at',
                ]),
                expected: $sourceRequired ? 'technically_homologated' : 'not_required',
                found: ! $sourceRequired ? 'not_required' : ($sourceTechnical ? 'technically_homologated' : 'pending'),
                blocks: ['candidate_configuration', 'numeric_homologation'],
            ),
            'index_source_operational_approval' => $this->requirement(
                code: 'index_source_operational_approval',
                name: 'Aprovação operacional da fonte do índice',
                category: PuBaselineRequirementCategory::Governance,
                status: ! $sourceRequired || $sourceApproved
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::AdministrativePending,
                reason: ! $sourceRequired
                    ? 'O indexador não exige fonte operacional externa.'
                    : ($sourceApproved
                        ? sprintf('%s possui aprovação operacional explícita.', $sourceLabel)
                        : sprintf('%s ainda não foi aprovada para uso operacional.', $sourceLabel)),
                evidence: ['review' => $indexSource['review'] ?? null],
                expected: $sourceRequired ? 'approved' : 'not_required',
                found: ! $sourceRequired ? 'not_required' : ($sourceApproved ? 'approved' : 'pending'),
                blocks: ['candidate_configuration', 'numeric_homologation'],
            ),
            'first_integralization_date' => $this->requirement(
                code: 'first_integralization_date',
                name: 'Data inicial da curva',
                category: PuBaselineRequirementCategory::OperationalData,
                status: $dateEvidence instanceof EmissionPuBaselineEvidence
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Blocking,
                reason: $dateEvidence instanceof EmissionPuBaselineEvidence
                    ? sprintf('A data inicial foi comprovada como %s.', $dateEvidence->evidenced_value)
                    : 'Falta evidência aprovada da data efetiva que inicia a curva.',
                evidence: $this->baselineEvidence($dateEvidence),
                expected: 'approved_curve_start_date_evidence',
                found: $dateEvidence?->evidenced_value,
                blocks: ['candidate_configuration', 'numeric_homologation'],
            ),
            'integralized_quantity' => $this->requirement(
                code: 'integralized_quantity',
                name: 'Quantidade efetivamente integralizada',
                category: PuBaselineRequirementCategory::OperationalData,
                status: $quantityEvidence instanceof EmissionPuBaselineEvidence
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Blocking,
                reason: $quantityEvidence instanceof EmissionPuBaselineEvidence
                    ? sprintf('A quantidade operacional foi comprovada como %s.', $quantityEvidence->evidenced_value)
                    : 'A quantidade não foi comprovada; ela afeta somente saldos e pagamentos agregados.',
                evidence: $this->baselineEvidence($quantityEvidence),
                expected: 'approved_quantity_evidence_for_aggregate_outputs',
                found: $quantityEvidence?->evidenced_value,
                blocks: ['aggregate_outputs'],
            ),
            'index_snapshots_loaded' => $this->requirement(
                code: 'index_snapshots_loaded',
                name: 'Snapshots do índice disponíveis',
                category: PuBaselineRequirementCategory::OperationalData,
                status: ($rateWindow['resolvable'] ?? false) && ($rateWindow['missing_rate_dates'] ?? []) === []
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Blocking,
                reason: ($rateWindow['resolvable'] ?? false)
                    ? sprintf(
                        '%d de %d snapshots requeridos estão carregados na fonte selecionada; %d data(s) ausente(s) ou incompatível(eis).',
                        (int) ($rateWindow['loaded_rate_count'] ?? 0),
                        (int) ($rateWindow['required_rate_count'] ?? 0),
                        count($rateWindow['missing_rate_dates'] ?? []),
                    )
                    : 'A janela de snapshots ainda não pode ser resolvida.',
                expected: $rateWindow['required_rate_dates'] ?? [],
                found: $rateWindow['loaded_rate_dates'] ?? [],
                blocks: ['numeric_homologation'],
            ),
            'pu_events_loaded' => $this->requirement(
                code: 'pu_events_loaded',
                name: 'Eventos PU persistidos',
                category: PuBaselineRequirementCategory::OperationalData,
                status: ($events['resolvable'] ?? false) && ($events['missing_events'] ?? []) === []
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Blocking,
                reason: ($events['resolvable'] ?? false)
                    ? sprintf(
                        '%d de %d eventos necessários à janela estão carregados.',
                        (int) ($events['loaded_required_event_count'] ?? 0),
                        (int) ($events['required_event_count'] ?? 0),
                    )
                    : 'O cronograma contratual ainda não pode ser confrontado com os eventos persistidos.',
                expected: $events['required_events'] ?? [],
                found: (int) ($events['loaded_required_event_count'] ?? 0),
                blocks: ['numeric_homologation'],
            ),
            'external_independent_validation' => $this->requirement(
                code: 'external_independent_validation',
                name: 'Homologação independente',
                category: PuBaselineRequirementCategory::IndependentValidation,
                status: $externalValidated
                    ? PuBaselineRequirementStatus::Satisfied
                    : PuBaselineRequirementStatus::Recommended,
                reason: $externalValidated
                    ? 'Existe gabarito externo aprovado com comparação aderente.'
                    : 'Validação independente pendente; recomenda-se revisão reforçada da memória experimental.',
                evidence: $this->baselineEvidence($externalEvidence),
                expected: 'matched',
                found: $externalEvidence?->evidenced_value,
                blocks: ['external_validation'],
            ),
        ];
    }

    /**
     * @param  array<string, PuBaselineRequirement>  $requirements
     */
    private function resolveStatus(array $requirements, bool $externalValidated): PuBaselineReadinessStatus
    {
        $candidateBlocked = collect($requirements)->contains(
            fn (PuBaselineRequirement $requirement): bool => in_array(
                'candidate_configuration',
                $requirement->blocks,
                true,
            ) && ! $requirement->isSatisfied(),
        );

        if ($candidateBlocked) {
            return PuBaselineReadinessStatus::Blocked;
        }

        $numericBlocked = collect($requirements)->contains(
            fn (PuBaselineRequirement $requirement): bool => in_array(
                'numeric_homologation',
                $requirement->blocks,
                true,
            ) && ! $requirement->isSatisfied(),
        );

        if ($numericBlocked) {
            return PuBaselineReadinessStatus::ReadyForCandidateConfiguration;
        }

        return $externalValidated
            ? PuBaselineReadinessStatus::ExternallyValidated
            : PuBaselineReadinessStatus::ReadyForNumericHomologation;
    }

    /**
     * @param  array<string, mixed>  $indexSource
     * @return array<string, mixed>
     */
    private function rateWindow(
        PuBaselineCandidate $candidate,
        CarbonImmutable $requestedEndDate,
        bool $calendarTechnicallyReady,
        array $indexSource,
    ): array {
        $curveStartDate = $this->date($candidate->configuration['curve_start_date'] ?? null);
        $base = [
            'derived_with' => 'PuIndexRateRequirementResolver',
            'resolvable' => false,
            'curve_start_date' => $curveStartDate?->toDateString(),
            'homologation_end_date' => null,
            'financial_requirement_start_date' => null,
            'required_rate_dates' => [],
            'loaded_rate_dates' => [],
            'missing_rate_dates' => [],
            'source_mismatch_dates' => [],
            'required_rate_count' => 0,
            'loaded_rate_count' => 0,
        ];

        if ($curveStartDate === null
            || $candidate->curveEndDate === null
            || $candidate->indexer === null
            || ! $calendarTechnicallyReady) {
            return $base;
        }

        $homologationEndDate = $requestedEndDate->min($candidate->curveEndDate);

        if ($homologationEndDate->lt($curveStartDate)) {
            return [...$base, 'homologation_end_date' => $homologationEndDate->toDateString()];
        }

        if (! $candidate->indexer->requiresIndexRates()) {
            return [
                ...$base,
                'resolvable' => true,
                'homologation_end_date' => $homologationEndDate->toDateString(),
                'financial_requirement_start_date' => $curveStartDate->toDateString(),
            ];
        }

        if (! $candidate->lookupMode instanceof PuIndexRateLookupMode) {
            return $base;
        }

        $parameterAttributes = Arr::except($candidate->configuration, ['index_percentage']);
        $parameterAttributes['curve_start_date'] = $curveStartDate;
        $parameterAttributes['curve_end_date'] = $homologationEndDate;
        $parameter = new EmissionPuParameter($parameterAttributes);
        $this->indexRateLookup->flushCache();
        $requirements = collect($this->rateRequirementResolver
            ->firstCouponPreIntegralizationRateRequirements($parameter));

        for ($curveDate = $curveStartDate; $curveDate->lte($homologationEndDate); $curveDate = $curveDate->addDay()) {
            $requirement = $this->rateRequirementResolver->resolve($parameter, $curveDate);

            if ($requirement->isRequiredForCalculation()) {
                $requirements->push($requirement);
            }
        }

        $requiredByDate = $requirements
            ->filter(fn (PuIndexRateRequirement $requirement): bool => $requirement->requiredRateDate() !== null)
            ->groupBy(fn (PuIndexRateRequirement $requirement): string => $requirement->requiredRateDate()->toDateString());
        $requiredRateDates = $requiredByDate->keys()->sort()->values();
        $selectedDataSource = $indexSource['rate_source_value'] ?? null;
        $missingRateDates = $requiredByDate
            ->filter(fn (Collection $sameDateRequirements): bool => $sameDateRequirements->every(
                fn (PuIndexRateRequirement $requirement): bool => $requirement->rate === null
                    || (is_string($selectedDataSource)
                        && $selectedDataSource !== ''
                        && $requirement->rate->source !== $selectedDataSource),
            ))
            ->keys()
            ->sort()
            ->values();
        $sourceMismatchDates = $requiredByDate
            ->filter(fn (Collection $sameDateRequirements): bool => is_string($selectedDataSource)
                && $selectedDataSource !== ''
                && $sameDateRequirements->contains(
                    fn (PuIndexRateRequirement $requirement): bool => $requirement->rate !== null
                        && $requirement->rate->source !== $selectedDataSource,
                ))
            ->keys()
            ->sort()
            ->values();
        $loadedRateDates = $requiredRateDates->diff($missingRateDates)->values();

        return [
            ...$base,
            'resolvable' => true,
            'homologation_end_date' => $homologationEndDate->toDateString(),
            'financial_requirement_start_date' => $this->rateRequirementResolver
                ->firstCouponPreIntegralizationFinancialCalendarStartDate($parameter)?->toDateString()
                ?? $curveStartDate->toDateString(),
            'required_rate_dates' => $requiredRateDates->all(),
            'loaded_rate_dates' => $loadedRateDates->all(),
            'missing_rate_dates' => $missingRateDates->all(),
            'source_mismatch_dates' => $sourceMismatchDates->all(),
            'required_rate_count' => $requiredRateDates->count(),
            'loaded_rate_count' => $loadedRateDates->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function integralizationDiagnostics(
        Emission $emission,
        ?EmissionPuBaselineEvidence $dateEvidence,
    ): array {
        $firstOperationalRecord = $emission->integralizationHistories
            ->sortBy('date')
            ->first();

        return [
            'offer_status' => Emission::STATUS_OPTIONS[$emission->status] ?? $emission->status,
            'operational_record_count' => $emission->integralizationHistories->count(),
            'inferred_first_date' => $firstOperationalRecord?->date?->toDateString(),
            'proven_first_date' => $dateEvidence?->evidenced_value,
            'evidence_status' => $dateEvidence === null ? 'pending' : 'approved',
            'evidence_document' => $dateEvidence?->document?->title,
            'acceptable_documents' => [
                'Boletim de Subscrição',
                'documento de aceitação',
                'extrato de liquidação B3',
                'posição do escriturador',
                'comprovante do banco liquidante',
                'mapa final de distribuição',
                'Anúncio de Encerramento',
            ],
            'closing_announcement_required' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function quantityDiagnostics(
        Emission $emission,
        ?EmissionPuBaselineEvidence $quantityEvidence,
    ): array {
        return [
            'issued_quantity' => $emission->issued_quantity,
            'operational_integralized_quantity' => $emission->integralizationHistories->sum('quantity'),
            'proven_integralized_quantity' => $quantityEvidence?->evidenced_value,
            'final_offer_quantity_proven' => false,
            'offer_may_remain_open' => $emission->status === 'active',
            'required_for_unit_pu' => false,
            'required_for_aggregate_balance_and_payments' => true,
        ];
    }

    /**
     * @param  Collection<int, EmissionPuBaselineEvidence>  $evidences
     */
    private function latestEvidence(
        Collection $evidences,
        PuBaselineEvidenceType $type,
    ): ?EmissionPuBaselineEvidence {
        return $evidences
            ->filter(fn (EmissionPuBaselineEvidence $evidence): bool => $evidence->evidence_type === $type)
            ->sortByDesc('reviewed_at')
            ->first();
    }

    private function evidencedDate(?EmissionPuBaselineEvidence $evidence): ?CarbonImmutable
    {
        return $this->date($evidence?->evidenced_value);
    }

    /** @return array<string, mixed>|null */
    private function baselineEvidence(?EmissionPuBaselineEvidence $evidence): ?array
    {
        if (! $evidence instanceof EmissionPuBaselineEvidence) {
            return null;
        }

        return [
            'document_id' => $evidence->document_id,
            'document' => $evidence->document?->title,
            'reference' => $evidence->reference,
            'status' => $evidence->status->value,
            'confidence' => $evidence->confidence,
            'reviewer' => $evidence->reviewedBy?->name,
            'reviewed_at' => $evidence->reviewed_at?->toIso8601String(),
        ];
    }

    private function requirement(
        string $code,
        string $name,
        PuBaselineRequirementCategory $category,
        PuBaselineRequirementStatus $status,
        string $reason,
        ?array $evidence = null,
        mixed $expected = null,
        mixed $found = null,
        array $blocks = [],
    ): PuBaselineRequirement {
        return new PuBaselineRequirement(
            code: $code,
            name: $name,
            category: $category,
            status: $status,
            severity: match ($status) {
                PuBaselineRequirementStatus::Blocking => PuBaselineRequirementSeverity::Critical,
                PuBaselineRequirementStatus::AdministrativePending,
                PuBaselineRequirementStatus::Recommended => PuBaselineRequirementSeverity::Warning,
                PuBaselineRequirementStatus::Satisfied => PuBaselineRequirementSeverity::Information,
            },
            reason: $reason,
            evidence: $evidence,
            expected: $expected,
            found: $found,
            blocks: $blocks,
        );
    }

    /**
     * @param  array<string, PuBaselineRequirement>  $requirements
     * @param  array<string, mixed>  $calendar
     * @param  array<string, mixed>  $indexSource
     * @return list<string>
     */
    private function nextActions(array $requirements, array $calendar, array $indexSource): array
    {
        $actions = [];

        if (! $requirements['index_source_operational_approval']->isSatisfied()) {
            $actions[] = sprintf(
                'Revisar e aprovar explicitamente a fonte %s.',
                $indexSource['source_label'] ?? 'selecionada',
            );
        }

        if (! $requirements['calendar_administrative_confirmation']->isSatisfied()) {
            $actions[] = sprintf(
                'Confirmar administrativamente o calendário %s para o período requerido.',
                $calendar['calendar_code'] ?? 'contratual',
            );
        }

        if (! $requirements['first_integralization_date']->isSatisfied()) {
            $actions[] = 'Vincular e aprovar um documento que comprove a data inicial efetiva da curva.';
        }

        $contractPending = collect($requirements)
            ->first(fn (PuBaselineRequirement $requirement): bool => $requirement->category === PuBaselineRequirementCategory::Contract
                && ! $requirement->isSatisfied());

        if ($contractPending instanceof PuBaselineRequirement) {
            $actions[] = sprintf('Comprovar o requisito contratual: %s.', $contractPending->name);
        }

        if (! $requirements['pu_events_loaded']->isSatisfied()) {
            $actions[] = 'Materializar os eventos PU comprovados para a janela de homologação.';
        }

        if (! $requirements['index_snapshots_loaded']->isSatisfied()) {
            $actions[] = 'Carregar os snapshots do índice exatamente nas datas resolvidas pelo gate.';
        }

        if (! $requirements['external_independent_validation']->isSatisfied()) {
            $actions[] = 'Obter memória oficial de PU para validação independente posterior.';
        }

        return array_values(array_unique($actions));
    }

    private function candidateString(PuBaselineCandidate $candidate, string $key): ?string
    {
        $value = $candidate->configuration[$key] ?? null;

        return is_string($value) && $value !== 'PENDING' ? $value : null;
    }

    private function periodLabel(mixed $from, mixed $to): string
    {
        return is_string($from) && is_string($to) ? sprintf('%s a %s', $from, $to) : 'período ainda não resolvido';
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->toDateString() === $value ? $date : null;
    }

    private function normalizedValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 16, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}
