<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\DTOs\PuEmissionOperationalHealth;
use App\Domain\PuCalculator\DTOs\PuOfficialCurveStatus;
use App\Domain\PuCalculator\DTOs\PuOperationalCondition;
use App\Domain\PuCalculator\DTOs\PuOperationalSnapshot;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Domain\PuCalculator\Enums\PuMonitorRunStatus;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalEligibility;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuParameter;
use App\Models\EmissionPuReconciliation;
use App\Models\EmissionPuSettlementConflict;
use App\Models\IndexRate;
use App\Models\PuIndexSyncAttempt;
use App\Models\PuMonitorRun;
use App\Models\PuObligationRefreshRequest;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Retrato operacional do PU (Fase 6) -- a fonte única do monitor, do comando e
 * da futura tela.
 *
 * Não recalcula nada financeiro. Cada condição vem de quem já decide aquele
 * estado:
 *
 *  - curva oficial: {@see PuOfficialCurveFreshnessService} (Fases 3 e 4) -- o
 *    "nova versão necessária" de uma oficial que já completou o horizonte
 *    aprovado aparece aqui pela atualidade, não por uma marca que só a extensão
 *    grava (dívida D4);
 *  - divulgação do índice: {@see PuIndexPublicationPolicy} + as tentativas de
 *    sincronização registradas -- a falta antes da divulgação esperada não é
 *    incidente, e a falta sem nenhuma consulta à fonte depois dela é
 *    "aguardando sincronização", não "índice ausente";
 *  - obrigações, liquidação e conciliação: as colunas e o histórico da Fase 5
 *    (a diferença é a que a conciliação gravou, na mesma régua de 2 casas);
 *  - trabalho pendente: os pedidos duráveis de atualização das obrigações;
 *  - indexador: {@see PuIndexerCapabilityPolicy}.
 *
 * Cada verificação roda isolada: a que falha aparece como falhada no retrato
 * (e, por emissão, só a emissão que falhou), e o monitor não resolve nada que
 * dependia dela. Elegibilidade: emissão sem parâmetros não é acompanhada, e a
 * configurada sem curva oficial nunca vira incidente de "falta curva oficial".
 *
 * Consultas agregadas por verificação (sem N+1 de obrigações). A atualidade é
 * calculada só para emissões com curva oficial.
 */
final class PuOperationalHealthService
{
    /** @var list<string> */
    private const PU_JOB_CLASSES = [
        'GeneratePuDailyCurveJob',
        'ValidatePuCurveJob',
        'ExtendPuDailyCurveJob',
        'SyncIndexRatesFromBcbJob',
    ];

    private const MAX_IDS_IN_CONTEXT = 50;

    public function __construct(
        private readonly PuOfficialCurveFreshnessService $freshness,
        private readonly PuIndexerCapabilityPolicy $indexers,
        private readonly PuOperationalSeverityPolicy $severities,
        private readonly PuIndexPublicationPolicy $publication,
        private readonly PuIndexRateRequirementResolver $requirements,
        private readonly PuOperationalFailureClassifier $failures,
        private readonly BusinessDayCalendar $calendar,
    ) {}

    /**
     * @param  list<int>|null  $emissionIds  restringe às emissões informadas
     */
    public function snapshot(?CarbonInterface $now = null, ?array $emissionIds = null): PuOperationalSnapshot
    {
        $now = CarbonImmutable::instance($now ?? now());
        $checks = [];
        $emissions = $this->relevantEmissions($emissionIds);
        $officials = $this->latestVersions($emissions->keys()->all(), official: true);
        $attempts = $this->latestVersions($emissions->keys()->all(), official: false);
        $syncAttempts = $this->recentSyncAttempts();

        // Curva e índice por emissão.
        $curve = [];
        $failedCurveEmissions = [];

        foreach ($emissions as $emission) {
            try {
                $curve[$emission->id] = $this->curveHealth($emission, $officials->get($emission->id), $attempts->get($emission->id), $syncAttempts, $now);
            } catch (Throwable $exception) {
                report($exception);
                $failedCurveEmissions[] = (int) $emission->id;
                $curve[$emission->id] = null;
            }
        }

        $checks[PuOperationalConditionType::CHECK_CURVE] = [
            'status' => $failedCurveEmissions === [] ? 'ok' : 'partial',
            'failed_emission_ids' => $failedCurveEmissions,
        ];

        $operationalByIndexer = $this->operationalEmissionsByIndexer($emissions, $officials);

        [$obligations, $checks[PuOperationalConditionType::CHECK_OBLIGATIONS]] = $this->guarded(
            fn (): array => $this->obligationHealth($emissions->keys()->all(), $now, $emissions),
            ['counts' => [], 'conditions' => []],
        );
        [$refresh, $checks[PuOperationalConditionType::CHECK_REFRESH]] = $this->guarded(
            fn (): array => $this->refreshHealth($emissions->keys()->all(), $now),
            ['counts' => [], 'conditions' => [], 'last_succeeded' => []],
        );
        [$index, $checks[PuOperationalConditionType::CHECK_INDEX]] = $this->guarded(
            fn (): array => $this->indexHealth($syncAttempts, $operationalByIndexer, $emissions, $officials, $now),
            ['indexes' => [], 'conditions' => []],
        );
        [$system, $checks[PuOperationalConditionType::CHECK_SYSTEM]] = $this->guarded(
            fn (): array => $this->systemHealth(array_sum($operationalByIndexer)),
            ['summary' => [], 'conditions' => []],
        );

        $health = [];

        foreach ($emissions as $emission) {
            $id = (int) $emission->id;
            $curveResult = $curve[$id] ?? null;
            $parameter = $emission->puParameter;
            $conditions = [
                ...($curveResult['conditions'] ?? []),
                ...($obligations['conditions'][$id] ?? []),
                ...($refresh['conditions'][$id] ?? []),
            ];
            $official = $officials->get($id);

            $health[] = new PuEmissionOperationalHealth(
                emissionId: $id,
                emissionName: $emission->name,
                eligibility: $curveResult['eligibility'] ?? $this->eligibilityWithoutCurveCheck($emission, $official),
                indexer: $parameter?->indexer_enum,
                indexerOperational: $this->indexers->allows($parameter?->indexer_enum, PuIndexerCapability::Homologation),
                officialStatus: $curveResult['status'] ?? null,
                obligations: $obligations['counts'][$id] ?? [],
                refresh: $refresh['counts'][$id] ?? [],
                lastSuccessfulOperations: [
                    'homologated_at' => $official?->homologated_at?->toIso8601String(),
                    'last_extended_at' => $official?->last_extended_at?->toIso8601String(),
                    'obligation_refresh_succeeded_at' => $refresh['last_succeeded'][$id] ?? null,
                ],
                conditions: $conditions,
            );
        }

        return new PuOperationalSnapshot(
            evaluatedAt: $now,
            emissions: $health,
            indexes: $index['indexes'],
            systemConditions: [...$index['conditions'], ...$system['conditions'], ...($obligations['system_conditions'] ?? [])],
            checks: $checks,
            system: [
                ...$system['summary'],
                'operational_emissions' => $operationalByIndexer,
                'indexer_capabilities' => $this->indexers->matrix(),
                'monitor' => $this->monitorSummary(),
            ],
        );
    }

