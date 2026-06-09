<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementReview;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class MeasurementWorkflow
{
    public const MAX_STAGE = 3;

    public const STAGE_ENGINEERING = 1;

    public const STAGE_PAYMENT = 4;

    public const STAGE_FINALIZATION = 5;

    public const STAGE_LABELS = [
        1 => 'Engenharia',
        2 => 'Gestão',
        3 => 'Compliance',
        4 => 'Pagamento',
        5 => 'Finalização',
    ];

    public const STAGE_COLORS = [
        1 => 'info',
        2 => 'warning',
        3 => 'primary',
        4 => 'success',
        5 => 'gray',
    ];

    /**
     * Resolves the unified workflow stage (1–5) of a measurement from its status,
     * looking through a pause to the stage it was paused at. Returns 0 when the
     * measurement is closed (rejected) and has no actionable stage.
     */
    public function unifiedStage(Measurement $measurement): int
    {
        $status = $measurement->status;

        if ($status === 'paused') {
            $status = $measurement->pauses()
                ->whereNull('resumed_at')
                ->latest('paused_at')
                ->value('paused_operation_status') ?? 'in_review';
        }

        return match ($status) {
            'pending', 'in_review' => (int) $measurement->current_stage,
            'awaiting_payment', 'awaiting_receipt' => self::STAGE_PAYMENT,
            'finalized' => self::STAGE_FINALIZATION,
            default => 0,
        };
    }

    /**
     * Opens the first review stage right after a measurement is uploaded.
     */
    public function startReview(Measurement $measurement): void
    {
        DB::transaction(function () use ($measurement): void {
            $reviewerId = $measurement->operation?->reviewerForStage(1);

            $measurement->reviews()->updateOrCreate(
                ['stage' => 1],
                ['reviewer_user_id' => $reviewerId, 'status' => 'pending'],
            );

            $measurement->forceFill([
                'current_stage' => 1,
                'status' => 'in_review',
            ])->save();
        });

        $this->notifyUsers($measurement, 'submitted', [$measurement->operation?->reviewerForStage(1)]);
    }

    public function approve(Measurement $measurement, User $actor, ?string $notes = null): void
    {
        DB::transaction(function () use ($measurement, $actor, $notes): void {
            $stage = $measurement->current_stage;

            $measurement->reviews()->updateOrCreate(
                ['stage' => $stage],
                [
                    'reviewer_user_id' => $measurement->reviews()->where('stage', $stage)->value('reviewer_user_id') ?? $actor->id,
                    'status' => 'approved',
                    'notes' => $notes,
                    'reviewed_at' => now(),
                ],
            );

            $nextStage = $this->nextStageWithReviewer($measurement, $stage);

            if ($nextStage !== null) {
                $measurement->reviews()->updateOrCreate(
                    ['stage' => $nextStage],
                    [
                        'reviewer_user_id' => $measurement->operation?->reviewerForStage($nextStage),
                        'status' => 'pending',
                    ],
                );

                $measurement->forceFill([
                    'current_stage' => $nextStage,
                    'status' => 'in_review',
                ])->save();

                return;
            }

            $measurement->forceFill([
                'status' => 'awaiting_payment',
            ])->save();
        });

        $measurement->refresh();

        if ($measurement->status === 'in_review') {
            $this->notifyUsers($measurement, 'advanced', [$measurement->operation?->reviewerForStage($measurement->current_stage)]);

            return;
        }

        $this->notifyUsers($measurement, 'awaiting_payment', [$measurement->operation?->payment_manager_user_id]);
    }

    /**
     * Rejecting at the Engineering stage closes the measurement (terminal) and notifies
     * the rejection recipients. Rejecting at any later stage (Gestão, Compliance,
     * Pagamentos) sends the measurement back to the previous stage.
     */
    public function reject(Measurement $measurement, User $actor, string $notes): void
    {
        $stage = $this->unifiedStage($measurement);

        if ($stage <= self::STAGE_ENGINEERING) {
            $this->rejectTerminally($measurement, $actor, $notes);

            return;
        }

        $target = $stage - 1;

        DB::transaction(function () use ($measurement, $actor, $notes, $stage, $target): void {
            if ($stage <= self::MAX_STAGE) {
                $measurement->reviews()->updateOrCreate(
                    ['stage' => $stage],
                    [
                        'reviewer_user_id' => $measurement->reviews()->where('stage', $stage)->value('reviewer_user_id') ?? $actor->id,
                        'status' => 'rejected',
                        'notes' => $notes,
                        'reviewed_at' => now(),
                    ],
                );
            }

            $this->moveToStage($measurement, $target, $notes);
        });

        $this->notifyUsers($measurement, 'returned', [$measurement->operation?->stageResponsibleId($target)]);
    }

    /**
     * Closes the measurement as rejected and notifies the configured rejection recipients.
     */
    private function rejectTerminally(Measurement $measurement, User $actor, string $notes): void
    {
        DB::transaction(function () use ($measurement, $actor, $notes): void {
            $measurement->reviews()->updateOrCreate(
                ['stage' => self::STAGE_ENGINEERING],
                [
                    'reviewer_user_id' => $measurement->reviews()->where('stage', self::STAGE_ENGINEERING)->value('reviewer_user_id') ?? $actor->id,
                    'status' => 'rejected',
                    'notes' => $notes,
                    'reviewed_at' => now(),
                ],
            );

            $measurement->forceFill([
                'status' => 'rejected',
                'analyzed_by' => $actor->id,
                'analyzed_at' => now(),
            ])->save();
        });

        $recipientIds = $measurement->operation?->rejectionNotifyUsers()->pluck('users.id')->all() ?? [];
        $recipientIds[] = $measurement->uploaded_by;

        $this->notifyUsers($measurement, 'rejected', $recipientIds);
    }

    /**
     * Sends the measurement back to any earlier stage (1–4). Used by the Finalization
     * stage to bounce a measurement to a chosen stage.
     */
    public function returnToStage(Measurement $measurement, User $actor, int $target, ?string $reason = null): void
    {
        DB::transaction(function () use ($measurement, $target, $reason): void {
            $this->moveToStage($measurement, $target, $reason);
        });

        $this->notifyUsers($measurement, 'returned', [$measurement->operation?->stageResponsibleId($target)]);
    }

    /**
     * Repositions a measurement at a target stage: a review stage (1–3) reopens that
     * review as pending; the payment stage (4) sets it back to awaiting payment.
     */
    private function moveToStage(Measurement $measurement, int $target, ?string $note = null): void
    {
        if ($target >= 1 && $target <= self::MAX_STAGE) {
            $measurement->reviews()->updateOrCreate(
                ['stage' => $target],
                [
                    'reviewer_user_id' => $measurement->operation?->reviewerForStage($target),
                    'status' => 'pending',
                    'notes' => $note,
                    'reviewed_at' => null,
                ],
            );

            $measurement->forceFill([
                'current_stage' => $target,
                'status' => 'in_review',
            ])->save();

            return;
        }

        if ($target === self::STAGE_PAYMENT) {
            $measurement->forceFill([
                'current_stage' => self::MAX_STAGE,
                'status' => 'awaiting_payment',
            ])->save();
        }
    }

    public function pause(Measurement $measurement, User $actor, string $reason): void
    {
        DB::transaction(function () use ($measurement, $actor, $reason): void {
            $stage = $measurement->current_stage;
            $snapshot = $measurement->status;

            $measurement->reviews()->updateOrCreate(
                ['stage' => $stage],
                [
                    'paused_at' => now(),
                    'paused_by' => $actor->id,
                    'pause_reason' => $reason,
                    'paused_operation_status' => $snapshot,
                ],
            );

            $measurement->pauses()->create([
                'stage' => $stage,
                'paused_by' => $actor->id,
                'pause_reason' => $reason,
                'paused_operation_status' => $snapshot,
                'paused_at' => now(),
            ]);

            $measurement->forceFill(['status' => 'paused'])->save();
        });

        $this->notifyUsers($measurement, 'paused', [
            $measurement->uploaded_by,
            $measurement->operation?->responsible_user_id,
        ]);
    }

    public function resume(Measurement $measurement, User $actor): void
    {
        DB::transaction(function () use ($measurement, $actor): void {
            $stage = $measurement->current_stage;
            $review = $measurement->reviews()->where('stage', $stage)->first();
            $restoreStatus = $review?->paused_operation_status ?? 'in_review';

            if ($review instanceof MeasurementReview) {
                $review->forceFill([
                    'paused_at' => null,
                    'paused_by' => null,
                    'pause_reason' => null,
                    'paused_operation_status' => null,
                ])->save();
            }

            $openPause = $measurement->pauses()
                ->where('stage', $stage)
                ->whereNull('resumed_at')
                ->latest('paused_at')
                ->first();

            if ($openPause instanceof MeasurementPause) {
                $openPause->forceFill([
                    'resumed_at' => now(),
                    'resumed_by' => $actor->id,
                ])->save();
            }

            $measurement->forceFill(['status' => $restoreStatus])->save();
        });

        $this->notifyUsers($measurement, 'resumed', [$measurement->operation?->reviewerForStage($measurement->current_stage)]);
    }

    /**
     * @param  array{pay_date: mixed, amount: mixed, method?: ?string, notes?: ?string, plan_set_id?: ?int}  $data
     */
    public function registerPayment(Measurement $measurement, User $actor, array $data): MeasurementPayment
    {
        return $this->registerPayments($measurement, $actor, [$data])->firstOrFail();
    }

    /**
     * Registers one payment per development (plan set) for a measurement and moves
     * it to "awaiting receipt". Rows without an amount are ignored.
     *
     * @param  array<int, array{pay_date: mixed, amount: mixed, method?: ?string, notes?: ?string, plan_set_id?: ?int}>  $rows
     * @return Collection<int, MeasurementPayment>
     */
    public function registerPayments(Measurement $measurement, User $actor, array $rows): Collection
    {
        $created = DB::transaction(function () use ($measurement, $actor, $rows): Collection {
            $payments = collect($rows)
                ->filter(fn (array $row): bool => filled($row['amount'] ?? null))
                ->map(fn (array $row): MeasurementPayment => $measurement->payments()->create([
                    'operation_id' => $measurement->operation_id,
                    'plan_set_id' => $row['plan_set_id'] ?? $measurement->operation?->defaultPlanSet()?->id,
                    'pay_date' => $row['pay_date'],
                    'amount' => $row['amount'],
                    'method' => $row['method'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'created_by' => $actor->id,
                ]))
                ->values();

            if ($payments->isNotEmpty()) {
                $measurement->forceFill(['status' => 'awaiting_receipt'])->save();
            }

            return $payments;
        });

        if ($created->isNotEmpty()) {
            $this->notifyUsers($measurement, 'awaiting_receipt', [$measurement->operation?->payment_manager_user_id]);
        }

        return $created;
    }

    /**
     * Attaches a receipt to a payment. Once every payment of the measurement has a
     * receipt, the user responsible for Finalization is notified that it is ready.
     */
    public function attachReceipt(MeasurementPayment $payment, string $path): void
    {
        $payment->forceFill([
            'receipt_path' => $path,
            'receipt_uploaded_at' => now(),
        ])->save();

        $measurement = $payment->measurement;

        if ($measurement instanceof Measurement
            && $measurement->payments()->whereNull('receipt_path')->doesntExist()) {
            $this->notifyUsers($measurement, 'receipt_attached', [$measurement->operation?->payment_finalizer_user_id]);
        }
    }

    public function finalize(Measurement $measurement, User $actor): void
    {
        $measurement->forceFill([
            'status' => 'finalized',
            'analyzed_by' => $actor->id,
            'analyzed_at' => now(),
        ])->save();

        $this->notifyUsers($measurement, 'finalized', [
            $measurement->uploaded_by,
            $measurement->operation?->assigned_user_id,
        ]);
    }

    /**
     * Propagates the realized monthly progress reported during the measurement
     * validation to the matching schedule line of each development (plan set).
     * The cumulative is derived automatically and the line is linked to the measurement.
     *
     * @param  array<int|string, mixed>  $monthlyByPlanSet  plan_set_id => realized monthly percent
     */
    public function recordRealizedProgress(Measurement $measurement, array $monthlyByPlanSet): void
    {
        $referenceMonth = $measurement->reference_month;

        DB::transaction(function () use ($measurement, $monthlyByPlanSet, $referenceMonth): void {
            foreach ($monthlyByPlanSet as $planSetId => $monthly) {
                if (blank($monthly)) {
                    continue;
                }

                $line = $this->resolveScheduleLine($measurement, (int) $planSetId, $referenceMonth);

                if (! $line instanceof MeasurementPlanLine) {
                    continue;
                }

                $previousCumulative = MeasurementPlanLine::query()
                    ->where('plan_set_id', $planSetId)
                    ->where('sequence_number', '<', $line->sequence_number)
                    ->orderByDesc('sequence_number')
                    ->value('realized_cumulative_percent');

                $base = $previousCumulative !== null
                    ? (float) $previousCumulative
                    : (float) $line->initial_realized_cumulative_percent;

                $line->forceFill([
                    'realized_monthly_percent' => (float) $monthly,
                    'realized_cumulative_percent' => round($base + (float) $monthly, 2),
                    'measurement_id' => $measurement->id,
                ])->save();
            }
        });
    }

    /**
     * Resolves the schedule line a realized value belongs to for a given development:
     * the line explicitly chosen on the uploaded asset, otherwise the line matching
     * the measurement's reference month.
     */
    private function resolveScheduleLine(Measurement $measurement, int $planSetId, ?\Carbon\CarbonInterface $referenceMonth): ?MeasurementPlanLine
    {
        $assetLineId = $measurement->assets()
            ->where('plan_set_id', $planSetId)
            ->whereNotNull('plan_line_id')
            ->value('plan_line_id');

        if ($assetLineId !== null) {
            return MeasurementPlanLine::query()->find($assetLineId);
        }

        if ($referenceMonth === null) {
            return null;
        }

        return MeasurementPlanLine::query()
            ->where('plan_set_id', $planSetId)
            ->whereYear('measurement_date', $referenceMonth->year)
            ->whereMonth('measurement_date', $referenceMonth->month)
            ->orderBy('sequence_number')
            ->first();
    }

    private function nextStageWithReviewer(Measurement $measurement, int $currentStage): ?int
    {
        for ($stage = $currentStage + 1; $stage <= self::MAX_STAGE; $stage++) {
            if (filled($measurement->operation?->reviewerForStage($stage))) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Sends a workflow notification to the given users, ignoring null/duplicate ids.
     *
     * @param  array<int, int|null>  $userIds
     */
    private function notifyUsers(Measurement $measurement, string $event, array $userIds): void
    {
        $ids = array_values(array_unique(array_filter($userIds)));

        if ($ids === []) {
            return;
        }

        $recipients = User::query()->whereKey($ids)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new MeasurementWorkflowNotification($measurement, $event));
    }
}
