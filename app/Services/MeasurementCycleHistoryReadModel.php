<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementCycleEvent;
use App\DTOs\Measurements\MeasurementCycleHistory;
use App\DTOs\Measurements\MeasurementCyclePayment;
use App\DTOs\Measurements\MeasurementPauseInterval;
use App\DTOs\Measurements\MeasurementStageVisit;
use App\Enums\AccessPermission;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Enums\MeasurementStageExitReason;
use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class MeasurementCycleHistoryReadModel
{
    public function __construct(
        private MeasurementCycleEventNormalizer $normalizer,
        private MeasurementStageVisitBuilder $visitBuilder,
    ) {}

    public function for(User $actor, Measurement|int $measurement): MeasurementCycleHistory
    {
        $this->authorize($actor);

        $measurementId = $measurement instanceof Measurement
            ? (int) $measurement->getKey()
            : $measurement;

        $authorizedMeasurement = Measurement::query()
            ->visibleTo($actor)
            ->whereKey($measurementId)
            ->with([
                'reviews' => fn (HasMany $reviews): HasMany => $reviews
                    ->select([
                        'id',
                        'measurement_id',
                        'stage',
                        'reviewer_user_id',
                        'status',
                        'reviewed_at',
                    ])
                    ->orderBy('stage'),
                'pauses' => fn (HasMany $pauses): HasMany => $pauses
                    ->select([
                        'id',
                        'measurement_id',
                        'stage',
                        'paused_by',
                        'pause_reason',
                        'paused_at',
                        'resumed_at',
                        'resumed_by',
                    ])
                    ->orderBy('paused_at')
                    ->orderBy('id'),
                'payments' => fn (HasMany $payments): HasMany => $payments
                    ->select([
                        'id',
                        'operation_id',
                        'measurement_id',
                        'pay_date',
                        'amount',
                        'method',
                        'created_by',
                        'created_at',
                        'receipt_uploaded_by',
                        'receipt_uploaded_at',
                    ])
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->firstOrFail([
                'id',
                'operation_id',
                'reference_month',
                'filename',
                'status',
                'current_stage',
                'analyzed_by',
                'analyzed_at',
            ]);

        $activities = $this->activitiesFor($authorizedMeasurement);

        return $this->projectAuthorizedLoadedMeasurement($authorizedMeasurement, $activities);
    }

    /**
     * Projects a Measurement that was already intersected with visibleTo() and
     * loaded with reviews, pauses and payments by a trusted read layer.
     *
     * @param  Collection<int, Activity>  $activities
     */
    public function projectAuthorizedLoadedMeasurement(
        Measurement $authorizedMeasurement,
        Collection $activities,
    ): MeasurementCycleHistory {
        $events = $this->normalizer->normalizeMany($activities, $authorizedMeasurement);
        [$events, $paymentWarnings] = $this->correlatePayments($events, $authorizedMeasurement->payments);
        $pauses = $authorizedMeasurement->pauses
            ->map(fn (MeasurementPause $pause): MeasurementPauseInterval => $this->pauseInterval($pause))
            ->values();
        [$pauseWarnings, $pauseCrossCheckCompleteness] = $this->crossCheckPauseActivities($events, $pauses);
        $payments = $authorizedMeasurement->payments
            ->map(fn (MeasurementPayment $payment): MeasurementCyclePayment => $this->paymentContext($payment))
            ->values();
        $visitResult = $this->visitBuilder->build($events, $pauses);
        [$stageVisits, $currentStateCompleteness, $currentStateWarnings] = $this->reconcileVisitsWithCurrentState(
            $authorizedMeasurement,
            $visitResult->visits,
        );
        [$cycleStart, $cycleEnd, $terminalReason, $cycleCompleteness, $cycleWarnings] = $this->cycleBounds(
            $authorizedMeasurement,
            $events,
        );

        $completeness = MeasurementHistoryCompleteness::worst(
            $cycleCompleteness,
            $visitResult->completeness,
            $pauseCrossCheckCompleteness,
            $currentStateCompleteness,
            ...$events->map(fn (MeasurementCycleEvent $event): MeasurementHistoryCompleteness => $event->completeness)->all(),
        );
        $warnings = array_values(array_unique([
            ...$paymentWarnings,
            ...$pauseWarnings,
            ...$visitResult->warnings,
            ...$currentStateWarnings,
            ...$cycleWarnings,
            ...$events->flatMap(fn (MeasurementCycleEvent $event): array => $event->missingReasons)->all(),
        ]));

        return new MeasurementCycleHistory(
            measurementId: (int) $authorizedMeasurement->getKey(),
            operationId: (int) $authorizedMeasurement->operation_id,
            referenceMonth: $authorizedMeasurement->reference_month?->toDateString(),
            measurementLabel: $authorizedMeasurement->filename,
            currentStatus: (string) $authorizedMeasurement->status,
            currentStage: (int) $authorizedMeasurement->current_stage,
            events: $events->all(),
            stageVisits: $stageVisits,
            pauses: $pauses->all(),
            payments: $payments->all(),
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd,
            terminalReason: $terminalReason,
            completeness: $completeness,
            warnings: $warnings,
        );
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can(AccessPermission::MeasurementsView->value)
            || ! $actor->can(AccessPermission::MeasurementsCycleReportsView->value)) {
            throw new AuthorizationException('Você não pode visualizar o histórico de ciclo de medições.');
        }
    }

    /** @return Collection<int, Activity> */
    private function activitiesFor(Measurement $measurement): Collection
    {
        return Activity::query()
            ->where('subject_type', $measurement->getMorphClass())
            ->where('subject_id', $measurement->getKey())
            // A trilha de atributos da medição saiu de `default` e passou a
            // gravar em `measurements`, que é a categoria retida por sete anos.
            // O balde genérico continua na lista porque atividades anteriores à
            // correção ainda vivem nele.
            ->whereIn('log_name', [
                'measurement_workflow',
                'measurements',
                config('activitylog.default_log_name', 'default'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'log_name',
                'description',
                'subject_type',
                'subject_id',
                'causer_type',
                'causer_id',
                'event',
                'properties',
                'created_at',
            ]);
    }

    /**
     * @param  Collection<int, MeasurementCycleEvent>  $events
     * @param  Collection<int, MeasurementPayment>  $payments
     * @return array{0: Collection<int, MeasurementCycleEvent>, 1: list<string>}
     */
    private function correlatePayments(Collection $events, Collection $payments): array
    {
        $paymentIds = $payments
            ->pluck('id')
            ->map(fn (mixed $paymentId): int => (int) $paymentId)
            ->all();
        $warnings = [];

        $correlated = $events->map(function (MeasurementCycleEvent $event) use ($paymentIds, &$warnings): MeasurementCycleEvent {
            if (! in_array($event->eventType, [
                MeasurementCycleEventType::PaymentRegistered,
                MeasurementCycleEventType::ReceiptAttached,
                MeasurementCycleEventType::ReceiptDeleted,
            ], true)) {
                return $event;
            }

            foreach ($event->paymentIds as $paymentId) {
                if (in_array($paymentId, $paymentIds, true)) {
                    continue;
                }

                $reason = 'payment_row_missing:'.$paymentId;
                $warnings[] = $reason;
                $event = $event->withMissingReason($reason);
            }

            return $event;
        });

        return [$correlated, array_values(array_unique($warnings))];
    }

    private function pauseInterval(MeasurementPause $pause): MeasurementPauseInterval
    {
        $missingReasons = [];
        $completeness = MeasurementHistoryCompleteness::Complete;
        $pausedAt = $this->immutableDate($pause->paused_at);
        $resumedAt = $this->immutableDate($pause->resumed_at);

        if (! $pausedAt instanceof CarbonImmutable) {
            $missingReasons[] = 'pause_start_unknown';
            $completeness = MeasurementHistoryCompleteness::Insufficient;
        }

        if ($pausedAt instanceof CarbonImmutable
            && $resumedAt instanceof CarbonImmutable
            && $resumedAt < $pausedAt) {
            $missingReasons[] = 'pause_interval_negative';
            $completeness = MeasurementHistoryCompleteness::Insufficient;
        }

        return new MeasurementPauseInterval(
            sourcePauseId: $pause->getKey() !== null ? (int) $pause->getKey() : null,
            sourceType: MeasurementHistorySourceType::TableFallback,
            measurementId: (int) $pause->measurement_id,
            stage: (int) $pause->stage,
            pausedAt: $pausedAt,
            resumedAt: $resumedAt,
            pausedById: $pause->paused_by !== null ? (int) $pause->paused_by : null,
            resumedById: $pause->resumed_by !== null ? (int) $pause->resumed_by : null,
            reason: $pause->pause_reason,
            completeness: $completeness,
            missingReasons: $missingReasons,
        );
    }

    private function paymentContext(MeasurementPayment $payment): MeasurementCyclePayment
    {
        return new MeasurementCyclePayment(
            id: (int) $payment->getKey(),
            measurementId: (int) $payment->measurement_id,
            amount: (string) $payment->amount,
            payDate: $this->immutableDate($payment->pay_date),
            method: $payment->method,
            createdById: $payment->created_by !== null ? (int) $payment->created_by : null,
            createdAt: $this->immutableDate($payment->created_at),
        );
    }

    /**
     * @param  list<MeasurementStageVisit>  $visits
     * @return array{0: list<MeasurementStageVisit>, 1: MeasurementHistoryCompleteness, 2: list<string>}
     */
    private function reconcileVisitsWithCurrentState(Measurement $measurement, array $visits): array
    {
        if ($visits === []) {
            return [$visits, MeasurementHistoryCompleteness::Complete, []];
        }

        $lastIndex = array_key_last($visits);
        $lastVisit = $visits[$lastIndex];

        if (! in_array($measurement->status, Measurement::CLOSED_STATUSES, true)) {
            if ($lastVisit->exitReason === MeasurementStageExitReason::Open
                && $lastVisit->stage === (int) $measurement->current_stage) {
                return [$visits, MeasurementHistoryCompleteness::Complete, []];
            }

            return [
                $visits,
                MeasurementHistoryCompleteness::Insufficient,
                ['stage_visit_chain_conflicts_with_current_state'],
            ];
        }

        $expectedStage = $measurement->status === 'finalized' ? 5 : 1;
        $expectedReason = $measurement->status === 'finalized'
            ? MeasurementStageExitReason::Finalized
            : MeasurementStageExitReason::RejectedTerminal;

        if ($lastVisit->exitReason === $expectedReason && $lastVisit->stage === $expectedStage) {
            return [$visits, MeasurementHistoryCompleteness::Complete, []];
        }

        $analyzedAt = $this->immutableDate($measurement->analyzed_at);

        if ($lastVisit->exitReason === MeasurementStageExitReason::Open
            && $lastVisit->stage === $expectedStage
            && $lastVisit->enteredAt instanceof CarbonImmutable
            && $analyzedAt instanceof CarbonImmutable
            && $analyzedAt >= $lastVisit->enteredAt) {
            $visits[$lastIndex] = $lastVisit->withFallbackExit(
                exitedAt: $analyzedAt,
                exitReason: $expectedReason,
                exitActorId: $measurement->analyzed_by !== null ? (int) $measurement->analyzed_by : null,
                missingReason: 'stage_visit_exit_from_current_state_fallback',
            );

            return [
                $visits,
                MeasurementHistoryCompleteness::Partial,
                ['stage_visit_exit_from_current_state_fallback'],
            ];
        }

        return [
            $visits,
            MeasurementHistoryCompleteness::Insufficient,
            ['stage_visit_chain_conflicts_with_terminal_current_state'],
        ];
    }

    /**
     * @param  Collection<int, MeasurementCycleEvent>  $events
     * @param  Collection<int, MeasurementPauseInterval>  $pauses
     * @return array{0: list<string>, 1: MeasurementHistoryCompleteness}
     */
    private function crossCheckPauseActivities(Collection $events, Collection $pauses): array
    {
        $warnings = [];
        $completeness = MeasurementHistoryCompleteness::Complete;

        foreach ($events as $event) {
            $pauseDateField = match ($event->eventType) {
                MeasurementCycleEventType::StagePaused => 'pausedAt',
                MeasurementCycleEventType::StageResumed => 'resumedAt',
                default => null,
            };

            if ($pauseDateField === null
                || ! $event->occurredAt instanceof CarbonImmutable
                || $event->stageBefore === null) {
                continue;
            }

            $matched = $pauses->contains(function (MeasurementPauseInterval $pause) use ($event, $pauseDateField): bool {
                $pauseAt = $pause->{$pauseDateField};

                return $pause->stage === $event->stageBefore
                    && $pauseAt instanceof CarbonImmutable
                    && abs($pauseAt->getTimestamp() - $event->occurredAt->getTimestamp()) <= 5;
            });

            if (! $matched) {
                $warnings[] = $event->eventType === MeasurementCycleEventType::StagePaused
                    ? 'pause_activity_without_table_row'
                    : 'resume_activity_without_table_row';
                $completeness = $completeness->combine(MeasurementHistoryCompleteness::Partial);
            }
        }

        return [array_values(array_unique($warnings)), $completeness];
    }

    /**
     * @param  Collection<int, MeasurementCycleEvent>  $events
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: ?MeasurementStageExitReason, 3: MeasurementHistoryCompleteness, 4: list<string>}
     */
    private function cycleBounds(Measurement $measurement, Collection $events): array
    {
        $warnings = [];
        $completeness = MeasurementHistoryCompleteness::Complete;
        $submissions = $events
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::Submitted)
            ->reject(fn (MeasurementCycleEvent $event): bool => $event->completeness === MeasurementHistoryCompleteness::Insufficient)
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->occurredAt instanceof CarbonImmutable)
            ->values();
        $cycleStart = $submissions->first()?->occurredAt;

        if ($submissions->isEmpty()) {
            $warnings[] = 'cycle_start_unknown';
            $completeness = MeasurementHistoryCompleteness::Insufficient;
        } elseif ($submissions->count() > 1) {
            $warnings[] = 'multiple_submission_events';
            $completeness = $completeness->combine(MeasurementHistoryCompleteness::Partial);
        }

        $terminalEvents = $events
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::Finalized
                || ($event->eventType === MeasurementCycleEventType::StageRejected
                    && $event->stageBefore === 1
                    && $event->statusAfter === 'rejected'))
            ->reject(fn (MeasurementCycleEvent $event): bool => $event->completeness === MeasurementHistoryCompleteness::Insufficient)
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->occurredAt instanceof CarbonImmutable)
            ->values();
        $terminalKinds = $terminalEvents
            ->map(fn (MeasurementCycleEvent $event): string => $event->eventType->value)
            ->unique();
        $cycleEnd = null;
        $terminalReason = null;

        if ($terminalKinds->count() > 1) {
            $warnings[] = 'contradictory_terminal_events';
            $completeness = MeasurementHistoryCompleteness::Insufficient;
        } elseif ($terminalEvents->isNotEmpty()) {
            $terminal = $terminalEvents->first();
            $cycleEnd = $terminal->occurredAt;
            $terminalReason = $terminal->eventType === MeasurementCycleEventType::Finalized
                ? MeasurementStageExitReason::Finalized
                : MeasurementStageExitReason::RejectedTerminal;

            if ($terminalEvents->count() > 1) {
                $warnings[] = 'duplicate_terminal_events';
                $completeness = $completeness->combine(MeasurementHistoryCompleteness::Partial);
            }
        } elseif (in_array($measurement->status, Measurement::CLOSED_STATUSES, true)) {
            $analyzedAt = $this->immutableDate($measurement->analyzed_at);

            if ($analyzedAt instanceof CarbonImmutable) {
                $cycleEnd = $analyzedAt;
                $terminalReason = $measurement->status === 'finalized'
                    ? MeasurementStageExitReason::Finalized
                    : MeasurementStageExitReason::RejectedTerminal;
                $warnings[] = 'cycle_end_from_current_state_fallback';
                $completeness = $completeness->combine(MeasurementHistoryCompleteness::Partial);
            } else {
                $warnings[] = 'cycle_end_unknown';
                $completeness = MeasurementHistoryCompleteness::Insufficient;
            }
        }

        return [
            $cycleStart,
            $cycleEnd,
            $terminalReason,
            $completeness,
            array_values(array_unique($warnings)),
        ];
    }

    private function immutableDate(mixed $value): ?CarbonImmutable
    {
        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : null;
    }
}
