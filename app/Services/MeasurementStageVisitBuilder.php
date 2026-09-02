<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementCycleEvent;
use App\DTOs\Measurements\MeasurementPauseInterval;
use App\DTOs\Measurements\MeasurementStageVisit;
use App\DTOs\Measurements\MeasurementStageVisitBuildResult;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MeasurementStageVisitBuilder
{
    /**
     * @param  Collection<int, MeasurementCycleEvent>  $events
     * @param  Collection<int, MeasurementPauseInterval>  $pauses
     */
    public function build(Collection $events, Collection $pauses): MeasurementStageVisitBuildResult
    {
        $stageVisitEvents = $events
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->eventType->changesStageVisit());
        $ignoredEvents = $stageVisitEvents
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->completeness === MeasurementHistoryCompleteness::Insufficient);
        $orderedEvents = $stageVisitEvents
            ->reject(fn (MeasurementCycleEvent $event): bool => $event->completeness === MeasurementHistoryCompleteness::Insufficient)
            ->sort(fn (MeasurementCycleEvent $left, MeasurementCycleEvent $right): int => $this->compareEvents($left, $right))
            ->values();
        $records = [];
        $open = null;
        $sequences = [];
        $warnings = $ignoredEvents
            ->map(fn (MeasurementCycleEvent $event): string => 'insufficient_transition_ignored:'.($event->sourceActivityId ?? 'unknown'))
            ->values()
            ->all();
        $completeness = $ignoredEvents->isEmpty()
            ? MeasurementHistoryCompleteness::Complete
            : MeasurementHistoryCompleteness::Insufficient;

        foreach ($orderedEvents as $event) {
            switch ($event->eventType) {
                case MeasurementCycleEventType::Submitted:
                    $this->openStage(
                        $event,
                        $event->stageAfter,
                        $open,
                        $records,
                        $sequences,
                        $warnings,
                        $completeness,
                    );
                    break;
                case MeasurementCycleEventType::StageApproved:
                    $this->closeAndOpen(
                        $event,
                        MeasurementStageExitReason::Approved,
                        $event->stageAfter,
                        $open,
                        $records,
                        $sequences,
                        $warnings,
                        $completeness,
                    );
                    break;
                case MeasurementCycleEventType::StageRejected:
                    $this->closeAndOpen(
                        $event,
                        $event->stageBefore === 1
                            ? MeasurementStageExitReason::RejectedTerminal
                            : MeasurementStageExitReason::ReturnedByRejection,
                        $event->stageBefore === 1 ? null : $event->stageAfter,
                        $open,
                        $records,
                        $sequences,
                        $warnings,
                        $completeness,
                    );
                    break;
                case MeasurementCycleEventType::FinalizationReturned:
                    $this->closeAndOpen(
                        $event,
                        MeasurementStageExitReason::ReturnedFromFinalization,
                        $event->stageAfter,
                        $open,
                        $records,
                        $sequences,
                        $warnings,
                        $completeness,
                    );
                    break;
                case MeasurementCycleEventType::Finalized:
                    $this->closeAndOpen(
                        $event,
                        MeasurementStageExitReason::Finalized,
                        null,
                        $open,
                        $records,
                        $sequences,
                        $warnings,
                        $completeness,
                    );
                    break;
                default:
                    break;
            }
        }

        if (is_array($open)) {
            $open['exitReason'] = MeasurementStageExitReason::Open;
            $records[] = $open;
        }

        $this->associatePauses($records, $pauses, $warnings, $completeness);

        $visits = array_map(
            fn (array $record): MeasurementStageVisit => $this->toVisit($record),
            $records,
        );

        foreach ($visits as $visit) {
            $completeness = $completeness->combine($visit->completeness);
        }

        return new MeasurementStageVisitBuildResult(
            visits: $visits,
            completeness: $completeness,
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * @param  array<string, mixed>|null  $open
     * @param  list<array<string, mixed>>  $records
     * @param  array<int, int>  $sequences
     * @param  list<string>  $warnings
     */
    private function openStage(
        MeasurementCycleEvent $event,
        ?int $stage,
        ?array &$open,
        array &$records,
        array &$sequences,
        array &$warnings,
        MeasurementHistoryCompleteness &$completeness,
    ): void {
        if ($stage === null || $stage < 1 || $stage > 5) {
            $this->warn(
                $warnings,
                $completeness,
                'stage_visit_entry_unknown:'.($event->sourceActivityId ?? 'unknown'),
                MeasurementHistoryCompleteness::Insufficient,
            );

            return;
        }

        if (is_array($open)) {
            $open['completeness'] = $open['completeness']->combine(MeasurementHistoryCompleteness::Insufficient);
            $open['missingReasons'][] = 'new_stage_opened_before_previous_exit';
            $open['missingReasons'] = array_values(array_unique($open['missingReasons']));
            $open['exitReason'] = MeasurementStageExitReason::Unknown;
            $records[] = $open;
            $this->warn(
                $warnings,
                $completeness,
                'simultaneous_open_stage_visits',
                MeasurementHistoryCompleteness::Insufficient,
            );
        }

        $sequences[$stage] = ($sequences[$stage] ?? 0) + 1;
        $entryCompleteness = $event->completeness;
        $missingReasons = $event->missingReasons;

        if (! $event->occurredAt instanceof CarbonImmutable) {
            $entryCompleteness = $entryCompleteness->combine(MeasurementHistoryCompleteness::Insufficient);
            $missingReasons[] = 'stage_entry_time_unknown';
        }

        $open = [
            'measurementId' => $event->measurementId,
            'stage' => $stage,
            'sequence' => $sequences[$stage],
            'enteredAt' => $event->occurredAt,
            'exitedAt' => null,
            'exitReason' => MeasurementStageExitReason::Open,
            'decisionEvent' => null,
            'exitActorId' => null,
            'responsibility' => MeasurementResponsibility::primaryForStage($stage),
            'expectedResponsibleId' => null,
            'delegated' => null,
            'delegationId' => null,
            'delegatorId' => null,
            'delegationScope' => null,
            'adminOverride' => null,
            'revisionStart' => $event->workflowRevision,
            'revisionEnd' => null,
            'calendarDuration' => null,
            'pausedDuration' => null,
            'activeDuration' => null,
            'completeness' => $entryCompleteness,
            'missingReasons' => array_values(array_unique($missingReasons)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $open
     * @param  list<array<string, mixed>>  $records
     * @param  array<int, int>  $sequences
     * @param  list<string>  $warnings
     */
    private function closeAndOpen(
        MeasurementCycleEvent $event,
        MeasurementStageExitReason $exitReason,
        ?int $targetStage,
        ?array &$open,
        array &$records,
        array &$sequences,
        array &$warnings,
        MeasurementHistoryCompleteness &$completeness,
    ): void {
        $this->closeStage(
            $event,
            $exitReason,
            $open,
            $records,
            $sequences,
            $warnings,
            $completeness,
        );

        if ($targetStage !== null) {
            $this->openStage(
                $event,
                $targetStage,
                $open,
                $records,
                $sequences,
                $warnings,
                $completeness,
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $open
     * @param  list<array<string, mixed>>  $records
     * @param  array<int, int>  $sequences
     * @param  list<string>  $warnings
     */
    private function closeStage(
        MeasurementCycleEvent $event,
        MeasurementStageExitReason $exitReason,
        ?array &$open,
        array &$records,
        array &$sequences,
        array &$warnings,
        MeasurementHistoryCompleteness &$completeness,
    ): void {
        $stage = $event->stageBefore;

        if ($stage === null || $stage < 1 || $stage > 5) {
            $this->warn(
                $warnings,
                $completeness,
                'stage_visit_exit_unknown:'.($event->sourceActivityId ?? 'unknown'),
                MeasurementHistoryCompleteness::Insufficient,
            );

            return;
        }

        if (is_array($open) && (int) $open['stage'] !== $stage) {
            $open['completeness'] = $open['completeness']->combine(MeasurementHistoryCompleteness::Insufficient);
            $open['missingReasons'][] = 'decision_for_different_open_stage';
            $open['missingReasons'] = array_values(array_unique($open['missingReasons']));
            $open['exitReason'] = MeasurementStageExitReason::Unknown;
            $records[] = $open;
            $open = null;
            $this->warn(
                $warnings,
                $completeness,
                'decision_without_matching_open_visit:'.$stage,
                MeasurementHistoryCompleteness::Insufficient,
            );
        }

        if (! is_array($open)) {
            $sequences[$stage] = ($sequences[$stage] ?? 0) + 1;
            $records[] = $this->orphanExitRecord($event, $stage, $sequences[$stage], $exitReason);
            $this->warn(
                $warnings,
                $completeness,
                'decision_without_open_visit:'.$stage,
                MeasurementHistoryCompleteness::Insufficient,
            );

            return;
        }

        $open['exitedAt'] = $event->occurredAt;
        $open['exitReason'] = $exitReason;
        $open['decisionEvent'] = $event;
        $open['exitActorId'] = $event->actorId;
        $open['responsibility'] = $event->responsibility ?? $open['responsibility'];
        $open['expectedResponsibleId'] = $event->expectedResponsibleId;
        $open['delegated'] = $event->delegated;
        $open['delegationId'] = $event->delegationId;
        $open['delegatorId'] = $event->delegatorId;
        $open['delegationScope'] = $event->delegationScope;
        $open['adminOverride'] = $event->adminOverride;
        $open['revisionEnd'] = $event->workflowRevision;
        $open['completeness'] = $open['completeness']->combine($event->completeness);
        $open['missingReasons'] = array_values(array_unique([
            ...$open['missingReasons'],
            ...$event->missingReasons,
        ]));

        if (! $event->occurredAt instanceof CarbonImmutable) {
            $open['completeness'] = $open['completeness']->combine(MeasurementHistoryCompleteness::Insufficient);
            $open['missingReasons'][] = 'stage_exit_time_unknown';
        }

        $records[] = $open;
        $open = null;
    }

    /** @return array<string, mixed> */
    private function orphanExitRecord(
        MeasurementCycleEvent $event,
        int $stage,
        int $sequence,
        MeasurementStageExitReason $exitReason,
    ): array {
        return [
            'measurementId' => $event->measurementId,
            'stage' => $stage,
            'sequence' => $sequence,
            'enteredAt' => null,
            'exitedAt' => $event->occurredAt,
            'exitReason' => $exitReason,
            'decisionEvent' => $event,
            'exitActorId' => $event->actorId,
            'responsibility' => $event->responsibility ?? MeasurementResponsibility::primaryForStage($stage),
            'expectedResponsibleId' => $event->expectedResponsibleId,
            'delegated' => $event->delegated,
            'delegationId' => $event->delegationId,
            'delegatorId' => $event->delegatorId,
            'delegationScope' => $event->delegationScope,
            'adminOverride' => $event->adminOverride,
            'revisionStart' => null,
            'revisionEnd' => $event->workflowRevision,
            'calendarDuration' => null,
            'pausedDuration' => null,
            'activeDuration' => null,
            'completeness' => MeasurementHistoryCompleteness::Insufficient,
            'missingReasons' => array_values(array_unique([
                ...$event->missingReasons,
                'stage_entry_unknown',
            ])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  Collection<int, MeasurementPauseInterval>  $pauses
     * @param  list<string>  $warnings
     */
    private function associatePauses(
        array &$records,
        Collection $pauses,
        array &$warnings,
        MeasurementHistoryCompleteness &$completeness,
    ): void {
        $matchesByPause = [];

        foreach ($pauses->values() as $pauseIndex => $pause) {
            $completeness = $completeness->combine($pause->completeness);
            $matchesByPause[$pauseIndex] = [];

            foreach ($records as $visitIndex => $record) {
                if ($this->pauseIntersectsVisit($pause, $record)) {
                    $matchesByPause[$pauseIndex][] = $visitIndex;
                }
            }

            if ($matchesByPause[$pauseIndex] === []) {
                $this->warn(
                    $warnings,
                    $completeness,
                    'pause_not_associated:'.($pause->sourcePauseId ?? 'unknown'),
                    $pause->pausedAt instanceof CarbonImmutable
                        ? MeasurementHistoryCompleteness::Partial
                        : MeasurementHistoryCompleteness::Insufficient,
                );
            }

            if (count($matchesByPause[$pauseIndex]) > 1) {
                foreach ($matchesByPause[$pauseIndex] as $visitIndex) {
                    $this->degradeRecord($records[$visitIndex], 'pause_matches_multiple_visits');
                }

                $this->warn($warnings, $completeness, 'pause_visit_ambiguous');
            }
        }

        foreach ($records as $visitIndex => &$record) {
            $matchedPauses = collect($matchesByPause)
                ->filter(fn (array $visitIndexes): bool => in_array($visitIndex, $visitIndexes, true))
                ->keys()
                ->map(fn (int $pauseIndex): MeasurementPauseInterval => $pauses->values()->get($pauseIndex))
                ->values();

            $this->calculateDurations($record, $matchedPauses, $warnings, $completeness);
        }
        unset($record);
    }

    /** @param array<string, mixed> $record */
    private function pauseIntersectsVisit(MeasurementPauseInterval $pause, array $record): bool
    {
        if ((int) $record['stage'] !== $pause->stage
            || ! $record['enteredAt'] instanceof CarbonImmutable
            || ! $pause->pausedAt instanceof CarbonImmutable) {
            return false;
        }

        $visitEnd = $record['exitedAt'];

        if ($visitEnd instanceof CarbonImmutable && $pause->pausedAt >= $visitEnd) {
            return false;
        }

        return ! $pause->resumedAt instanceof CarbonImmutable
            || $pause->resumedAt > $record['enteredAt'];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  Collection<int, MeasurementPauseInterval>  $pauses
     * @param  list<string>  $warnings
     */
    private function calculateDurations(
        array &$record,
        Collection $pauses,
        array &$warnings,
        MeasurementHistoryCompleteness &$completeness,
    ): void {
        if (! $record['enteredAt'] instanceof CarbonImmutable
            || ! $record['exitedAt'] instanceof CarbonImmutable) {
            return;
        }

        $calendarDuration = $record['exitedAt']->getTimestamp() - $record['enteredAt']->getTimestamp();

        if ($calendarDuration < 0) {
            $this->degradeRecord($record, 'negative_calendar_duration', MeasurementHistoryCompleteness::Insufficient);
            $this->warn(
                $warnings,
                $completeness,
                'negative_calendar_duration',
                MeasurementHistoryCompleteness::Insufficient,
            );

            return;
        }

        $record['calendarDuration'] = $calendarDuration;
        $ranges = [];

        foreach ($pauses as $pause) {
            $record['completeness'] = $record['completeness']->combine($pause->completeness);
            $record['missingReasons'] = array_values(array_unique([
                ...$record['missingReasons'],
                ...$pause->missingReasons,
            ]));

            if (! $pause->pausedAt instanceof CarbonImmutable) {
                $this->degradeRecord($record, 'pause_start_unknown', MeasurementHistoryCompleteness::Insufficient);

                return;
            }

            if (! $pause->resumedAt instanceof CarbonImmutable) {
                $this->degradeRecord($record, 'open_pause_in_closed_visit');
                $this->warn($warnings, $completeness, 'open_pause_in_closed_visit');

                return;
            }

            if ($pause->resumedAt < $pause->pausedAt) {
                $this->degradeRecord($record, 'pause_interval_negative', MeasurementHistoryCompleteness::Insufficient);
                $this->warn(
                    $warnings,
                    $completeness,
                    'pause_interval_negative',
                    MeasurementHistoryCompleteness::Insufficient,
                );

                return;
            }

            if ($pause->pausedAt < $record['enteredAt'] || $pause->resumedAt > $record['exitedAt']) {
                $this->degradeRecord($record, 'pause_crosses_stage_visit_boundary');
                $this->warn($warnings, $completeness, 'pause_crosses_stage_visit_boundary');
            }

            $start = max($pause->pausedAt->getTimestamp(), $record['enteredAt']->getTimestamp());
            $end = min($pause->resumedAt->getTimestamp(), $record['exitedAt']->getTimestamp());

            if ($end > $start) {
                $ranges[] = [$start, $end];
            }
        }

        $pausedDuration = $this->unionDuration($ranges);
        $activeDuration = $calendarDuration - $pausedDuration;

        if ($activeDuration < 0) {
            $this->degradeRecord($record, 'negative_active_duration', MeasurementHistoryCompleteness::Insufficient);
            $this->warn(
                $warnings,
                $completeness,
                'negative_active_duration',
                MeasurementHistoryCompleteness::Insufficient,
            );

            return;
        }

        $record['pausedDuration'] = $pausedDuration;
        $record['activeDuration'] = $activeDuration;
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private function unionDuration(array $ranges): int
    {
        if ($ranges === []) {
            return 0;
        }

        usort($ranges, fn (array $left, array $right): int => $left[0] <=> $right[0]);
        [$currentStart, $currentEnd] = array_shift($ranges);
        $duration = 0;

        foreach ($ranges as [$start, $end]) {
            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);

                continue;
            }

            $duration += $currentEnd - $currentStart;
            $currentStart = $start;
            $currentEnd = $end;
        }

        return $duration + ($currentEnd - $currentStart);
    }

    /** @param array<string, mixed> $record */
    private function toVisit(array $record): MeasurementStageVisit
    {
        return new MeasurementStageVisit(
            measurementId: $record['measurementId'],
            stage: $record['stage'],
            sequence: $record['sequence'],
            enteredAt: $record['enteredAt'],
            exitedAt: $record['exitedAt'],
            exitReason: $record['exitReason'],
            decisionEvent: $record['decisionEvent'],
            exitActorId: $record['exitActorId'],
            responsibility: $record['responsibility'],
            expectedResponsibleId: $record['expectedResponsibleId'],
            delegated: $record['delegated'],
            delegationId: $record['delegationId'],
            delegatorId: $record['delegatorId'],
            delegationScope: $record['delegationScope'],
            adminOverride: $record['adminOverride'],
            revisionStart: $record['revisionStart'],
            revisionEnd: $record['revisionEnd'],
            calendarDuration: $record['calendarDuration'],
            pausedDuration: $record['pausedDuration'],
            activeDuration: $record['activeDuration'],
            completeness: $record['completeness'],
            missingReasons: array_values(array_unique($record['missingReasons'])),
        );
    }

    private function compareEvents(MeasurementCycleEvent $left, MeasurementCycleEvent $right): int
    {
        $timeComparison = ($left->occurredAt?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($right->occurredAt?->getTimestamp() ?? PHP_INT_MAX);

        return $timeComparison !== 0
            ? $timeComparison
            : (($left->sourceActivityId ?? PHP_INT_MAX) <=> ($right->sourceActivityId ?? PHP_INT_MAX));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function degradeRecord(
        array &$record,
        string $reason,
        MeasurementHistoryCompleteness $level = MeasurementHistoryCompleteness::Partial,
    ): void {
        $record['completeness'] = $record['completeness']->combine($level);
        $record['missingReasons'][] = $reason;
        $record['missingReasons'] = array_values(array_unique($record['missingReasons']));
    }

    /** @param list<string> $warnings */
    private function warn(
        array &$warnings,
        MeasurementHistoryCompleteness &$completeness,
        string $warning,
        MeasurementHistoryCompleteness $level = MeasurementHistoryCompleteness::Partial,
    ): void {
        $warnings[] = $warning;
        $warnings = array_values(array_unique($warnings));
        $completeness = $completeness->combine($level);
    }
}
