<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineCandidatePersistenceResult;
use App\Domain\PuCalculator\DTOs\PuBaselineReadinessReport;
use App\Domain\PuCalculator\DTOs\PuBaselineRequirement;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Enums\AccessPermission;
use App\Models\BusinessCalendarYear;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuParameter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class PuBaselineCandidatePersistenceService
{
    private const PENDING_VALUE = 'PENDING';

    public const ACTION_CANDIDATE_NOT_READY = 'candidate_configuration_not_ready';

    public const ACTION_CANDIDATE_INVALID = 'candidate_configuration_invalid';

    public const ACTION_WILL_CREATE = 'will_create';

    public const ACTION_ALREADY_MATCHES = 'already_matches';

    public const ACTION_CONFIGURATION_CONFLICT = 'configuration_conflict';

    public const ACTION_ACTOR_REQUIRED = 'actor_required';

    public const ACTION_ACTOR_NOT_FOUND = 'actor_not_found';

    public const ACTION_ACTOR_INACTIVE = 'actor_inactive';

    public const ACTION_ACTOR_UNAPPROVED = 'actor_unapproved';

    public const ACTION_ACTOR_UNAUTHORIZED = 'actor_unauthorized';

    public const ACTION_CREATED = 'created';

    /**
     * O conjunto define os campos materiais do fingerprint; a serialização
     * canônica ordena as chaves antes de calcular o SHA-256.
     *
     * @var array<string, array{parameter_field:?string,persisted:bool,reason:string}>
     */
    private const FIELD_MAPPING = [
        'indexer' => [
            'parameter_field' => 'indexer',
            'persisted' => true,
            'reason' => 'Mapeamento direto para o indexador canônico da engine.',
        ],
        'index_percentage' => [
            'parameter_field' => null,
            'persisted' => false,
            'reason' => 'A engine atual suporta somente CDI comprovado a 100%; não existe coluna direta.',
        ],
        'spread_rate' => [
            'parameter_field' => 'spread_rate',
            'persisted' => true,
            'reason' => 'Pontos percentuais anuais já normalizados pelo candidate factory.',
        ],
        'calculation_method' => [
            'parameter_field' => 'calculation_method',
            'persisted' => true,
            'reason' => 'Método canônico derivado do indexador comprovado.',
        ],
        'business_day_basis' => [
            'parameter_field' => 'business_day_basis',
            'persisted' => true,
            'reason' => 'Mapeamento direto para a base anual de Dias Úteis.',
        ],
        'calendar_code' => [
            'parameter_field' => 'calendar_code',
            'persisted' => true,
            'reason' => 'Código canônico do calendário confirmado.',
        ],
        'index_rate_lookup_mode' => [
            'parameter_field' => 'index_rate_lookup_mode',
            'persisted' => true,
            'reason' => 'Valor canônico do enum; quando inaplicável, preserva o default neutro do schema.',
        ],
        'index_rate_lag_business_days' => [
            'parameter_field' => 'index_rate_lag_business_days',
            'persisted' => true,
            'reason' => 'Offset assinado sem conversão; quando inaplicável, preserva o default neutro do schema.',
        ],
        'curve_start_date' => [
            'parameter_field' => 'curve_start_date',
            'persisted' => true,
            'reason' => 'Data ISO derivada exclusivamente da evidência de primeira integralização aprovada.',
        ],
        'curve_end_date' => [
            'parameter_field' => 'curve_end_date',
            'persisted' => true,
            'reason' => 'Data ISO de vencimento comprovada no instrumento governante.',
        ],
        'initial_unit_value' => [
            'parameter_field' => 'initial_unit_value',
            'persisted' => true,
            'reason' => 'VNU com escala 16; não representa quantidade integralizada.',
        ],
        'first_coupon_pre_integralization_premium_enabled' => [
            'parameter_field' => 'first_coupon_pre_integralization_premium_enabled',
            'persisted' => true,
            'reason' => 'Flag contratual do prêmio inicial.',
        ],
        'first_coupon_pre_integralization_business_days' => [
            'parameter_field' => 'first_coupon_pre_integralization_business_days',
            'persisted' => true,
            'reason' => 'Quantidade contratual de Dias Úteis do prêmio inicial.',
        ],
        'first_coupon_pre_integralization_apply_index_factor' => [
            'parameter_field' => 'first_coupon_pre_integralization_apply_index_factor',
            'persisted' => true,
            'reason' => 'Flag contratual de aplicação do fator do índice.',
        ],
        'first_coupon_pre_integralization_apply_spread_factor' => [
            'parameter_field' => 'first_coupon_pre_integralization_apply_spread_factor',
            'persisted' => true,
            'reason' => 'Flag contratual de aplicação do fator de spread.',
        ],
        'legacy_projection_enabled' => [
            'parameter_field' => 'legacy_projection_enabled',
            'persisted' => true,
            'reason' => 'Controle do gate que impede projeções legadas nesta configuração.',
        ],
    ];

    /** @var array<string, int> */
    private const DECIMAL_SCALES = [
        'index_percentage' => 8,
        'spread_rate' => 8,
        'initial_unit_value' => 16,
    ];

    /** @var list<string> */
    private const DATE_FIELDS = ['curve_start_date', 'curve_end_date'];

    /** @var list<string> */
    private const INTEGER_FIELDS = [
        'business_day_basis',
        'index_rate_lag_business_days',
        'first_coupon_pre_integralization_business_days',
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = [
        'first_coupon_pre_integralization_premium_enabled',
        'first_coupon_pre_integralization_apply_index_factor',
        'first_coupon_pre_integralization_apply_spread_factor',
        'legacy_projection_enabled',
    ];

    public function __construct(
        private readonly PuBaselineReadinessService $readiness,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function dryRun(
        Emission $emission,
        ?CarbonImmutable $asOf = null,
    ): PuBaselineCandidatePersistenceResult {
        return $this->inspect($emission->fresh() ?? $emission, $asOf ?? CarbonImmutable::now());
    }

    public function write(
        Emission $emission,
        ?string $actorIdentifier,
        ?CarbonImmutable $asOf = null,
    ): PuBaselineCandidatePersistenceResult {
        $referenceDate = $asOf ?? CarbonImmutable::now();

        try {
            return DB::transaction(function () use ($emission, $actorIdentifier, $referenceDate): PuBaselineCandidatePersistenceResult {
                $lockedEmission = Emission::query()
                    ->whereKey($emission->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $inspection = $this->inspect($lockedEmission, $referenceDate, lockParameter: true);

                if (in_array($inspection->action, [
                    self::ACTION_CANDIDATE_NOT_READY,
                    self::ACTION_CANDIDATE_INVALID,
                ], true)) {
                    return $inspection;
                }

                [$actor, $actorAction, $actorReason] = $this->actorForWrite($actorIdentifier);

                if (! $actor instanceof User) {
                    return $inspection->withOutcome(
                        action: $actorAction,
                        reason: $actorReason,
                    );
                }

                if ($inspection->action !== self::ACTION_WILL_CREATE) {
                    return $inspection->withOutcome(
                        action: $inspection->action,
                        reason: $inspection->reason,
                        parameterId: $inspection->parameterId,
                        actorId: $actor->id,
                    );
                }

                $parameter = $lockedEmission->puParameter()->create($inspection->proposedConfiguration);
                $this->auditLog->logCandidateConfigurationCreated(
                    emission: $lockedEmission,
                    parameter: $parameter,
                    actor: $actor,
                    readinessStatus: $inspection->readinessStatus,
                    persistedFields: $inspection->proposedConfiguration,
                    nonPersistedProvenFields: $this->nonPersistedProvenFields($inspection),
                    candidateFingerprint: $inspection->candidateFingerprint,
                    provenance: $inspection->provenance,
                );

                $persistedInspection = $this->inspect(
                    $lockedEmission->fresh() ?? $lockedEmission,
                    $referenceDate,
                    lockParameter: true,
                );

                return $persistedInspection->withOutcome(
                    action: self::ACTION_CREATED,
                    reason: 'Configuração candidata criada de forma explícita e auditada.',
                    writes: 1,
                    parameterId: $parameter->id,
                    actorId: $actor->id,
                );
            });
        } catch (UniqueConstraintViolationException) {
            $inspection = $this->dryRun($emission, $referenceDate);

            if ($inspection->action === self::ACTION_WILL_CREATE) {
                return $inspection->withOutcome(
                    action: self::ACTION_CONFIGURATION_CONFLICT,
                    reason: 'A constraint única rejeitou uma criação concorrente; reavalie a configuração existente.',
                );
            }

            return $inspection;
        }
    }

    /**
     * @return list<array{candidate_field:string,parameter_field:?string,persisted:bool,reason:string}>
     */
    public function mapping(): array
    {
        $mapping = [];

        foreach (self::FIELD_MAPPING as $candidateField => $definition) {
            $mapping[] = ['candidate_field' => $candidateField, ...$definition];
        }

        return $mapping;
    }

    private function inspect(
        Emission $emission,
        CarbonImmutable $asOf,
        bool $lockParameter = false,
    ): PuBaselineCandidatePersistenceResult {
        $report = $this->readiness->evaluate($emission, $asOf);
        $parameterQuery = EmissionPuParameter::query()->whereBelongsTo($emission);

        if ($lockParameter) {
            $parameterQuery->lockForUpdate();
        }

        $existingParameter = $parameterQuery->first();
        $candidate = $this->canonicalCandidate($report->candidateConfiguration);
        $canonicalConfiguration = $this->candidateToCanonicalConfiguration($report->candidateConfiguration);
        $proposed = $this->canonicalConfigurationToPersistenceAttributes($canonicalConfiguration);
        $existing = $existingParameter instanceof EmissionPuParameter
            ? $this->parameterToCanonicalConfiguration($existingParameter)
            : null;
        $diff = $existing === null ? [] : $this->diff($existing, $canonicalConfiguration);
        $blockingRequirements = $this->blockingCandidateRequirements($report);
        $futureSnapshotWindow = $this->futureSnapshotWindow($report);
        $action = self::ACTION_WILL_CREATE;
        $reason = 'Nenhuma configuração existe e o candidato está pronto para criação explícita.';

        if ($report->status !== PuBaselineReadinessStatus::ReadyForCandidateConfiguration
            || $report->pendingFields !== []) {
            $action = self::ACTION_CANDIDATE_NOT_READY;
            $reason = 'O readiness oficial ainda não atingiu ready_for_candidate_configuration com pendingFields vazio.';
        } elseif (($candidateErrors = $this->candidateErrors($report->candidateConfiguration)) !== []) {
            $action = self::ACTION_CANDIDATE_INVALID;
            $reason = 'O candidato não é integralmente representável por EmissionPuParameter: '.implode('; ', $candidateErrors);
        } elseif ($existingParameter instanceof EmissionPuParameter && $diff === []) {
            $action = self::ACTION_ALREADY_MATCHES;
            $reason = 'A configuração existente já coincide exatamente com os campos materiais do candidato.';
        } elseif ($existingParameter instanceof EmissionPuParameter) {
            $action = self::ACTION_CONFIGURATION_CONFLICT;
            $reason = 'A configuração existente diverge do candidato documental e não será sobrescrita.';
        }

        return new PuBaselineCandidatePersistenceResult(
            emissionId: $emission->id,
            readinessStatus: $report->status->value,
            pendingFields: $report->pendingFields,
            action: $action,
            reason: $reason,
            candidate: $candidate,
            existingConfiguration: $existing,
            proposedConfiguration: $proposed,
            mapping: $this->mapping(),
            diff: $diff,
            blockingRequirements: $blockingRequirements,
            financialEffects: $this->financialEffects($emission, $existingParameter),
            provenance: $this->provenance($report, $futureSnapshotWindow),
            futureSnapshotWindow: $futureSnapshotWindow,
            candidateFingerprint: $this->fingerprint($candidate),
            parameterId: $existingParameter?->id,
        );
    }

    /** @return array{0:?User,1:string,2:string} */
    private function actorForWrite(?string $identifier): array
    {
        if (! filled($identifier)) {
            return [null, self::ACTION_ACTOR_REQUIRED, 'Um --actor=<id|email> explícito é obrigatório para write.'];
        }

        $normalizedIdentifier = trim((string) $identifier);
        $actorQuery = User::query()->lockForUpdate();
        $actor = ctype_digit($normalizedIdentifier)
            ? $actorQuery->whereKey((int) $normalizedIdentifier)->first()
            : $actorQuery->where('email', $normalizedIdentifier)->first();

        if (! $actor instanceof User) {
            return [null, self::ACTION_ACTOR_NOT_FOUND, 'O actor explícito não existe.'];
        }

        if (! $actor->isActive()) {
            return [null, self::ACTION_ACTOR_INACTIVE, 'O actor explícito está inativo.'];
        }

        if (! $actor->isApproved()) {
            return [null, self::ACTION_ACTOR_UNAPPROVED, 'O actor explícito ainda não foi aprovado.'];
        }

        if (! $actor->can(AccessPermission::PuParametersConfigure->value)) {
            return [null, self::ACTION_ACTOR_UNAUTHORIZED, sprintf(
                'O actor explícito não possui a permission %s.',
                AccessPermission::PuParametersConfigure->value,
            )];
        }

        return [$actor, '', ''];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function candidateErrors(array $candidate): array
    {
        $errors = [];
        $expectedFields = array_keys(self::FIELD_MAPPING);
        $missingFields = array_values(array_diff($expectedFields, array_keys($candidate)));
        $unsupportedFields = array_values(array_diff(array_keys($candidate), $expectedFields));

        if ($missingFields !== []) {
            $errors[] = 'campos ausentes: '.implode(', ', $missingFields);
        }

        if ($unsupportedFields !== []) {
            $errors[] = 'campos sem mapping explícito: '.implode(', ', $unsupportedFields);
        }

        $indexer = is_string($candidate['indexer'] ?? null)
            ? PuIndexer::tryFrom($candidate['indexer'])
            : null;
        $method = is_string($candidate['calculation_method'] ?? null)
            ? PuCalculationMethod::tryFrom($candidate['calculation_method'])
            : null;
        $lookupMode = is_string($candidate['index_rate_lookup_mode'] ?? null)
            ? PuIndexRateLookupMode::tryFrom($candidate['index_rate_lookup_mode'])
            : null;

        if (! $indexer instanceof PuIndexer) {
            $errors[] = 'indexer inválido';
        }

        if (! $method instanceof PuCalculationMethod
            || ($indexer instanceof PuIndexer && $method !== PuCalculationMethod::forIndexer($indexer))) {
            $errors[] = 'calculation_method inválido ou incompatível com indexer';
        }

        if ($indexer?->requiresIndexRates() && ! $lookupMode instanceof PuIndexRateLookupMode) {
            $errors[] = 'index_rate_lookup_mode obrigatório ou inválido';
        }

        foreach (self::DATE_FIELDS as $field) {
            if ($this->canonicalDate($candidate[$field] ?? null) === null) {
                $errors[] = $field.' precisa ser uma data ISO real';
            }
        }

        if ($this->canonicalDecimal($candidate['initial_unit_value'] ?? null, 16) === null) {
            $errors[] = 'initial_unit_value precisa ser decimal com escala compatível';
        }

        if ($indexer?->usesSpread()
            && $this->canonicalDecimal($candidate['spread_rate'] ?? null, 8) === null) {
            $errors[] = 'spread_rate precisa ser decimal com escala compatível';
        }

        if ($indexer === PuIndexer::Cdi
            && $this->canonicalDecimal($candidate['index_percentage'] ?? null, 8) !== '100.00000000') {
            $errors[] = 'index_percentage CDI precisa ser exatamente 100.00000000';
        }

        $basis = $this->canonicalInteger($candidate['business_day_basis'] ?? null);

        if ($basis === null || $basis <= 0 || $basis > 65535) {
            $errors[] = 'business_day_basis está fora do domínio unsigned smallint';
        }

        if (! is_string($candidate['calendar_code'] ?? null)
            || trim($candidate['calendar_code']) === ''
            || mb_strlen($candidate['calendar_code']) > 20) {
            $errors[] = 'calendar_code está vazio ou excede o schema';
        }

        $lag = $this->canonicalInteger($candidate['index_rate_lag_business_days'] ?? null);

        if ($indexer?->requiresIndexRates() && ($lag === null || $lag < -32768 || $lag > 32767)) {
            $errors[] = 'index_rate_lag_business_days está fora do domínio smallint';
        }

        foreach (self::BOOLEAN_FIELDS as $field) {
            if (! is_bool($candidate[$field] ?? null)) {
                $errors[] = $field.' precisa ser booleano';
            }
        }

        if (($candidate['first_coupon_pre_integralization_premium_enabled'] ?? null) === true) {
            $premiumDays = $this->canonicalInteger(
                $candidate['first_coupon_pre_integralization_business_days'] ?? null,
            );

            if ($premiumDays === null || $premiumDays < 1 || $premiumDays > 65535) {
                $errors[] = 'first_coupon_pre_integralization_business_days está fora do domínio unsigned smallint';
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $candidate */
    private function candidateToCanonicalConfiguration(array $candidate): array
    {
        $configuration = [];

        foreach (self::FIELD_MAPPING as $candidateField => $definition) {
            if (! $definition['persisted'] || $definition['parameter_field'] === null) {
                continue;
            }

            $rawValue = $candidate[$candidateField] ?? null;
            $canonicalValue = $this->canonicalValue($candidateField, $rawValue);
            $configuration[$candidateField] = match (true) {
                $candidateField === 'index_rate_lookup_mode' && $rawValue === null => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
                $candidateField === 'index_rate_lag_business_days' && $rawValue === null => 1,
                default => $canonicalValue ?? $rawValue,
            };
        }

        return $configuration;
    }

    /** @return array<string, mixed> */
    private function parameterToCanonicalConfiguration(EmissionPuParameter $parameter): array
    {
        $configuration = [];

        foreach (self::FIELD_MAPPING as $candidateField => $definition) {
            $parameterField = $definition['parameter_field'];

            if (! $definition['persisted'] || $parameterField === null) {
                continue;
            }

            $configuration[$candidateField] = $this->canonicalValue(
                $candidateField,
                $parameter->getAttribute($parameterField),
            );
        }

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $canonicalConfiguration
     * @return array<string, mixed>
     */
    private function canonicalConfigurationToPersistenceAttributes(array $canonicalConfiguration): array
    {
        $attributes = [];

        foreach (self::FIELD_MAPPING as $candidateField => $definition) {
            $parameterField = $definition['parameter_field'];

            if (! $definition['persisted'] || $parameterField === null) {
                continue;
            }

            $canonicalValue = $canonicalConfiguration[$candidateField] ?? null;
            $attributes[$parameterField] = $canonicalValue === self::PENDING_VALUE
                ? null
                : $canonicalValue;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $candidate
     * @return list<array{field:string,existing:mixed,candidate:mixed}>
     */
    private function diff(array $existing, array $candidate): array
    {
        $diff = [];

        foreach ($candidate as $field => $candidateValue) {
            $existingValue = $existing[$field] ?? null;

            if ($existingValue === $candidateValue) {
                continue;
            }

            $diff[] = [
                'field' => $field,
                'existing' => $existingValue,
                'candidate' => $candidateValue,
            ];
        }

        return $diff;
    }

    /** @return list<string> */
    private function blockingCandidateRequirements(PuBaselineReadinessReport $report): array
    {
        return collect($report->requirements)
            ->filter(fn (PuBaselineRequirement $requirement): bool => in_array(
                'candidate_configuration',
                $requirement->blocks,
                true,
            ) && ! $requirement->isSatisfied())
            ->keys()
            ->values()
            ->all();
    }

    /** @return array<string, bool|int|null> */
    private function financialEffects(
        Emission $emission,
        ?EmissionPuParameter $parameter,
    ): array {
        $curveCount = $emission->puDailyCurves()->count();
        $historyCount = $emission->puHistories()->count();
        $eventCount = $emission->puEvents()->count();
        $paymentCount = $emission->payments()->count();
        $validatedCurveVersionCount = EmissionPuCurveVersion::query()
            ->whereBelongsTo($emission)
            ->where('status', PuCurveStatus::Validated->value)
            ->count();
        $homologatedCurveVersionCount = EmissionPuCurveVersion::query()
            ->whereBelongsTo($emission)
            ->where('status', PuCurveStatus::Homologated->value)
            ->count();

        return [
            'existing_parameter_id' => $parameter?->id,
            'has_curves' => $curveCount > 0,
            'curve_count' => $curveCount,
            'has_history' => $historyCount > 0,
            'history_count' => $historyCount,
            'has_events' => $eventCount > 0,
            'event_count' => $eventCount,
            'has_payments' => $paymentCount > 0,
            'payment_count' => $paymentCount,
            'has_validated_curves' => $validatedCurveVersionCount > 0,
            'validated_curve_version_count' => $validatedCurveVersionCount,
            'has_homologated_curves' => $homologatedCurveVersionCount > 0,
            'homologated_curve_version_count' => $homologatedCurveVersionCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $futureSnapshotWindow
     * @return array<string, mixed>
     */
    private function provenance(
        PuBaselineReadinessReport $report,
        array $futureSnapshotWindow,
    ): array {
        $calendarYears = collect($report->calendarDiagnostics['years'] ?? [])
            ->map(fn (array $year): array => Arr::only($year, [
                'year', 'coverage_status', 'governance_status', 'checksum', 'review_state',
            ]))
            ->values()
            ->all();

        return [
            'candidate_source' => PuBaselineCandidateFactory::class,
            'readiness_source' => PuBaselineReadinessService::class,
            'candidate_fingerprint_semantics' => [
                'purpose' => 'diagnostic_candidate',
                'includes_pending_values' => $report->pendingFields !== [],
                'authorizes_persistence' => false,
            ],
            'confirmed_legal_fields' => collect($report->candidateFields)
                ->filter(fn (array $field): bool => in_array(
                    $field['status'] ?? null,
                    ['proven', 'governance_control'],
                    true,
                ))
                ->map(fn (array $field): array => Arr::only($field, [
                    'field', 'value', 'document_id', 'reference', 'validation_status',
                    'reviewer', 'reviewed_at', 'status',
                ]))
                ->values()
                ->all(),
            'approved_first_integralization_date' => $report
                ->requirement('first_integralization_date')
                ->evidence,
            'approved_index_source' => Arr::only($report->indexSourceDiagnostics, [
                'source_code', 'source_label', 'rate_source_value', 'workflow_status',
                'classification', 'artifact_checksum', 'report_checksum', 'review',
            ]),
            'confirmed_calendar' => [
                'calendar_code' => $report->calendarDiagnostics['calendar_code'] ?? null,
                'administratively_confirmed' => $report
                    ->calendarDiagnostics['administratively_confirmed'] ?? false,
                'confirmed_years' => collect($calendarYears)
                    ->where('governance_status', BusinessCalendarYear::STATUS_CONFIRMED)
                    ->pluck('year')
                    ->values()
                    ->all(),
                'years' => $calendarYears,
            ],
            'required_calendar_window' => [
                'resolvable' => $futureSnapshotWindow['resolvable'],
                'reason' => $futureSnapshotWindow['reason'],
                'curve_start_date' => $futureSnapshotWindow['curve_start_date'],
                'from' => $futureSnapshotWindow['calendar_required_from'],
                'to' => $futureSnapshotWindow['calendar_required_to'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function futureSnapshotWindow(PuBaselineReadinessReport $report): array
    {
        $requiredRateDates = array_values($report->rateWindow['required_rate_dates'] ?? []);
        $curveStartDate = $this->canonicalDate($report->rateWindow['curve_start_date'] ?? null);
        $calendarResolvable = (bool) ($report->calendarDiagnostics['resolvable'] ?? false);
        $rateWindowResolvable = (bool) ($report->rateWindow['resolvable'] ?? false);
        $resolvable = $curveStartDate !== null && $calendarResolvable && $rateWindowResolvable;
        $reason = match (true) {
            $curveStartDate === null => 'curve_start_date_pending',
            ! $calendarResolvable => 'calendar_window_unresolved',
            ! $rateWindowResolvable => 'rate_window_unresolved',
            default => null,
        };

        return [
            'resolvable' => $resolvable,
            'reason' => $reason,
            'curve_start_date' => $curveStartDate,
            'calendar_required_from' => $resolvable
                ? ($report->calendarDiagnostics['required_from'] ?? null)
                : null,
            'calendar_required_to' => $resolvable
                ? ($report->calendarDiagnostics['required_to'] ?? null)
                : null,
            'rate_required_from' => $resolvable ? ($requiredRateDates[0] ?? null) : null,
            'rate_required_to' => $resolvable
                ? ($requiredRateDates[array_key_last($requiredRateDates)] ?? null)
                : null,
            'required_rate_dates' => $resolvable ? $requiredRateDates : [],
            'first_rate_lookups' => $resolvable ? array_slice($requiredRateDates, 0, 10) : [],
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function canonicalCandidate(array $candidate): array
    {
        $canonical = [];

        foreach (array_keys(self::FIELD_MAPPING) as $field) {
            $rawValue = $candidate[$field] ?? null;
            $canonical[$field] = $this->canonicalValue($field, $rawValue) ?? $rawValue;
        }

        ksort($canonical);

        return $canonical;
    }

    private function canonicalValue(string $field, mixed $value): mixed
    {
        if (isset(self::DECIMAL_SCALES[$field])) {
            return $this->canonicalDecimal($value, self::DECIMAL_SCALES[$field]);
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return $this->canonicalDate($value);
        }

        if (in_array($field, self::INTEGER_FIELDS, true)) {
            return $this->canonicalInteger($value);
        }

        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return is_bool($value) ? $value : null;
        }

        return is_string($value) ? trim($value) : null;
    }

    private function canonicalDecimal(mixed $value, int $scale): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $decimal = trim((string) $value);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            return null;
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';
        $discarded = mb_substr($fraction, $scale);

        if ($discarded !== '' && trim($discarded, '0') !== '') {
            return null;
        }

        $fraction = str_pad(mb_substr($fraction, 0, $scale), $scale, '0');
        $isZero = $integer === '0' && trim($fraction, '0') === '';
        $sign = ($matches[1] ?? '') === '-' && ! $isZero ? '-' : '';

        return $scale === 0
            ? $sign.$integer
            : $sign.$integer.'.'.$fraction;
    }

    private function canonicalInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^[+-]?\d+$/', trim($value)) !== 1) {
            return null;
        }

        return (int) trim($value);
    }

    private function canonicalDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $canonicalCandidate */
    private function fingerprint(array $canonicalCandidate): string
    {
        ksort($canonicalCandidate);

        return hash('sha256', json_encode(
            $canonicalCandidate,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function nonPersistedProvenFields(
        PuBaselineCandidatePersistenceResult $result,
    ): array {
        return collect($result->mapping)
            ->where('persisted', false)
            ->map(fn (array $mapping): array => [
                'candidate_field' => $mapping['candidate_field'],
                'value' => $result->candidate[$mapping['candidate_field']] ?? null,
                'reason' => $mapping['reason'],
            ])
            ->values()
            ->all();
    }
}