    /**
     * @template T of array
     *
     * @param  callable(): T  $check
     * @param  T  $empty
     * @return array{0: T, 1: array{status: string, error?: string}}
     */
    private function guarded(callable $check, array $empty): array
    {
        try {
            return [$check(), ['status' => 'ok']];
        } catch (Throwable $exception) {
            report($exception);

            return [$empty, ['status' => 'failed', 'error' => $this->failures->sanitize($exception->getMessage())]];
        }
    }

    /**
     * Emissões com algo a acompanhar: parâmetros de PU, versão operacional,
     * obrigação ou pedido de atualização aberto.
     *
     * @param  list<int>|null  $only
     * @return Collection<int, Emission>
     */
    private function relevantEmissions(?array $only): Collection
    {
        $ids = collect()
            ->merge(EmissionPuParameter::query()->pluck('emission_id'))
            ->merge(EmissionPuCurveVersion::query()->operational()->distinct()->pluck('emission_id'))
            ->merge(EmissionPuObligation::query()->distinct()->pluck('emission_id'))
            ->merge(PuObligationRefreshRequest::query()->open()->distinct()->pluck('emission_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique();

        if ($only !== null) {
            $ids = $ids->intersect(array_map('intval', $only));
        }

        return Emission::query()
            ->whereIn('id', $ids->values()->all())
            ->with('puParameter')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * A oficial (homologada operacional mais recente) ou a última tentativa
     * operacional de cada emissão, em duas consultas.
     *
     * @param  list<int>  $emissionIds
     * @return Collection<int, EmissionPuCurveVersion>
     */
    private function latestVersions(array $emissionIds, bool $official): Collection
    {
        if ($emissionIds === []) {
            return collect();
        }

        $ids = EmissionPuCurveVersion::query()
            ->operational()
            ->when($official, fn (Builder $query): Builder => $query->homologated())
            ->whereIn('emission_id', $emissionIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('emission_id')
            ->pluck('id');

        return EmissionPuCurveVersion::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (EmissionPuCurveVersion $version): int => (int) $version->emission_id);
    }

    /**
     * @param  Collection<int, PuIndexSyncAttempt>  $syncAttempts
     * @return array{eligibility: PuOperationalEligibility, status: ?PuOfficialCurveStatus, conditions: list<PuOperationalCondition>}
     */
    private function curveHealth(
        Emission $emission,
        ?EmissionPuCurveVersion $official,
        ?EmissionPuCurveVersion $latestAttempt,
        Collection $syncAttempts,
        CarbonImmutable $now,
    ): array {
        $id = (int) $emission->id;
        $parameter = $emission->puParameter;
        $indexer = $parameter?->indexer_enum;
        $conditions = [];
        $eligibility = $this->eligibilityWithoutCurveCheck($emission, $official);
        $inactive = $eligibility === PuOperationalEligibility::Inactive;

        if ($eligibility === PuOperationalEligibility::NotConfigured) {
            return ['eligibility' => $eligibility, 'status' => null, 'conditions' => []];
        }

        if ($official instanceof EmissionPuCurveVersion && ! $this->indexers->allows($this->indexers->versionIndexer($official), PuIndexerCapability::Homologation)) {
            $officialIndexer = $this->indexers->versionIndexer($official);
            $conditions[] = $this->condition(PuOperationalConditionType::UnsupportedIndexerOfficialCurve, sprintf(
                'A curva oficial %s usa o indexador %s, que não tem homologação operacional: o PU oficial não deveria existir. Invalide a versão; nenhuma obrigação é calculada a partir dela.',
                $official->calculation_version,
                $officialIndexer?->value ?? 'desconhecido',
            ), ['emission_id' => $id, 'curve_version_id' => (int) $official->id], ['indexer' => $officialIndexer?->value]);
        } elseif ($eligibility === PuOperationalEligibility::UnsupportedIndexer) {
            $conditions[] = $this->condition(PuOperationalConditionType::UnsupportedIndexer, sprintf(
                'O indexador %s não tem homologação operacional: geração, homologação e obrigações ficam bloqueadas (só simulação).',
                $indexer?->value ?? 'desconhecido',
            ), ['emission_id' => $id], ['indexer' => $indexer?->value]);
        }

        if ($eligibility === PuOperationalEligibility::ConfiguredNotLaunched) {
            $conditions[] = $this->condition(
                PuOperationalConditionType::NoOfficialCurve,
                'Configurada e ainda sem curva oficial: nada é publicado até a primeira homologação.',
                ['emission_id' => $id],
            );
        }

        if ($latestAttempt instanceof EmissionPuCurveVersion && $eligibility !== PuOperationalEligibility::UnsupportedIndexer) {
            $staleMinutes = (int) config('pu_calculator.stale_processing_minutes', 30);

            if ($latestAttempt->status === PuCurveStatus::Error) {
                $conditions[] = $this->condition(PuOperationalConditionType::CurveGenerationFailed, sprintf(
                    'A geração da versão %s falhou: %s',
                    $latestAttempt->calculation_version,
                    $this->failures->sanitize((string) $latestAttempt->error_message),
                ), ['emission_id' => $id, 'curve_version_id' => (int) $latestAttempt->id], ['emission_inactive' => $inactive]);
            } elseif ($latestAttempt->status === PuCurveStatus::Processing
                && $latestAttempt->updated_at !== null
                && $latestAttempt->updated_at->lt($now->subMinutes($staleMinutes))) {
                $conditions[] = $this->condition(PuOperationalConditionType::CurveGenerationStuck, sprintf(
                    'A versão %s está em processamento há mais de %d minutos: verifique o worker da fila.',
                    $latestAttempt->calculation_version,
                    $staleMinutes,
                ), ['emission_id' => $id, 'curve_version_id' => (int) $latestAttempt->id], ['emission_inactive' => $inactive]);
            } elseif (in_array($latestAttempt->status, [PuCurveStatus::Generated, PuCurveStatus::Validated, PuCurveStatus::Divergent], true)
                && $latestAttempt->id !== $official?->id) {
                $conditions[] = $this->condition(PuOperationalConditionType::CandidateAwaitingReview, sprintf(
                    'A versão %s (%s) aguarda revisão; só a homologação a torna oficial.',
                    $latestAttempt->calculation_version,
                    mb_strtolower($latestAttempt->status->label()),
                ), ['emission_id' => $id, 'curve_version_id' => (int) $latestAttempt->id]);
            }
        }

        $status = null;

        if ($official instanceof EmissionPuCurveVersion
            && in_array($eligibility, [PuOperationalEligibility::Operational, PuOperationalEligibility::Inactive], true)) {
            $status = $this->freshness->status($emission, $now);
            array_push($conditions, ...$this->officialConditions($emission, $official, $status, $syncAttempts, $now, $inactive));
        }

        return ['eligibility' => $eligibility, 'status' => $status, 'conditions' => $conditions];
    }

    /**
     * @param  Collection<int, PuIndexSyncAttempt>  $syncAttempts
     * @return list<PuOperationalCondition>
     */
    private function officialConditions(
        Emission $emission,
        EmissionPuCurveVersion $official,
        PuOfficialCurveStatus $status,
        Collection $syncAttempts,
        CarbonImmutable $now,
        bool $inactive,
    ): array {
        $scope = ['emission_id' => (int) $emission->id, 'curve_version_id' => (int) $official->id];
        $base = [
            'emission_inactive' => $inactive,
            'calculation_version' => $official->calculation_version,
            'freshness' => $status->freshness->value,
            'realized_through' => $status->realizedThrough?->toDateString(),
            'curve_end_date' => $status->curveEndDate?->toDateString(),
        ];

        return match ($status->freshness) {
            PuOfficialCurveFreshness::ReprocessingRequired => [$this->condition(
                PuOperationalConditionType::ReprocessingRequired,
                (string) ($status->reason ?? 'O passado da curva oficial mudou: ela exige reprocessamento por uma versão nova homologada.'),
                [...$scope, 'business_date' => $status->reprocessingFrom],
                [
                    ...$base,
                    'cause' => is_array($official->extension_divergence) ? ($official->extension_divergence['cause'] ?? 'official_history_changed') : 'contractual_input_changed',
                    'reprocessing_from' => $status->reprocessingFrom?->toDateString(),
                    'diverged_at' => $official->extension_diverged_at?->toIso8601String(),
                ],
            )],
            PuOfficialCurveFreshness::NewVersionRequired => [$this->condition(
                PuOperationalConditionType::NewVersionRequired,
                sprintf(
                    'A curva oficial %s chegou até %s e não responde pelo contrato a partir de %s: uma mudança contratual ainda não homologada exige nova versão. %s',
                    $official->calculation_version,
                    $status->realizedThrough?->format('d/m/Y') ?? '—',
                    $status->contractualChangeFrom?->format('d/m/Y') ?? '—',
                    (string) $status->reason,
                ),
                [...$scope, 'business_date' => $status->contractualChangeFrom],
                [...$base, 'contractual_change_from' => $status->contractualChangeFrom?->toDateString()],
            )],
            PuOfficialCurveFreshness::MissingIndex => [$this->condition(
                PuOperationalConditionType::OfficialCurveMissingIndex,
                (string) $status->reason,
                [...$scope, 'business_date' => $status->nextRequiredRateDate, 'indexer' => PuIndexer::Cdi->value],
                [...$base, ...$this->missingIndexContext($emission, $status, $syncAttempts)],
            )],
            PuOfficialCurveFreshness::ExtensionFailed => [$this->extensionFailedCondition($official, $status, $scope, $base)],
            PuOfficialCurveFreshness::Stale => [$this->condition(
                PuOperationalConditionType::OfficialCurveStale,
                (string) $status->reason,
                [...$scope, 'business_date' => $status->expectedRealizedThrough],
                [...$base, ...$this->staleContext($official, $now)],
            )],
            PuOfficialCurveFreshness::Current, PuOfficialCurveFreshness::Complete => $status->contractualChangeFrom !== null
                ? [$this->condition(
                    PuOperationalConditionType::ContractualChangePending,
                    sprintf('Mudança contratual ainda não homologada a partir de %s: a oficial avança só até a véspera.', $status->contractualChangeFrom->format('d/m/Y')),
                    [...$scope, 'business_date' => $status->contractualChangeFrom],
                    $base,
                )]
                : [],
            PuOfficialCurveFreshness::NotTracked => $official->extension_failed_at !== null
                ? [$this->extensionFailedCondition($official, $status, $scope, $base)]
                : [],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $base
     */
    private function extensionFailedCondition(EmissionPuCurveVersion $official, PuOfficialCurveStatus $status, array $scope, array $base): PuOperationalCondition
    {
        $failure = is_array($official->extension_failure) ? $official->extension_failure : [];

        return $this->condition(
            PuOperationalConditionType::OfficialExtensionFailed,
            sprintf(
                'A extensão da curva oficial %s falhou (%s): o PU oficial não avança além de %s. %s',
                $official->calculation_version,
                (string) ($failure['action'] ?? 'erro'),
                $status->realizedThrough?->format('d/m/Y') ?? '—',
                $this->failures->sanitize((string) ($failure['reason'] ?? $status->reason ?? '')),
            ),
            [...$scope, 'business_date' => $status->realizedThrough],
            [
                ...$base,
                'failed_since' => $official->extension_failed_at?->toIso8601String(),
                'failure_action' => $failure['action'] ?? null,
                'failure_category' => $failure['category'] ?? null,
                'retryable' => $failure['retryable'] ?? null,
                'consecutive_failures' => (int) ($failure['consecutive_failures'] ?? 1),
                'recovery' => 'A rotina diária tenta de novo; falha permanente exige corrigir a causa (pré-requisito, insumo) ou nova versão.',
            ],
        );
    }

    /**
     * Separa a observação que falta dentro do histórico, a que era esperada e
     * ninguém foi buscar, e a que a fonte não trouxe depois de consultada.
     *
     * @param  Collection<int, PuIndexSyncAttempt>  $syncAttempts
     * @return array<string, mixed>
     */
    private function missingIndexContext(Emission $emission, PuOfficialCurveStatus $status, Collection $syncAttempts): array
    {
        $next = $status->nextRequiredRateDate;
        $latest = $status->latestRealizedRateDate;

        if ($next === null) {
            return ['awaiting_synchronization' => false];
        }

        if ($latest !== null && $next->lt($latest)) {
            return ['historical_gap' => true, 'awaiting_synchronization' => false, 'missing_observation_date' => $next->toDateString()];
        }

        $calendar = $emission->puParameter instanceof EmissionPuParameter
            ? $this->requirements->observationCalendarCode($emission->puParameter)
            : BusinessCalendarRegistry::CDI_PUBLICATION_CALENDAR;
        $expectedAt = $this->publication->expectedAvailabilityAt($next, $calendar);
        $attempt = $this->firstFinishedSince($syncAttempts, PuIndexer::Cdi, $expectedAt);

        return [
            'historical_gap' => false,
            'missing_observation_date' => $next->toDateString(),
            'expected_available_at' => $expectedAt->toIso8601String(),
            'awaiting_synchronization' => ! $attempt instanceof PuIndexSyncAttempt,
            'sync_attempt_id' => $attempt?->id,
            'sync_outcome' => $attempt?->outcome?->value,
            'sync_finished_at' => $attempt?->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Desde quando há índice gravado que a oficial ainda não incorporou.
     *
     * @return array<string, mixed>
     */
    private function staleContext(EmissionPuCurveVersion $official, CarbonImmutable $now): array
    {
        $lastUsed = EmissionPuDailyCurve::query()
            ->where('curve_version_id', $official->id)
            ->whereNotNull('index_rate_date')
            ->max('index_rate_date');
        $staleSince = IndexRate::query()
            ->forIndexer(PuIndexer::Cdi)
            ->where('is_projected', false)
            ->when($lastUsed !== null, fn (Builder $query): Builder => $query->whereDate('rate_date', '>', CarbonImmutable::parse((string) $lastUsed)->toDateString()))
            ->min('created_at');
        $grace = (int) config('pu_calculator.monitoring.stale_grace_minutes', 360);
        $since = $staleSince !== null ? CarbonImmutable::parse((string) $staleSince) : null;

        return [
            'stale_since' => $since?->toIso8601String(),
            'stale_grace_minutes' => $grace,
            'beyond_grace' => $since !== null && $since->lt($now->subMinutes($grace)),
        ];
    }

    private function eligibilityWithoutCurveCheck(Emission $emission, ?EmissionPuCurveVersion $official): PuOperationalEligibility
    {
        $parameter = $emission->puParameter;

        if (! $parameter instanceof EmissionPuParameter && ! $official instanceof EmissionPuCurveVersion) {
            return PuOperationalEligibility::NotConfigured;
        }

        if (($official instanceof EmissionPuCurveVersion && ! $this->indexers->allows($this->indexers->versionIndexer($official), PuIndexerCapability::Homologation))
            || ($parameter instanceof EmissionPuParameter && ! $this->indexers->allows($parameter->indexer_enum, PuIndexerCapability::CurveGeneration))) {
            return PuOperationalEligibility::UnsupportedIndexer;
        }

        if ($emission->status !== 'active') {
            return PuOperationalEligibility::Inactive;
        }

        return $official instanceof EmissionPuCurveVersion
            ? PuOperationalEligibility::Operational
            : PuOperationalEligibility::ConfiguredNotLaunched;
    }

    /**
     * @param  Collection<int, Emission>  $emissions
     * @param  Collection<int, EmissionPuCurveVersion>  $officials
     * @return array<string, int>
     */
    private function operationalEmissionsByIndexer(Collection $emissions, Collection $officials): array
    {
        $counts = [];

        foreach ($emissions as $emission) {
            $official = $officials->get($emission->id);

            if ($this->eligibilityWithoutCurveCheck($emission, $official) !== PuOperationalEligibility::Operational) {
                continue;
            }

            $indexer = $emission->puParameter?->indexer_enum?->value ?? 'unknown';
            $counts[$indexer] = ($counts[$indexer] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Obrigações, liquidação e conciliação da Fase 5, em consultas agregadas.
     *
     * @param  list<int>  $emissionIds
     * @param  Collection<int, Emission>  $emissions
     * @return array{counts: array<int, array<string, int>>, conditions: array<int, list<PuOperationalCondition>>, system_conditions: list<PuOperationalCondition>}
     */
    private function obligationHealth(array $emissionIds, CarbonImmutable $now, Collection $emissions): array
    {
        $counts = [];
        $conditions = [];
        $systemConditions = [];
        $today = BusinessTime::dateString($now);
        $active = fn (): Builder => EmissionPuObligation::query()->active()->whereIn('emission_id', $emissionIds);

        foreach (['calculation_state', 'settlement_state', 'reconciliation_status'] as $column) {
            $rows = $active()->selectRaw("emission_id, {$column} as state, COUNT(*) as aggregate")->groupBy('emission_id', $column)->get();

            foreach ($rows as $row) {
                $counts[(int) $row->emission_id][$column.':'.$row->state] = (int) $row->aggregate;
            }
        }

        $superseded = EmissionPuObligation::query()
            ->whereIn('emission_id', $emissionIds)
            ->where('lifecycle_status', 'superseded')
            ->selectRaw('emission_id, COUNT(*) as aggregate')
            ->groupBy('emission_id')
            ->pluck('aggregate', 'emission_id');

        foreach ($superseded as $emissionId => $total) {
            $counts[(int) $emissionId]['lifecycle:superseded'] = (int) $total;
        }

        $inactive = fn (int $emissionId): bool => ($emissions->get($emissionId)?->status ?? 'active') !== 'active';

        // Vencidas sem liquidação: a liquidação só existe com fato registrado --
        // vencer não liquida, e liquidar a menor não deixa saldo.
        $overdue = $active()
            ->where('settlement_state', PuSettlementState::Unsettled->value)
            ->whereDate('due_date', '<', $today)
            ->orderBy('due_date')
            ->get(['id', 'emission_id', 'due_date'])
            ->groupBy('emission_id');

        foreach ($overdue as $emissionId => $rows) {
            $emissionId = (int) $emissionId;
            $counts[$emissionId]['unsettled_past_due'] = $rows->count();
            $oldest = CarbonImmutable::instance($rows->first()->due_date);
            $conditions[$emissionId][] = $this->condition(
                PuOperationalConditionType::ObligationsUnsettledPastDue,
                sprintf('%d obrigação(ões) vencida(s) sem liquidação registrada (a mais antiga em %s).', $rows->count(), $oldest->format('d/m/Y')),
                ['emission_id' => $emissionId, 'business_date' => $oldest],
                [
                    'obligation_ids' => $rows->take(self::MAX_IDS_IN_CONTEXT)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'count' => $rows->count(),
                    'business_date' => $today,
                    'emission_inactive' => false,
                ],
            );
        }

        $reconciliations = fn (Collection $obligations): Collection => EmissionPuReconciliation::query()
            ->whereIn('id', $obligations->pluck('latest_reconciliation_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $divergent = $active()->where('reconciliation_status', PuReconciliationStatus::Divergent->value)->orderBy('id')->get();
        $divergentResults = $reconciliations($divergent);

        foreach ($divergent as $obligation) {
            $result = $divergentResults->get($obligation->latest_reconciliation_id);
            $divergence = is_array($result?->divergence) ? $result->divergence : [];
            $kinds = (array) ($divergence['kinds'] ?? []);
            $conditions[(int) $obligation->emission_id][] = $this->condition(
                PuOperationalConditionType::SettlementDivergent,
                sprintf(
                    'Liquidação divergente do esperado em %s (%s): esperado %s, liquidado %s, diferença %s. A liquidação continua fechada; nada é ajustado.',
                    $obligation->due_date?->format('d/m/Y') ?? '—',
                    $kinds === [] ? 'sem detalhe' : implode(', ', $kinds),
                    $result?->expected_total ?? '—',
                    $result?->actual_total ?? '—',
                    $result?->difference ?? '—',
                ),
                ['emission_id' => (int) $obligation->emission_id, 'obligation_id' => (int) $obligation->id, 'business_date' => $obligation->due_date !== null ? CarbonImmutable::instance($obligation->due_date) : null],
                [
                    'reconciliation_id' => $result?->id,
                    'kinds' => $kinds,
                    'expected_total' => $result?->expected_total !== null ? (string) $result->expected_total : null,
                    'actual_total' => $result?->actual_total !== null ? (string) $result->actual_total : null,
                    'difference' => $result?->difference !== null ? (string) $result->difference : null,
                    'divergence' => $divergence,
                    'settlement_id' => $result?->settlement_id,
                    'calculation_id' => $result?->calculation_id,
                    'emission_inactive' => false,
                ],
            );
        }

        $indeterminate = $active()->where('reconciliation_status', PuReconciliationStatus::Indeterminate->value)->orderBy('id')->get();
        $indeterminateResults = $reconciliations($indeterminate);

        foreach ($indeterminate->groupBy('emission_id') as $emissionId => $rows) {
            $emissionId = (int) $emissionId;
            $reasons = $rows->map(fn (EmissionPuObligation $obligation): string => (string) ($indeterminateResults->get($obligation->latest_reconciliation_id)?->reason ?? 'unknown'))->countBy()->all();
            $conditions[$emissionId][] = $this->condition(
                PuOperationalConditionType::ReconciliationIndeterminate,
                sprintf('%d liquidação(ões) registrada(s) sem esperado confiável para conciliar (%s). A liquidação continua registrada; nenhum esperado fictício é usado.', $rows->count(), implode(', ', array_keys($reasons))),
                ['emission_id' => $emissionId],
                [
                    'obligation_ids' => $rows->take(self::MAX_IDS_IN_CONTEXT)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'reasons' => $reasons,
                    'settlement_ids' => $rows->take(self::MAX_IDS_IN_CONTEXT)->map(fn (EmissionPuObligation $obligation): ?int => $indeterminateResults->get($obligation->latest_reconciliation_id)?->settlement_id)->filter()->values()->all(),
                    'emission_inactive' => false,
                ],
            );
        }

        $unsupported = $active()->where('calculation_state', PuObligationCalculationState::Unsupported->value)->orderBy('id')->get(['id', 'emission_id', 'due_date', 'calculation_state_reason']);

        foreach ($unsupported->groupBy('emission_id') as $emissionId => $rows) {
            $emissionId = (int) $emissionId;
            $conditions[$emissionId][] = $this->condition(
                PuOperationalConditionType::ObligationUnsupportedEffect,
                sprintf('%d obrigação(ões) com efeito financeiro sem regra: o cálculo fica bloqueado (sem valor, nunca zero) até a regra contratual ser definida. %s', $rows->count(), (string) $rows->first()->calculation_state_reason),
                ['emission_id' => $emissionId],
                [
                    'obligation_ids' => $rows->take(self::MAX_IDS_IN_CONTEXT)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'reasons' => $rows->pluck('calculation_state_reason')->filter()->unique()->take(10)->values()->all(),
                    'retryable' => false,
                    'emission_inactive' => $inactive($emissionId),
                ],
            );
        }

        foreach ([
            PuObligationCalculationState::ReprocessingRequired->value => PuOperationalConditionType::ObligationReprocessingRequired,
            PuObligationCalculationState::AwaitingIndex->value => PuOperationalConditionType::ObligationAwaitingIndex,
        ] as $state => $type) {
            foreach ($counts as $emissionId => $emissionCounts) {
                $total = $emissionCounts['calculation_state:'.$state] ?? 0;

                if ($total > 0) {
                    $conditions[$emissionId][] = $this->condition($type, sprintf('%d obrigação(ões): %s.', $total, mb_strtolower($type->label())), ['emission_id' => $emissionId], ['count' => $total]);
                }
            }
        }

        // Conflito de liquidação: um incidente por conflito, até a decisão governada.
        $conflicts = EmissionPuSettlementConflict::query()->open()->whereIn('emission_id', $emissionIds)->orderBy('id')->get();

        foreach ($conflicts as $conflict) {
            $conditions[(int) $conflict->emission_id][] = $this->condition(
                PuOperationalConditionType::SettlementConflictOpen,
                sprintf(
                    'Conflito de liquidação (%s) detectado em %s aguarda decisão: a liquidação existente não é sobrescrita e nenhuma é escolhida pela ordem de chegada.',
                    $conflict->kind->label(),
                    $conflict->detected_at?->format('d/m/Y H:i') ?? '—',
                ),
                ['emission_id' => (int) $conflict->emission_id, 'obligation_id' => (int) $conflict->obligation_id, 'settlement_conflict_id' => (int) $conflict->id],
                [
                    'kind' => $conflict->kind->value,
                    'existing_settlement_id' => $conflict->existing_settlement_id,
                    'source' => $conflict->source->value,
                    'external_reference' => $conflict->external_reference,
                    'detected_at' => $conflict->detected_at?->toIso8601String(),
                    'status' => $conflict->status->value,
                    'operator_action_required' => true,
                    'emission_inactive' => false,
                ],
            );
            $counts[(int) $conflict->emission_id]['settlement_conflicts_open'] = ($counts[(int) $conflict->emission_id]['settlement_conflicts_open'] ?? 0) + 1;
        }

        return ['counts' => $counts, 'conditions' => $conditions, 'system_conditions' => $systemConditions];
    }

    /**
     * Pedidos duráveis de atualização das obrigações ainda abertos.
     *
     * @param  list<int>  $emissionIds
     * @return array{counts: array<int, array<string, int>>, conditions: array<int, list<PuOperationalCondition>>, last_succeeded: array<int, string>}
     */
    private function refreshHealth(array $emissionIds, CarbonImmutable $now): array
    {
        $counts = [];
        $conditions = [];
        $stalledAfter = (int) config('pu_calculator.monitoring.refresh_stalled_after_minutes', 30);
        $open = PuObligationRefreshRequest::query()->open()->whereIn('emission_id', $emissionIds)->orderBy('id')->get();

        foreach ($open->groupBy('emission_id') as $emissionId => $requests) {
            $emissionId = (int) $emissionId;
            $byStatus = $requests->groupBy(fn (PuObligationRefreshRequest $request): string => $request->status->value);

            foreach ($byStatus as $status => $rows) {
                $counts[$emissionId][(string) $status] = $rows->count();
            }

            $describe = fn (Collection $rows): array => [
                'request_ids' => $rows->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'triggers' => $rows->pluck('trigger')->unique()->values()->all(),
                'attempts' => (int) $rows->max('attempts'),
                'last_error_category' => $rows->last()?->last_error_category?->value,
                'last_error_message' => $rows->last()?->last_error_message,
                'correlation_ids' => $rows->pluck('correlation_id')->take(10)->all(),
            ];

            if (($rows = $byStatus->get(PuObligationRefreshStatus::Exhausted->value)) !== null) {
                $conditions[$emissionId][] = $this->condition(
                    PuOperationalConditionType::ObligationRefreshExhausted,
                    sprintf('A atualização das obrigações esgotou as %d tentativas automáticas (%s). Retome com pu:obligations:recover --retry depois de tratar a causa.', (int) $rows->max('attempts'), (string) $rows->last()?->last_error_category?->label()),
                    ['emission_id' => $emissionId],
                    [...$describe($rows), 'recovery' => 'manual_retry_required'],
                );
            }

            if (($rows = $byStatus->get(PuObligationRefreshStatus::Blocked->value)) !== null) {
                $conditions[$emissionId][] = $this->condition(
                    PuOperationalConditionType::ObligationRefreshBlocked,
                    sprintf('A atualização das obrigações falhou de forma permanente (%s) e não se repete sozinha: trate a causa e retome com autorização.', (string) $rows->last()?->last_error_category?->label()),
                    ['emission_id' => $emissionId],
                    [...$describe($rows), 'recovery' => 'fix_cause_then_manual_retry'],
                );
            }

            if (($rows = $byStatus->get(PuObligationRefreshStatus::RetryScheduled->value)) !== null) {
                $conditions[$emissionId][] = $this->condition(
                    PuOperationalConditionType::ObligationRefreshRetrying,
                    sprintf('A atualização das obrigações falhou (%s) e será repetida em %s.', (string) $rows->last()?->last_error_category?->label(), $rows->min('next_attempt_at')?->format('d/m/Y H:i') ?? '—'),
                    ['emission_id' => $emissionId],
                    [...$describe($rows), 'next_attempt_at' => $rows->min('next_attempt_at')?->toIso8601String(), 'recovery' => 'automatic_retry'],
                );
            }

            $stalled = $requests->filter(fn (PuObligationRefreshRequest $request): bool => ($request->status === PuObligationRefreshStatus::Pending
                    && $request->requested_at !== null
                    && $request->requested_at->lt($now->subMinutes($stalledAfter)))
                || ($request->status === PuObligationRefreshStatus::Running
                    && $request->claim_expires_at !== null
                    && $request->claim_expires_at->lt($now)));
            $pending = $requests->filter(fn (PuObligationRefreshRequest $request): bool => in_array($request->status, [PuObligationRefreshStatus::Pending, PuObligationRefreshStatus::Running], true))
                ->reject(fn (PuObligationRefreshRequest $request): bool => $stalled->contains('id', $request->id));

            if ($stalled->isNotEmpty()) {
                $conditions[$emissionId][] = $this->condition(
                    PuOperationalConditionType::ObligationRefreshStalled,
                    sprintf('%d pedido(s) de atualização das obrigações sem execução há mais de %d minutos: verifique a varredura (pu:obligations:recover) e o agendador.', $stalled->count(), $stalledAfter),
                    ['emission_id' => $emissionId],
                    [...$describe($stalled), 'recovery' => 'scanner'],
                );
            }

            if ($pending->isNotEmpty()) {
                $conditions[$emissionId][] = $this->condition(
                    PuOperationalConditionType::ObligationRefreshPending,
                    sprintf('%d pedido(s) de atualização das obrigações em andamento.', $pending->count()),
                    ['emission_id' => $emissionId],
                    $describe($pending),
                );
            }
        }

        $lastSucceeded = PuObligationRefreshRequest::query()
            ->whereIn('emission_id', $emissionIds)
            ->where('status', PuObligationRefreshStatus::Succeeded->value)
            ->selectRaw('emission_id, MAX(completed_at) as completed_at')
            ->groupBy('emission_id')
            ->pluck('completed_at', 'emission_id')
            ->map(fn ($value): string => CarbonImmutable::parse((string) $value)->toIso8601String())
            ->all();

        return ['counts' => $counts, 'conditions' => $conditions, 'last_succeeded' => $lastSucceeded];
    }

    /**
     * Sincronização e divulgação por indexador.
     *
     * @param  Collection<int, PuIndexSyncAttempt>  $syncAttempts
     * @param  array<string, int>  $operationalByIndexer
     * @param  Collection<int, Emission>  $emissions
     * @param  Collection<int, EmissionPuCurveVersion>  $officials
     * @return array{indexes: list<array<string, mixed>>, conditions: list<PuOperationalCondition>}
     */
    private function indexHealth(Collection $syncAttempts, array $operationalByIndexer, Collection $emissions, Collection $officials, CarbonImmutable $now): array
    {
        $indexes = [];
        $conditions = [];

        foreach ([PuIndexer::Cdi, PuIndexer::Ipca] as $indexer) {
            $attempts = $syncAttempts->filter(fn (PuIndexSyncAttempt $attempt): bool => $attempt->indexer === $indexer->value)->values();
            $finished = $attempts->filter(fn (PuIndexSyncAttempt $attempt): bool => $attempt->finished_at !== null)->values();
            $latest = $finished->first();
            $consecutive = 0;

            foreach ($finished as $attempt) {
                if (! in_array($attempt->outcome, [PuIndexSyncOutcome::Failed, PuIndexSyncOutcome::PartialFailure], true)) {
                    break;
                }

                $consecutive++;
            }

            $operational = (int) ($operationalByIndexer[$indexer->value] ?? 0);
            $context = ['operational_emissions' => $operational];

            if ($latest instanceof PuIndexSyncAttempt && in_array($latest->outcome, [PuIndexSyncOutcome::Failed, PuIndexSyncOutcome::PartialFailure], true)) {
                $conditions[] = $this->condition(
                    PuOperationalConditionType::IndexSyncFailed,
                    sprintf(
                        'A sincronização do %s %s em %s (%s): %s',
                        $indexer->value,
                        $latest->outcome === PuIndexSyncOutcome::PartialFailure ? 'falhou em parte' : 'falhou',
                        $latest->finished_at?->format('d/m/Y H:i') ?? '—',
                        $latest->failure_category?->label() ?? 'parcial',
                        (string) $latest->error_message,
                    ),
                    ['indexer' => $indexer->value],
                    [
                        ...$context,
                        'attempt_id' => $latest->id,
                        'outcome' => $latest->outcome?->value,
                        'failure_category' => $latest->failure_category?->value,
                        'retryable' => $latest->failure_category?->isRetryable() ?? true,
                        'consecutive_failures' => max(1, $consecutive),
                    ],
                );
            }

            if ($latest instanceof PuIndexSyncAttempt && $latest->conflicts > 0) {
                $conditions[] = $this->condition(
                    PuOperationalConditionType::IndexRateConflict,
                    sprintf('A fonte informou %d valor(es) de %s diferentes dos já registrados: nada foi sobrescrito; a revisão só entra por correção governada (pu:index-rates:correct).', $latest->conflicts, $indexer->value),
                    ['indexer' => $indexer->value],
                    [...$context, 'attempt_id' => $latest->id, 'conflicts' => $latest->conflicts],
                );
            }

            $expectation = $indexer === PuIndexer::Cdi && $operational > 0
                ? $this->cdiExpectation($attempts, $emissions, $officials, $now, $context)
                : ['summary' => [], 'conditions' => []];
            array_push($conditions, ...$expectation['conditions']);

            $indexes[] = [
                'indexer' => $indexer->value,
                'operational_emissions' => $operational,
                'latest_observation_date' => $this->latestObservation($indexer),
                'last_attempt' => $latest instanceof PuIndexSyncAttempt ? [
                    'id' => $latest->id,
                    'started_at' => $latest->started_at?->toIso8601String(),
                    'finished_at' => $latest->finished_at?->toIso8601String(),
                    'outcome' => $latest->outcome?->value,
                    'failure_category' => $latest->failure_category?->value,
                    'created' => $latest->created,
                    'conflicts' => $latest->conflicts,
                    'invalid_entries' => $latest->invalid_entries,
                ] : null,
                'last_successful_attempt_at' => $finished->first(fn (PuIndexSyncAttempt $attempt): bool => $attempt->outcome?->reachedProvider() === true && $attempt->outcome !== PuIndexSyncOutcome::PartialFailure)?->finished_at?->toIso8601String(),
                'consecutive_failures' => $consecutive,
                // Tentativa começada e não terminada: em andamento, ou um processo que
                // morreu no meio (a próxima tentativa agendada a substitui).
                'in_progress_attempt_started_at' => $attempts->first(fn (PuIndexSyncAttempt $attempt): bool => $attempt->finished_at === null)?->started_at?->toIso8601String(),
                ...$expectation['summary'],
            ];
        }

        return ['indexes' => $indexes, 'conditions' => $conditions];
    }

    /**
     * O CDI que já deveria estar no banco, no calendário de divulgação das
     * emissões em operação, e o que foi feito para buscá-lo.
     *
     * @param  Collection<int, PuIndexSyncAttempt>  $attempts
     * @param  Collection<int, Emission>  $emissions
     * @param  Collection<int, EmissionPuCurveVersion>  $officials
     * @param  array<string, int>  $context
     * @return array{summary: array<string, mixed>, conditions: list<PuOperationalCondition>}
     */
    private function cdiExpectation(Collection $attempts, Collection $emissions, Collection $officials, CarbonImmutable $now, array $context): array
    {
        $calendar = BusinessCalendarRegistry::CDI_PUBLICATION_CALENDAR;

        foreach ($emissions as $emission) {
            if ($emission->puParameter?->indexer_enum === PuIndexer::Cdi
                && $this->eligibilityWithoutCurveCheck($emission, $officials->get($emission->id)) === PuOperationalEligibility::Operational) {
                $calendar = $this->requirements->observationCalendarCode($emission->puParameter);

                break;
            }
        }

        $expectedLatest = $this->publication->expectedLatestRateDate($calendar, $now);
        $latestStored = $this->latestObservation(PuIndexer::Cdi);
        $summary = [
            'publication_calendar' => $calendar,
            'expected_latest_observation_date' => $expectedLatest->toDateString(),
            'awaiting_synchronization' => false,
        ];

        if ($latestStored !== null && $latestStored >= $expectedLatest->toDateString()) {
            return ['summary' => $summary, 'conditions' => []];
        }

        $missing = $latestStored === null
            ? $expectedLatest
            : $this->calendarNextBusinessDay(CarbonImmutable::parse($latestStored), $calendar);
        $expectedAt = $this->publication->expectedAvailabilityAt($missing, $calendar);
        $attempt = $this->firstFinishedSince($attempts, PuIndexer::Cdi, $expectedAt);
        $summary = [...$summary, 'missing_observation_date' => $missing->toDateString(), 'expected_available_at' => $expectedAt->toIso8601String()];
        $scope = ['indexer' => PuIndexer::Cdi->value, 'business_date' => $missing];

        if (! $attempt instanceof PuIndexSyncAttempt) {
            $overdue = (int) config('pu_calculator.monitoring.index_sync_overdue_minutes', 180);
            $summary['awaiting_synchronization'] = true;
            $type = $expectedAt->addMinutes($overdue)->lt($now)
                ? PuOperationalConditionType::IndexSynchronizationOverdue
                : PuOperationalConditionType::IndexAwaitingSynchronization;

            return ['summary' => $summary, 'conditions' => [$this->condition(
                $type,
                sprintf(
                    'O CDI de %s é esperado desde %s (horário de Brasília) e nenhuma sincronização terminou depois disso%s.',
                    $missing->format('d/m/Y'),
                    BusinessTime::at($expectedAt)->format('d/m/Y H:i'),
                    $type === PuOperationalConditionType::IndexSynchronizationOverdue ? sprintf(' (há mais de %d minutos): verifique o agendador', $overdue) : '',
                ),
                $scope,
                [...$context, 'expected_available_at' => $expectedAt->toIso8601String()],
            )]];
        }

        if ($attempt->outcome === PuIndexSyncOutcome::Failed) {
            // A falha da consulta já é a condição (sincronização falhou).
            return ['summary' => $summary, 'conditions' => []];
        }

        return ['summary' => $summary, 'conditions' => [$this->condition(
            PuOperationalConditionType::IndexObservationMissing,
            sprintf(
                'O CDI de %s era esperado desde %s e a fonte, consultada em %s, não o trouxe (%s). Nenhum CDI é repetido nem projetado no lugar.',
                $missing->format('d/m/Y'),
                BusinessTime::at($expectedAt)->format('d/m/Y H:i'),
                $attempt->finished_at !== null ? BusinessTime::at($attempt->finished_at)->format('d/m/Y H:i') : '—',
                $attempt->outcome?->label() ?? '—',
            ),
            $scope,
            [...$context, 'expected_available_at' => $expectedAt->toIso8601String(), 'sync_attempt_id' => $attempt->id, 'sync_outcome' => $attempt->outcome?->value],
        )]];
    }

    private function calendarNextBusinessDay(CarbonImmutable $date, string $calendar): CarbonImmutable
    {
        return $this->calendar->shiftBusinessDays($date, 1, $calendar);
    }

    /**
     * Fila e jobs do PU.
     *
     * @return array{summary: array<string, mixed>, conditions: list<PuOperationalCondition>}
     */
    private function systemHealth(int $operationalEmissions): array
    {
        $failedByJob = [];

        if (Schema::hasTable('failed_jobs')) {
            foreach (self::PU_JOB_CLASSES as $job) {
                $count = (int) DB::table('failed_jobs')->where('payload', 'like', '%'.$job.'%')->count();

                if ($count > 0) {
                    $failedByJob[$job] = $count;
                }
            }
        }

        $conditions = $failedByJob === [] ? [] : [$this->condition(
            PuOperationalConditionType::QueuedJobsFailed,
            sprintf('%d job(s) do PU com falha na fila (%s): verifique `php artisan queue:failed`.', array_sum($failedByJob), implode(', ', array_keys($failedByJob))),
            [],
            ['failed_jobs' => $failedByJob, 'operational_emissions' => $operationalEmissions],
        )];

        return [
            'summary' => [
                'failed_pu_jobs' => $failedByJob,
                'pending_jobs' => Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0,
            ],
            'conditions' => $conditions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorSummary(): array
    {
        $last = PuMonitorRun::query()->latest('id')->first();
        $lastSucceeded = PuMonitorRun::query()->where('status', PuMonitorRunStatus::Succeeded->value)->latest('id')->first();

        return [
            'last_run_id' => $last?->id,
            'last_run_status' => $last?->status?->value ?? 'never_ran',
            'last_run_finished_at' => $last?->finished_at?->toIso8601String(),
            'last_succeeded_at' => $lastSucceeded?->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, PuIndexSyncAttempt>
     */
    private function recentSyncAttempts(): Collection
    {
        return PuIndexSyncAttempt::query()->latest('id')->limit(200)->get();
    }

    /**
     * @param  Collection<int, PuIndexSyncAttempt>  $attempts
     */
    private function firstFinishedSince(Collection $attempts, PuIndexer $indexer, CarbonImmutable $since): ?PuIndexSyncAttempt
    {
        return $attempts->first(fn (PuIndexSyncAttempt $attempt): bool => $attempt->indexer === $indexer->value
            && $attempt->finished_at !== null
            && $attempt->finished_at->greaterThanOrEqualTo($since));
    }

    private function latestObservation(PuIndexer $indexer): ?string
    {
        $latest = IndexRate::query()->forIndexer($indexer)->where('is_projected', false)->max('rate_date');

        return $latest !== null ? CarbonImmutable::parse((string) $latest)->toDateString() : null;
    }

    /**
     * @param  array{emission_id?: int, curve_version_id?: int, obligation_id?: int, settlement_conflict_id?: int, indexer?: string|null, business_date?: CarbonInterface|null}  $scope
     * @param  array<string, mixed>  $context
     */
    private function condition(PuOperationalConditionType $type, string $reason, array $scope, array $context = []): PuOperationalCondition
    {
        $businessDate = $scope['business_date'] ?? null;

        return new PuOperationalCondition(
            type: $type,
            severity: $this->severities->severity($type, $context),
            reason: trim($reason),
            emissionId: $scope['emission_id'] ?? null,
            curveVersionId: $scope['curve_version_id'] ?? null,
            obligationId: $scope['obligation_id'] ?? null,
            settlementConflictId: $scope['settlement_conflict_id'] ?? null,
            indexer: $scope['indexer'] ?? ($context['indexer'] ?? null),
            businessDate: $businessDate instanceof CarbonInterface ? CarbonImmutable::instance($businessDate)->startOfDay() : null,
            context: $context,
        );
    }
}
