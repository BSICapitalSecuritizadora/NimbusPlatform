<?php

namespace App\Services;

use App\DTOs\Measurements\AuthorizedMeasurementCycleHistory;
use App\DTOs\Measurements\MeasurementCurrentWorkload;
use App\DTOs\Measurements\MeasurementCycleHistory;
use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\DTOs\Measurements\MeasurementCycleReportResult;
use App\DTOs\Measurements\MeasurementCycleReportRow;
use App\DTOs\Measurements\MeasurementCycleReportSummary;
use App\DTOs\Measurements\MeasurementHistoricalCoverage;
use App\DTOs\Measurements\MeasurementStageMetrics;
use App\DTOs\Measurements\MeasurementStageVisit;
use App\Enums\AccessPermission;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class MeasurementCycleReportingService
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private MeasurementCycleHistoryBatchReader $batchReader,
        private MeasurementCycleHistoryReadModel $historyReadModel,
        private MeasurementSlaService $sla,
        private MeasurementWorkflow $workflow,
    ) {}

    public function report(
        User $actor,
        MeasurementCycleReportFilters $filters,
        int $page = 1,
        int $perPage = 25,
    ): MeasurementCycleReportResult {
        $this->authorize($actor);
        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));
        $offset = ($page - 1) * $perPage;
        $coverage = ['complete' => 0, 'partial' => 0, 'insufficient' => 0];
        $stageAccumulators = $this->emptyStageAccumulators();
        $cycleDurations = [];
        $rows = [];
        $totalRows = 0;
        $operationOptions = [];
        $emissionOptions = [];
        $measurementOptions = [];
        $actorOptions = [];
        $responsibleOptions = [];

        foreach ($this->historyBatches($actor, $filters) as $batch) {
            $names = $this->userNamesFor($batch);

            foreach ($batch as $record) {
                $history = $record->history;
                $measurement = $record->measurement;
                $this->collectContextOptions(
                    $record,
                    $operationOptions,
                    $emissionOptions,
                    $measurementOptions,
                );

                $visitRows = collect($history->stageVisits)
                    ->map(fn (MeasurementStageVisit $visit): MeasurementCycleReportRow => $this->row(
                        $record,
                        $visit,
                        $names,
                    ));
                $matchingRows = $visitRows
                    ->filter(fn (MeasurementCycleReportRow $row): bool => $this->rowMatches($row, $filters))
                    ->values();

                foreach ($matchingRows as $row) {
                    $coverage[$row->completeness->value]++;

                    if ($row->actorId !== null && $row->actorName !== null) {
                        $actorOptions[$row->actorId] = $row->actorName;
                    }

                    if ($row->expectedResponsibleId !== null && $row->expectedResponsibleName !== null) {
                        $responsibleOptions[$row->expectedResponsibleId] = $row->expectedResponsibleName;
                    }

                    $this->accumulateStage($stageAccumulators[$row->stage], $row);

                    if ($totalRows >= $offset && count($rows) < $perPage) {
                        $rows[] = $row;
                    }

                    $totalRows++;
                }

                $matchesCycleDimensions = $visitRows->contains(
                    fn (MeasurementCycleReportRow $row): bool => $this->rowMatches(
                        $row,
                        $filters,
                        applyPeriod: false,
                    ),
                );

                if ($matchesCycleDimensions && $this->cycleIsEligible($history, $filters)) {
                    $cycleDurations[] = $history->cycleEnd->getTimestamp() - $history->cycleStart->getTimestamp();
                }
            }
        }

        $stageMetrics = collect(range(1, 5))
            ->map(fn (int $stage): MeasurementStageMetrics => $this->stageMetrics($stage, $stageAccumulators[$stage]))
            ->all();

        return new MeasurementCycleReportResult(
            summary: $this->summary($stageMetrics, $cycleDurations),
            coverage: new MeasurementHistoricalCoverage(...$coverage),
            stageMetrics: $stageMetrics,
            rows: $rows,
            totalRows: $totalRows,
            currentPage: $page,
            perPage: $perPage,
            workload: $this->currentWorkload($actor, $filters),
            operationOptions: $this->sortedOptions($operationOptions),
            emissionOptions: $this->sortedOptions($emissionOptions),
            measurementOptions: $this->sortedOptions($measurementOptions),
            actorOptions: $this->sortedOptions($actorOptions),
            responsibleOptions: $this->sortedOptions($responsibleOptions),
        );
    }

    /** @return Generator<int, MeasurementCycleReportRow> */
    public function stageVisitRows(User $actor, MeasurementCycleReportFilters $filters): Generator
    {
        $this->authorize($actor);

        foreach ($this->historyBatches($actor, $filters) as $batch) {
            $names = $this->userNamesFor($batch);

            foreach ($batch as $record) {
                foreach ($record->history->stageVisits as $visit) {
                    $row = $this->row($record, $visit, $names);

                    if ($this->rowMatches($row, $filters)) {
                        yield $row;
                    }
                }
            }
        }
    }

    public function detail(
        User $actor,
        int $measurementId,
        MeasurementCycleReportFilters $filters,
    ): MeasurementCycleHistory {
        $this->authorize($actor);

        $isInCohort = $this->applyStructuralFilters(
            Measurement::query()->visibleTo($actor),
            $filters,
        )->whereKey($measurementId)->exists();

        if (! $isInCohort) {
            throw (new ModelNotFoundException)->setModel(Measurement::class, [$measurementId]);
        }

        return $this->historyReadModel->for($actor, $measurementId);
    }

    /**
     * @return Generator<int, Collection<int, AuthorizedMeasurementCycleHistory>>
     */
    private function historyBatches(User $actor, MeasurementCycleReportFilters $filters): Generator
    {
        $idQuery = $this->applyStructuralFilters(
            Measurement::query()->visibleTo($actor),
            $filters,
        )->select('measurements.id');

        foreach ($idQuery
            ->reorder('measurements.id')
            ->lazyById(self::CHUNK_SIZE, column: 'measurements.id', alias: 'id')
            ->chunk(self::CHUNK_SIZE) as $idChunk) {
            $batch = $this->batchReader->read(
                $actor,
                collect($idChunk)->map(fn (Measurement $measurement): int => (int) $measurement->getKey()),
            );

            if ($batch->isNotEmpty()) {
                yield $batch;
            }
        }
    }

    /** @param Builder<Measurement> $query */
    private function applyStructuralFilters(
        Builder $query,
        MeasurementCycleReportFilters $filters,
    ): Builder {
        return $query
            ->when($filters->operationId !== null, fn (Builder $measurements): Builder => $measurements
                ->where('measurements.operation_id', $filters->operationId))
            ->when($filters->emissionId !== null, fn (Builder $measurements): Builder => $measurements
                ->whereHas('operation', fn (Builder $operations): Builder => $operations
                    ->where('operations.emission_id', $filters->emissionId)))
            ->when($filters->measurementId !== null, fn (Builder $measurements): Builder => $measurements
                ->whereKey($filters->measurementId));
    }

    /**
     * @param  Collection<int, AuthorizedMeasurementCycleHistory>  $batch
     * @return array<int, string>
     */
    private function userNamesFor(Collection $batch): array
    {
        $userIds = $batch
            ->flatMap(function (AuthorizedMeasurementCycleHistory $record): array {
                $ids = [];

                foreach ($record->history->stageVisits as $visit) {
                    $ids[] = $visit->exitActorId;
                    $ids[] = $visit->expectedResponsibleId;
                    $ids[] = $visit->delegatorId;
                }

                return $ids;
            })
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        return $userIds->isEmpty()
            ? []
            : User::query()->whereKey($userIds)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @param array<int, string> $names */
    private function row(
        AuthorizedMeasurementCycleHistory $record,
        MeasurementStageVisit $visit,
        array $names,
    ): MeasurementCycleReportRow {
        $measurement = $record->measurement;
        $operation = $measurement->operation;
        $emission = $operation?->emission;
        $completeness = MeasurementHistoryCompleteness::worst(
            $record->history->completeness,
            $visit->completeness,
        );

        return new MeasurementCycleReportRow(
            measurementId: (int) $measurement->getKey(),
            measurementLabel: $measurement->filename ?: 'Medição #'.$measurement->getKey(),
            operationId: (int) $measurement->operation_id,
            operationLabel: $this->operationLabel($operation),
            emissionId: $emission?->getKey() !== null ? (int) $emission->getKey() : null,
            emissionLabel: $this->emissionLabel($emission),
            referenceMonth: $measurement->reference_month?->toDateString(),
            stage: $visit->stage,
            sequence: $visit->sequence,
            enteredAt: $visit->enteredAt,
            exitedAt: $visit->exitedAt,
            exitReason: $visit->exitReason,
            calendarDuration: $visit->calendarDuration,
            pausedDuration: $visit->pausedDuration,
            activeDuration: $visit->activeDuration,
            actorId: $visit->exitActorId,
            actorName: $visit->exitActorId !== null ? ($names[$visit->exitActorId] ?? null) : null,
            responsibility: $visit->responsibility,
            expectedResponsibleId: $visit->expectedResponsibleId,
            expectedResponsibleName: $visit->expectedResponsibleId !== null
                ? ($names[$visit->expectedResponsibleId] ?? null)
                : null,
            delegated: $visit->delegated,
            delegatorId: $visit->delegatorId,
            delegatorName: $visit->delegatorId !== null ? ($names[$visit->delegatorId] ?? null) : null,
            adminOverride: $visit->adminOverride,
            completeness: $completeness,
            missingReasons: array_values(array_unique([
                ...$record->history->warnings,
                ...$visit->missingReasons,
            ])),
        );
    }

    private function rowMatches(
        MeasurementCycleReportRow $row,
        MeasurementCycleReportFilters $filters,
        bool $applyPeriod = true,
    ): bool {
        if ($filters->stage !== null && $row->stage !== $filters->stage) {
            return false;
        }

        if ($filters->decisionType !== null && $row->exitReason !== $filters->decisionType) {
            return false;
        }

        if ($filters->actorId !== null && $row->actorId !== $filters->actorId) {
            return false;
        }

        if ($filters->expectedResponsibleId !== null
            && $row->expectedResponsibleId !== $filters->expectedResponsibleId) {
            return false;
        }

        if ($filters->completeness !== null && $row->completeness !== $filters->completeness) {
            return false;
        }

        if ($applyPeriod && ($filters->periodFrom !== null || $filters->periodTo !== null)) {
            if ($row->exitedAt === null) {
                return false;
            }

            if ($filters->periodFrom !== null && $row->exitedAt < $filters->periodFrom) {
                return false;
            }

            if ($filters->periodTo !== null && $row->exitedAt > $filters->periodTo) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $accumulator */
    private function accumulateStage(array &$accumulator, MeasurementCycleReportRow $row): void
    {
        if (! $row->isDecision() || $row->exitedAt === null) {
            return;
        }

        $bucket = match ($row->completeness) {
            MeasurementHistoryCompleteness::Complete => 'complete',
            MeasurementHistoryCompleteness::Partial => 'partial',
            MeasurementHistoryCompleteness::Insufficient => 'insufficient',
        };
        $accumulator[$bucket]++;

        if ($row->completeness !== MeasurementHistoryCompleteness::Complete) {
            return;
        }

        if ($this->countsAsStageDecision($row)) {
            $accumulator['decisions']++;
        }

        match ($row->exitReason) {
            MeasurementStageExitReason::Approved => $accumulator['approvals']++,
            MeasurementStageExitReason::RejectedTerminal,
            MeasurementStageExitReason::ReturnedByRejection => $accumulator['rejections']++,
            MeasurementStageExitReason::Finalized => $accumulator['finalizations']++,
            MeasurementStageExitReason::ReturnedFromFinalization => $accumulator['finalization_returns']++,
            default => null,
        };

        if ($row->calendarDuration !== null) {
            $accumulator['calendar'][] = $row->calendarDuration;
        }

        if ($row->activeDuration !== null) {
            $accumulator['active'][] = $row->activeDuration;
        }

        if ($row->pausedDuration !== null) {
            $accumulator['paused'][] = $row->pausedDuration;
        }
    }

    private function countsAsStageDecision(MeasurementCycleReportRow $row): bool
    {
        return match ($row->exitReason) {
            MeasurementStageExitReason::Approved => $row->stage >= 1 && $row->stage <= 4,
            MeasurementStageExitReason::RejectedTerminal => $row->stage === 1,
            MeasurementStageExitReason::ReturnedByRejection => $row->stage >= 2 && $row->stage <= 4,
            default => false,
        };
    }

    /** @param array<string, mixed> $accumulator */
    private function stageMetrics(int $stage, array $accumulator): MeasurementStageMetrics
    {
        $stageDecisionDenominator = $accumulator['approvals'] + $accumulator['rejections'];
        $finalizationDenominator = $accumulator['finalizations'] + $accumulator['finalization_returns'];

        return new MeasurementStageMetrics(
            stage: $stage,
            decisions: $accumulator['decisions'],
            approvals: $accumulator['approvals'],
            rejections: $accumulator['rejections'],
            finalizations: $accumulator['finalizations'],
            finalizationReturns: $accumulator['finalization_returns'],
            rejectionRate: $stageDecisionDenominator > 0
                ? $accumulator['rejections'] / $stageDecisionDenominator
                : null,
            finalizationReturnRate: $finalizationDenominator > 0
                ? $accumulator['finalization_returns'] / $finalizationDenominator
                : null,
            averageCalendarDuration: $this->average($accumulator['calendar']),
            medianCalendarDuration: $this->median($accumulator['calendar']),
            averageActiveDuration: $this->average($accumulator['active']),
            medianActiveDuration: $this->median($accumulator['active']),
            pausedDurationTotal: array_sum($accumulator['paused']),
            averagePausedDuration: $this->average($accumulator['paused']),
            completeCohort: $accumulator['complete'],
            partialExcluded: $accumulator['partial'],
            insufficientExcluded: $accumulator['insufficient'],
        );
    }

    /**
     * @param  list<MeasurementStageMetrics>  $stageMetrics
     * @param  list<int>  $cycleDurations
     */
    private function summary(array $stageMetrics, array $cycleDurations): MeasurementCycleReportSummary
    {
        $stages = collect($stageMetrics);
        $decisions = $stages->where('stage', '<=', 4)->sum('decisions');
        $approvals = $stages->where('stage', '<=', 4)->sum('approvals');
        $rejections = $stages->where('stage', '<=', 4)->sum('rejections');
        $finalizations = $stages->sum('finalizations');
        $finalizationReturns = $stages->sum('finalizationReturns');
        $decisionDenominator = $approvals + $rejections;
        $finalizationDenominator = $finalizations + $finalizationReturns;

        return new MeasurementCycleReportSummary(
            decisions: (int) $decisions,
            approvals: (int) $approvals,
            rejections: (int) $rejections,
            rejectionRate: $decisionDenominator > 0 ? $rejections / $decisionDenominator : null,
            finalizationReturns: (int) $finalizationReturns,
            finalizations: (int) $finalizations,
            finalizationReturnRate: $finalizationDenominator > 0
                ? $finalizationReturns / $finalizationDenominator
                : null,
            pausedDurationTotal: (int) $stages->sum('pausedDurationTotal'),
            averageCycleDuration: $this->average($cycleDurations),
            medianCycleDuration: $this->median($cycleDurations),
            eligibleStageVisits: (int) $stages->sum('completeCohort'),
            eligibleCycles: count($cycleDurations),
        );
    }

    private function cycleIsEligible(
        MeasurementCycleHistory $history,
        MeasurementCycleReportFilters $filters,
    ): bool {
        if ($history->completeness !== MeasurementHistoryCompleteness::Complete
            || $history->cycleStart === null
            || $history->cycleEnd === null
            || $history->cycleEnd < $history->cycleStart) {
            return false;
        }

        return ($filters->periodFrom === null || $history->cycleEnd >= $filters->periodFrom)
            && ($filters->periodTo === null || $history->cycleEnd <= $filters->periodTo);
    }

    /** @return list<MeasurementCurrentWorkload> */
    private function currentWorkload(User $actor, MeasurementCycleReportFilters $filters): array
    {
        $aggregates = [];
        $query = $this->applyStructuralFilters(
            Measurement::query()->visibleTo($actor)->open(),
            $filters,
        )->with([
            'operation:id,emission_id,responsible_user_id,stage2_reviewer_user_id,stage3_reviewer_user_id,payment_manager_user_id,payment_receipt_uploader_user_id,payment_finalizer_user_id',
            'reviews:id,measurement_id,stage,status,paused_at,created_at',
            'pauses:id,measurement_id,stage,paused_at,resumed_at',
        ]);

        $query
            ->reorder('measurements.id')
            ->lazyById(self::CHUNK_SIZE, column: 'measurements.id', alias: 'id')
            ->chunk(self::CHUNK_SIZE)
            ->each(function (LazyCollection $chunk) use (&$aggregates, $filters): void {
                $measurements = $chunk->collect();
                $responsibleIds = $measurements
                    ->map(function (Measurement $measurement): ?int {
                        $responsibility = $this->currentResponsibility($measurement);

                        return $responsibility instanceof MeasurementResponsibility
                            ? $measurement->operation?->responsibleUserIdFor($responsibility)
                            : null;
                    })
                    ->filter()
                    ->unique();
                $names = $responsibleIds->isEmpty()
                    ? []
                    : User::query()->whereKey($responsibleIds)->pluck('name', 'id')->all();

                foreach ($measurements as $measurement) {
                    $responsibility = $this->currentResponsibility($measurement);

                    if (! $responsibility instanceof MeasurementResponsibility
                        || ($filters->stage !== null && $responsibility->stage() !== $filters->stage)) {
                        continue;
                    }

                    $responsibleId = $measurement->operation?->responsibleUserIdFor($responsibility);

                    if ($filters->expectedResponsibleId !== null
                        && $responsibleId !== $filters->expectedResponsibleId) {
                        continue;
                    }

                    $key = ($responsibleId ?? 0).':'.$responsibility->value;
                    $aggregates[$key] ??= [
                        'responsible_id' => $responsibleId,
                        'responsible_name' => $responsibleId !== null
                            ? ($names[$responsibleId] ?? 'Responsável não localizado')
                            : 'Não configurado',
                        'responsibility' => $responsibility,
                        'stage' => $responsibility->stage(),
                        'pending' => 0,
                        'overdue' => 0,
                    ];
                    $aggregates[$key]['pending']++;
                    $aggregates[$key]['overdue'] += $this->sla->evaluate($measurement)['status']
                        === MeasurementSlaService::STATUS_OVERDUE ? 1 : 0;
                }
            });

        return collect($aggregates)
            ->sortBy(fn (array $row): string => $row['responsible_name'].':'.$row['stage'])
            ->map(fn (array $row): MeasurementCurrentWorkload => new MeasurementCurrentWorkload(
                responsibleId: $row['responsible_id'],
                responsibleName: $row['responsible_name'],
                responsibility: $row['responsibility'],
                stage: $row['stage'],
                pendingCount: $row['pending'],
                overdueCount: $row['overdue'],
                delegatedCount: null,
            ))
            ->values()
            ->all();
    }

    private function currentResponsibility(Measurement $measurement): ?MeasurementResponsibility
    {
        return match ($measurement->status) {
            'pending', 'in_review' => MeasurementResponsibility::primaryForStage((int) $measurement->current_stage),
            'paused' => MeasurementResponsibility::primaryForStage($this->workflow->unifiedStage($measurement)),
            'awaiting_payment' => MeasurementResponsibility::PaymentManager,
            'awaiting_receipt' => MeasurementResponsibility::ReceiptUploader,
            'approved' => MeasurementResponsibility::Finalizer,
            default => null,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function emptyStageAccumulators(): array
    {
        return array_fill_keys(range(1, 5), [
            'decisions' => 0,
            'approvals' => 0,
            'rejections' => 0,
            'finalizations' => 0,
            'finalization_returns' => 0,
            'complete' => 0,
            'partial' => 0,
            'insufficient' => 0,
            'calendar' => [],
            'active' => [],
            'paused' => [],
        ]);
    }

    /** @param list<int> $values */
    private function average(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** @param list<int> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function operationLabel(?Operation $operation): string
    {
        if (! $operation instanceof Operation) {
            return 'Operação não disponível';
        }

        return collect([$operation->code, $operation->title])
            ->filter(fn (mixed $value): bool => filled($value))
            ->implode(' — ') ?: 'Operação #'.$operation->getKey();
    }

    private function emissionLabel(mixed $emission): string
    {
        if ($emission === null) {
            return 'Emissão não disponível';
        }

        $identifier = $emission->bsi_code ?? $emission->if_code ?? $emission->isin_code;

        return collect([$emission->name, $identifier])
            ->filter(fn (mixed $value): bool => filled($value))
            ->implode(' — ') ?: 'Emissão #'.$emission->getKey();
    }

    /**
     * @param  array<int, string>  $operationOptions
     * @param  array<int, string>  $emissionOptions
     * @param  array<int, string>  $measurementOptions
     */
    private function collectContextOptions(
        AuthorizedMeasurementCycleHistory $record,
        array &$operationOptions,
        array &$emissionOptions,
        array &$measurementOptions,
    ): void {
        $measurement = $record->measurement;
        $operation = $measurement->operation;
        $emission = $operation?->emission;
        $operationOptions[(int) $measurement->operation_id] = $this->operationLabel($operation);
        $measurementOptions[(int) $measurement->getKey()] = $measurement->filename ?: 'Medição #'.$measurement->getKey();

        if ($emission?->getKey() !== null) {
            $emissionOptions[(int) $emission->getKey()] = $this->emissionLabel($emission);
        }
    }

    /** @param array<int, string> $options @return array<int, string> */
    private function sortedOptions(array $options): array
    {
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can(AccessPermission::MeasurementsView->value)
            || ! $actor->can(AccessPermission::MeasurementsCycleReportsView->value)) {
            throw new AuthorizationException('Você não pode visualizar o relatório de ciclo de medições.');
        }
    }
}
