<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeasurementWorkflow
{
    public const MAX_STAGE = 4;

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

    public function __construct(
        private MeasurementAuthorizationService $authorization,
        private MeasurementEngineeringService $engineering,
        private DocumentStorageService $storage,
        private MeasurementFileValidationService $fileValidation,
    ) {}

    public function unifiedStage(Measurement $measurement): int
    {
        if ($measurement->status === 'paused') {
            $openPause = $measurement->relationLoaded('pauses')
                ? $measurement->pauses->whereNull('resumed_at')->sortByDesc('paused_at')->first()
                : $measurement->pauses()->whereNull('resumed_at')->latest('paused_at')->first();

            return (int) ($openPause?->stage ?? $measurement->current_stage);
        }

        return match ($measurement->status) {
            'pending', 'in_review' => (int) $measurement->current_stage,
            'awaiting_payment' => self::STAGE_PAYMENT,
            'awaiting_receipt', 'approved', 'finalized' => self::STAGE_FINALIZATION,
            default => 0,
        };
    }

    public function canApprove(Measurement $measurement, User $actor): bool
    {
        $stage = $this->unifiedStage($measurement);

        return $this->hasPendingDecisionState($measurement, $stage)
            && $this->authorization->canDecideStage($actor, $measurement, $stage);
    }

    public function canReject(Measurement $measurement, User $actor): bool
    {
        return $this->canApprove($measurement, $actor);
    }

    public function canPause(Measurement $measurement, User $actor): bool
    {
        $stage = $this->unifiedStage($measurement);

        return $stage >= self::STAGE_ENGINEERING
            && $stage <= self::STAGE_PAYMENT
            && $measurement->status !== 'paused'
            && $this->hasPendingDecisionState($measurement, $stage)
            && $this->authorization->canPauseStage($actor, $measurement, $stage);
    }

    public function canResume(Measurement $measurement, User $actor): bool
    {
        $stage = $this->unifiedStage($measurement);

        return $measurement->status === 'paused'
            && $stage >= self::STAGE_ENGINEERING
            && $stage <= self::STAGE_PAYMENT
            && $this->authorization->canPauseStage($actor, $measurement, $stage);
    }

    public function canRegisterPayment(Measurement $measurement, User $actor): bool
    {
        $hasPendingReview = $measurement->relationLoaded('reviews')
            ? $measurement->reviews->contains(fn (MeasurementReview $review): bool => (int) $review->stage === self::STAGE_PAYMENT && $review->status === 'pending')
            : $measurement->reviews()->where('stage', self::STAGE_PAYMENT)->where('status', 'pending')->exists();

        return $measurement->status === 'awaiting_payment'
            && (int) $measurement->current_stage === self::STAGE_PAYMENT
            && $hasPendingReview
            && $this->authorization->canRegisterPayment($actor, $measurement);
    }

    public function canManageReceipts(Measurement $measurement, User $actor): bool
    {
        return in_array($measurement->status, ['awaiting_receipt', 'approved'], true)
            && (int) $measurement->current_stage === self::STAGE_FINALIZATION
            && $this->authorization->canManageReceipts($actor, $measurement);
    }

    public function canFinalize(Measurement $measurement, User $actor): bool
    {
        return $measurement->status === 'approved'
            && (int) $measurement->current_stage === self::STAGE_FINALIZATION
            && $this->authorization->canFinalize($actor, $measurement);
    }

    public function canReturnFromFinalization(Measurement $measurement, User $actor): bool
    {
        return in_array($measurement->status, ['awaiting_receipt', 'approved'], true)
            && (int) $measurement->current_stage === self::STAGE_FINALIZATION
            && $this->authorization->canFinalize($actor, $measurement);
    }

    public function startReview(Measurement $measurement, User $actor): void
    {
        $locked = DB::transaction(function () use ($measurement, $actor): Measurement {
            $locked = $this->lockMeasurement($measurement);

            if (! $this->authorization->canCreateMeasurement($actor, $locked->operation)) {
                throw new AuthorizationException('Você não pode iniciar uma medição nesta operação.');
            }

            if (! in_array($locked->status, ['pending', 'in_review'], true)
                || $locked->reviews()->where('status', 'approved')->exists()) {
                throw $this->invalidState($locked, 'A medição não pode iniciar novamente o fluxo de Engenharia.');
            }

            $locked->reviews()->updateOrCreate(
                ['stage' => self::STAGE_ENGINEERING],
                [
                    'reviewer_user_id' => null,
                    'status' => 'pending',
                    'notes' => null,
                    'reviewed_at' => null,
                ],
            );

            $fromStatus = $locked->status;
            $locked->forceFill([
                'current_stage' => self::STAGE_ENGINEERING,
                'status' => 'in_review',
                'workflow_revision' => (int) $locked->workflow_revision + 1,
            ])->save();

            $this->audit($locked, $actor, 'measurement_submitted', [
                'stage' => self::STAGE_ENGINEERING,
                'from_status' => $fromStatus,
                'to_status' => 'in_review',
                'responsibility' => $this->authorization->responsibilityForStage(self::STAGE_ENGINEERING),
            ]);

            return $locked;
        });

        $this->notifyUsers($locked, 'submitted', [$locked->operation->stageResponsibleId(self::STAGE_ENGINEERING)]);
    }

    /**
     * @param  array<int|string, mixed>  $engineeringProgress
     */
    public function approve(
        Measurement $measurement,
        User $actor,
        ?string $notes = null,
        array $engineeringProgress = [],
        ?int $expectedStage = null,
        ?int $expectedRevision = null,
    ): void {
        $expectedStage ??= $this->unifiedStage($measurement);
        $expectedRevision ??= (int) $measurement->workflow_revision;

        $result = DB::transaction(function () use ($measurement, $actor, $expectedStage, $expectedRevision, $notes, $engineeringProgress): array {
            $locked = $expectedStage === self::STAGE_ENGINEERING
                ? $this->lockEngineeringMeasurement($measurement)
                : $this->lockMeasurement($measurement);
            $stage = $this->unifiedStage($locked);

            $this->assertExpectedState($locked, $expectedRevision, $expectedStage);

            $this->authorizeStageDecision($locked, $actor, $stage);
            $review = $this->lockPendingReview($locked, $stage);

            if ($stage === self::STAGE_ENGINEERING) {
                $engineeringSnapshot = $this->engineering->validateAndRecord($locked, $engineeringProgress);
                $locked->forceFill(['engineering_snapshot' => $engineeringSnapshot])->save();
                $this->audit($locked, $actor, 'measurement_engineering_snapshot_created', [
                    'snapshot_schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
                    'snapshot_sha256' => $this->snapshotHash($engineeringSnapshot),
                    'engineering_snapshot' => $engineeringSnapshot,
                    'workflow_revision' => (int) $locked->workflow_revision + 1,
                ]);
            }

            if ($stage === self::STAGE_PAYMENT) {
                $this->ensureValidPaymentExists($locked);
            }

            $fromStatus = $locked->status;

            $review->forceFill([
                'reviewer_user_id' => $actor->getKey(),
                'status' => 'approved',
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'reviewed_at' => now(),
            ])->save();

            if ($stage < self::STAGE_PAYMENT) {
                $nextStage = $stage + 1;
                $locked->reviews()->updateOrCreate(
                    ['stage' => $nextStage],
                    [
                        'reviewer_user_id' => null,
                        'status' => 'pending',
                        'notes' => null,
                        'reviewed_at' => null,
                        'paused_at' => null,
                        'paused_by' => null,
                        'pause_reason' => null,
                        'paused_operation_status' => null,
                    ],
                );

                $toStatus = $nextStage === self::STAGE_PAYMENT ? 'awaiting_payment' : 'in_review';
                $locked->forceFill([
                    'current_stage' => $nextStage,
                    'status' => $toStatus,
                ])->save();
            } else {
                $nextStage = self::STAGE_FINALIZATION;
                $toStatus = $this->allPaymentsHaveReceipts($locked) ? 'approved' : 'awaiting_receipt';

                $locked->reviews()->updateOrCreate(
                    ['stage' => self::STAGE_FINALIZATION],
                    [
                        'reviewer_user_id' => null,
                        'status' => 'pending',
                        'notes' => null,
                        'reviewed_at' => null,
                    ],
                );

                $locked->forceFill([
                    'current_stage' => self::STAGE_FINALIZATION,
                    'status' => $toStatus,
                ])->save();
            }

            $this->advanceRevision($locked);

            $this->audit($locked, $actor, 'measurement_stage_approved', [
                'stage' => $stage,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $notes,
                'responsibility' => $this->authorization->responsibilityForStage($stage),
                'expected_responsible_user_id' => $locked->operation->stageResponsibleId($stage),
            ]);

            return [
                'measurement' => $locked,
                'approved_stage' => $stage,
                'next_stage' => $nextStage,
                'status' => $toStatus,
            ];
        });

        /** @var Measurement $locked */
        $locked = $result['measurement'];

        if ($result['approved_stage'] === self::STAGE_PAYMENT) {
            if ($result['status'] === 'approved') {
                $this->notifyUsers($locked, 'ready_to_finalize', [$locked->operation->payment_finalizer_user_id]);
            } else {
                $this->notifyUsers($locked, 'payment_approved', [$locked->operation->payment_receipt_uploader_user_id]);
            }

            return;
        }

        $event = $result['next_stage'] === self::STAGE_PAYMENT ? 'awaiting_payment' : 'advanced';
        $this->notifyUsers($locked, $event, [$locked->operation->stageResponsibleId($result['next_stage'])]);
    }

    public function reject(
        Measurement $measurement,
        User $actor,
        string $notes,
        ?int $expectedStage = null,
        ?int $expectedRevision = null,
    ): void {
        $expectedStage ??= $this->unifiedStage($measurement);
        $expectedRevision ??= (int) $measurement->workflow_revision;

        $notes = trim($notes);

        if ($notes === '') {
            throw ValidationException::withMessages(['notes' => 'Informe o motivo da recusa.']);
        }

        $result = DB::transaction(function () use ($measurement, $actor, $expectedStage, $expectedRevision, $notes): array {
            $locked = $this->lockMeasurement($measurement);
            $stage = $this->unifiedStage($locked);

            $this->assertExpectedState($locked, $expectedRevision, $expectedStage);

            $this->authorizeStageDecision($locked, $actor, $stage);
            $review = $this->lockPendingReview($locked, $stage);
            $fromStatus = $locked->status;

            $review->forceFill([
                'reviewer_user_id' => $actor->getKey(),
                'status' => 'rejected',
                'notes' => $notes,
                'reviewed_at' => now(),
            ])->save();

            if ($stage === self::STAGE_ENGINEERING) {
                $targetStage = 0;
                $toStatus = 'rejected';
                $locked->forceFill([
                    'status' => $toStatus,
                    'analyzed_by' => $actor->getKey(),
                    'analyzed_at' => now(),
                ])->save();
            } else {
                $targetStage = $stage - 1;
                $toStatus = $targetStage === self::STAGE_PAYMENT ? 'awaiting_payment' : 'in_review';
                $this->reopenStage($locked, $actor, $targetStage, $notes);
            }

            $this->advanceRevision($locked);

            $this->audit($locked, $actor, 'measurement_stage_rejected', [
                'stage' => $stage,
                'target_stage' => $targetStage ?: null,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $notes,
                'responsibility' => $this->authorization->responsibilityForStage($stage),
                'expected_responsible_user_id' => $locked->operation->stageResponsibleId($stage),
            ]);

            return compact('locked', 'stage', 'targetStage');
        });

        /** @var Measurement $locked */
        $locked = $result['locked'];

        if ($result['stage'] === self::STAGE_ENGINEERING) {
            $recipientIds = $locked->operation->rejectionNotifyUsers()->pluck('users.id')->all();
            $recipientIds[] = $locked->uploaded_by;
            $this->notifyUsers($locked, 'rejected', $recipientIds);

            return;
        }

        $this->notifyUsers($locked, 'returned', [$locked->operation->stageResponsibleId($result['targetStage'])]);
    }

    public function returnToStage(
        Measurement $measurement,
        User $actor,
        int $target,
        ?string $reason = null,
        ?int $expectedRevision = null,
        ?string $expectedStatus = null,
    ): void {
        $expectedRevision ??= (int) $measurement->workflow_revision;
        $expectedStatus ??= (string) $measurement->status;

        $reason = trim((string) $reason);

        if (! in_array($target, [1, 2, 3, self::STAGE_PAYMENT], true)) {
            throw ValidationException::withMessages(['target_stage' => 'Selecione uma etapa de retorno válida.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da devolução.']);
        }

        $locked = DB::transaction(function () use ($measurement, $actor, $expectedRevision, $expectedStatus, $target, $reason): Measurement {
            $locked = $this->lockMeasurement($measurement);

            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_FINALIZATION, $expectedStatus);

            if (! $this->canReturnFromFinalization($locked, $actor)) {
                $this->throwAuthorizationOrState(
                    $this->authorization->canFinalize($actor, $locked),
                    $locked,
                    'A medição não está em Finalização ou o usuário não pode devolvê-la.',
                );
            }

            $fromStatus = $locked->status;
            $finalizationReview = $locked->reviews()->where('stage', self::STAGE_FINALIZATION)->lockForUpdate()->first();

            if (! $finalizationReview instanceof MeasurementReview) {
                $finalizationReview = $locked->reviews()->create([
                    'stage' => self::STAGE_FINALIZATION,
                    'reviewer_user_id' => null,
                    'status' => 'pending',
                ]);
            }

            $finalizationReview->forceFill([
                'reviewer_user_id' => $actor->getKey(),
                'status' => 'rejected',
                'notes' => $reason,
                'reviewed_at' => now(),
            ])->save();

            $this->reopenStage($locked, $actor, $target, $reason);
            $this->advanceRevision($locked);

            $this->audit($locked, $actor, 'measurement_finalization_returned', [
                'stage' => self::STAGE_FINALIZATION,
                'target_stage' => $target,
                'from_status' => $fromStatus,
                'to_status' => $locked->status,
                'notes' => $reason,
                'responsibility' => 'payment_finalizer_user_id',
                'expected_responsible_user_id' => $locked->operation->payment_finalizer_user_id,
            ]);

            return $locked;
        });

        $this->notifyUsers($locked, 'returned', [$locked->operation->stageResponsibleId($target)]);
    }

    public function pause(
        Measurement $measurement,
        User $actor,
        string $reason,
        ?int $expectedStage = null,
        ?int $expectedRevision = null,
    ): void {
        $expectedStage ??= $this->unifiedStage($measurement);
        $expectedRevision ??= (int) $measurement->workflow_revision;

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da pausa.']);
        }

        $locked = DB::transaction(function () use ($measurement, $actor, $expectedStage, $expectedRevision, $reason): Measurement {
            $locked = $this->lockMeasurement($measurement);
            $stage = $this->unifiedStage($locked);

            $this->assertExpectedState($locked, $expectedRevision, $expectedStage);

            if (! $this->canPause($locked, $actor)) {
                $this->throwAuthorizationOrState(
                    $this->authorization->canPauseStage($actor, $locked, $stage),
                    $locked,
                    'Somente uma etapa pendente entre Engenharia e Pagamento pode ser pausada.',
                );
            }

            $review = $this->lockPendingReview($locked, $stage);
            $snapshot = $locked->status;
            $pausedAt = now();

            $review->forceFill([
                'paused_at' => $pausedAt,
                'paused_by' => $actor->getKey(),
                'pause_reason' => $reason,
                'paused_operation_status' => $snapshot,
            ])->save();

            $locked->pauses()->create([
                'stage' => $stage,
                'paused_by' => $actor->getKey(),
                'pause_reason' => $reason,
                'paused_operation_status' => $snapshot,
                'paused_at' => $pausedAt,
            ]);

            $locked->forceFill(['status' => 'paused'])->save();
            $this->advanceRevision($locked);

            $this->audit($locked, $actor, 'measurement_stage_paused', [
                'stage' => $stage,
                'from_status' => $snapshot,
                'to_status' => 'paused',
                'notes' => $reason,
                'responsibility' => $this->authorization->responsibilityForStage($stage),
                'expected_responsible_user_id' => $locked->operation->stageResponsibleId($stage),
            ]);

            return $locked;
        });

        $this->notifyUsers($locked, 'paused', [$locked->uploaded_by, $locked->operation->stageResponsibleId($locked->current_stage)]);
    }

    public function resume(
        Measurement $measurement,
        User $actor,
        ?int $expectedStage = null,
        ?int $expectedRevision = null,
        ?int $expectedPauseId = null,
    ): void {
        $expectedStage ??= $this->unifiedStage($measurement);
        $expectedRevision ??= (int) $measurement->workflow_revision;
        $expectedPauseId ??= (int) $measurement->pauses()
            ->whereNull('resumed_at')
            ->latest('paused_at')
            ->value('id');

        $locked = DB::transaction(function () use ($measurement, $actor, $expectedStage, $expectedRevision, $expectedPauseId): Measurement {
            $locked = $this->lockMeasurement($measurement);
            $this->assertExpectedState($locked, $expectedRevision, $expectedStage, 'paused');
            $openPause = $locked->pauses()->whereNull('resumed_at')->latest('paused_at')->lockForUpdate()->first();
            $stage = (int) ($openPause?->stage ?? $locked->current_stage);

            if (! $openPause instanceof MeasurementPause
                || $locked->status !== 'paused'
                || (int) $openPause->getKey() !== $expectedPauseId) {
                throw $this->invalidState($locked, 'A medição não possui uma pausa aberta.');
            }

            if (! $this->authorization->canPauseStage($actor, $locked, $stage)) {
                throw new AuthorizationException('Você não pode retomar esta etapa da medição.');
            }

            $review = $locked->reviews()->where('stage', $stage)->lockForUpdate()->first();

            if (! $review instanceof MeasurementReview || $review->status !== 'pending') {
                throw $this->invalidState($locked, 'A etapa pausada não está mais pendente.');
            }

            $restoreStatus = in_array($openPause->paused_operation_status, ['in_review', 'awaiting_payment'], true)
                ? $openPause->paused_operation_status
                : ($stage === self::STAGE_PAYMENT ? 'awaiting_payment' : 'in_review');

            $review->forceFill([
                'paused_at' => null,
                'paused_by' => null,
                'pause_reason' => null,
                'paused_operation_status' => null,
            ])->save();

            $openPause->forceFill([
                'resumed_at' => now(),
                'resumed_by' => $actor->getKey(),
            ])->save();

            $locked->forceFill([
                'status' => $restoreStatus,
                'current_stage' => $stage,
            ])->save();
            $this->advanceRevision($locked);

            $this->audit($locked, $actor, 'measurement_stage_resumed', [
                'stage' => $stage,
                'from_status' => 'paused',
                'to_status' => $restoreStatus,
                'responsibility' => $this->authorization->responsibilityForStage($stage),
                'expected_responsible_user_id' => $locked->operation->stageResponsibleId($stage),
            ]);

            return $locked;
        });

        $this->notifyUsers($locked, 'resumed', [$locked->operation->stageResponsibleId($locked->current_stage)]);
    }

    /**
     * @param  array{pay_date: mixed, amount: mixed, method?: ?string, notes?: ?string, plan_set_id?: ?int}  $data
     */
    public function registerPayment(
        Measurement $measurement,
        User $actor,
        array $data,
        ?int $expectedRevision = null,
    ): MeasurementPayment {
        return $this->registerPayments($measurement, $actor, [$data], $expectedRevision)->firstOrFail();
    }

    /**
     * @param  array<int, array{pay_date: mixed, amount: mixed, method?: ?string, notes?: ?string, plan_set_id?: ?int}>  $rows
     * @return Collection<int, MeasurementPayment>
     */
    public function registerPayments(
        Measurement $measurement,
        User $actor,
        array $rows,
        ?int $expectedRevision = null,
    ): Collection {
        $expectedRevision ??= (int) $measurement->workflow_revision;

        $created = DB::transaction(function () use ($measurement, $actor, $expectedRevision, $rows): Collection {
            $locked = $this->lockMeasurement($measurement);

            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_PAYMENT, 'awaiting_payment');

            if (! $this->canRegisterPayment($locked, $actor)) {
                $this->throwAuthorizationOrState(
                    $this->authorization->canRegisterPayment($actor, $locked),
                    $locked,
                    'Pagamentos só podem ser registrados enquanto a etapa Pagamento está pendente.',
                );
            }

            $this->ensureEngineeringSnapshotTopLevelIsIntact($locked);

            $rows = collect($rows)
                ->filter(fn (array $row): bool => filled($row['amount'] ?? null))
                ->values()
                ->all();

            if ($rows === []) {
                throw ValidationException::withMessages(['payments' => 'Informe ao menos um pagamento válido.']);
            }

            $validated = Validator::make(['payments' => $rows], [
                'payments' => ['required', 'array', 'min:1'],
                'payments.*.pay_date' => ['required', 'date'],
                'payments.*.amount' => ['required', 'numeric', 'gt:0'],
                'payments.*.method' => ['nullable', 'string', 'max:255'],
                'payments.*.notes' => ['nullable', 'string'],
                'payments.*.plan_set_id' => ['nullable', 'integer'],
            ])->validate()['payments'];

            $snapshotPlanSets = collect($locked->engineering_snapshot['plan_sets'] ?? []);
            $approvedPlanSetIds = $snapshotPlanSets
                ->pluck('plan_set_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values();
            $currentPlanSetIds = $locked->operation->planSets()
                ->whereKey($approvedPlanSetIds->all())
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (int $id): int => $id);

            if ($approvedPlanSetIds->isEmpty() || $currentPlanSetIds->count() !== $approvedPlanSetIds->count()) {
                throw $this->invalidState($locked, 'O contexto aprovado pela Engenharia não está disponível para pagamentos.');
            }

            $validPlanSetIds = $approvedPlanSetIds->all();
            $defaultPlanSetId = (int) ($snapshotPlanSets->firstWhere('is_default', true)['plan_set_id']
                ?? $validPlanSetIds[0]);

            foreach ($validated as $index => $row) {
                if (filled($row['plan_set_id'] ?? null)
                    && ! in_array((int) $row['plan_set_id'], $validPlanSetIds, true)) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.plan_set_id" => 'O empreendimento informado não pertence ao contexto aprovado desta medição.',
                    ]);
                }
            }

            $payments = collect($validated)->map(fn (array $row): MeasurementPayment => $locked->payments()->create([
                'operation_id' => $locked->operation_id,
                'plan_set_id' => $row['plan_set_id'] ?? $defaultPlanSetId,
                'pay_date' => $row['pay_date'],
                'amount' => $row['amount'],
                'method' => $row['method'] ?? null,
                'notes' => $row['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ]))->values();

            $this->audit($locked, $actor, 'measurement_payment_registered', [
                'stage' => self::STAGE_PAYMENT,
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'amount' => $payments->sum(fn (MeasurementPayment $payment): float => (float) $payment->amount),
                'payment_ids' => $payments->pluck('id')->all(),
                'responsibility' => 'payment_manager_user_id',
                'expected_responsible_user_id' => $locked->operation->payment_manager_user_id,
            ]);

            $this->advanceRevision($locked);

            return $payments;
        });

        $measurement->refresh()->load('operation');
        $this->notifyUsers($measurement, 'payment_registered', [$measurement->operation?->payment_manager_user_id]);

        return $created;
    }

    public function attachReceipt(
        MeasurementPayment $payment,
        User $actor,
        string $path,
        ?string $disk = null,
        ?int $expectedRevision = null,
        ?string $expectedStatus = null,
    ): void {
        $disk ??= DocumentStorageService::privateDisk();
        $expectedRevision ??= (int) $payment->measurement->workflow_revision;
        $expectedStatus ??= (string) $payment->measurement->status;

        $this->fileValidation->validateReceipt($path, $disk);

        $checksum = $this->storage->checksum($path, $disk);

        if ($checksum === null) {
            throw ValidationException::withMessages(['receipt' => 'Não foi possível calcular o SHA-256 do comprovante.']);
        }

        $result = DB::transaction(function () use ($payment, $actor, $expectedRevision, $expectedStatus, $path, $disk, $checksum): array {
            $locked = $this->lockMeasurement($payment->measurement);
            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_FINALIZATION, $expectedStatus);
            $lockedPayment = $locked->payments()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $lockedPayment instanceof MeasurementPayment) {
                throw $this->invalidState($locked, 'O pagamento não pertence a esta medição.');
            }

            if (! $this->canManageReceipts($locked, $actor)) {
                $this->throwAuthorizationOrState(
                    $this->authorization->canManageReceipts($actor, $locked),
                    $locked,
                    'Comprovantes não podem ser alterados no estado atual da medição.',
                );
            }

            $oldPath = $lockedPayment->receipt_path;
            $oldDisk = $lockedPayment->resolved_receipt_disk;

            $lockedPayment->forceFill([
                'receipt_path' => $path,
                'receipt_disk' => $disk,
                'receipt_sha256' => $checksum,
                'receipt_uploaded_by' => $actor->getKey(),
                'receipt_uploaded_at' => now(),
            ])->save();

            if (! is_string($lockedPayment->receipt_sha256) || mb_strlen($lockedPayment->receipt_sha256) !== 64) {
                throw ValidationException::withMessages(['receipt' => 'O SHA-256 do comprovante não pôde ser persistido.']);
            }

            $becameReady = false;

            if ($locked->reviews()->where('stage', self::STAGE_PAYMENT)->where('status', 'approved')->exists()
                && $this->allPaymentsHaveReceipts($locked)) {
                $becameReady = $locked->status !== 'approved';
                $locked->forceFill([
                    'status' => 'approved',
                    'current_stage' => self::STAGE_FINALIZATION,
                ])->save();
            }

            $this->audit($locked, $actor, 'measurement_receipt_attached', [
                'stage' => (int) $locked->current_stage,
                'payment_id' => $lockedPayment->getKey(),
                'hash' => $lockedPayment->receipt_sha256,
                'from_status' => $becameReady ? 'awaiting_receipt' : $locked->status,
                'to_status' => $locked->status,
                'responsibility' => 'payment_receipt_uploader_user_id',
                'expected_responsible_user_id' => $locked->operation->payment_receipt_uploader_user_id,
            ]);

            $this->advanceRevision($locked);

            return compact('locked', 'becameReady', 'oldPath', 'oldDisk');
        });

        if (filled($result['oldPath'])
            && ($result['oldPath'] !== $path || $result['oldDisk'] !== $disk)) {
            rescue(
                fn (): bool => Storage::disk($result['oldDisk'])->delete($result['oldPath']),
                report: true,
            );
        }

        if ($result['becameReady']) {
            $this->notifyUsers($result['locked'], 'ready_to_finalize', [$result['locked']->operation->payment_finalizer_user_id]);
        }

        $this->notifyUsers($result['locked'], 'receipt_attached', [$result['locked']->operation->payment_manager_user_id]);
    }

    public function deleteReceipt(
        MeasurementPayment $payment,
        User $actor,
        ?int $expectedRevision = null,
        ?string $expectedStatus = null,
    ): void {
        $expectedRevision ??= (int) $payment->measurement->workflow_revision;
        $expectedStatus ??= (string) $payment->measurement->status;

        $result = DB::transaction(function () use ($payment, $actor, $expectedRevision, $expectedStatus): array {
            $locked = $this->lockMeasurement($payment->measurement);
            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_FINALIZATION, $expectedStatus);
            $lockedPayment = $locked->payments()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $lockedPayment instanceof MeasurementPayment || ! $lockedPayment->hasReceipt()) {
                throw $this->invalidState($locked, 'O pagamento não possui comprovante para remover.');
            }

            if (! $this->canManageReceipts($locked, $actor)) {
                $this->throwAuthorizationOrState(
                    $this->authorization->canManageReceipts($actor, $locked),
                    $locked,
                    'Comprovantes não podem ser removidos no estado atual da medição.',
                );
            }

            $path = $lockedPayment->receipt_path;
            $disk = $lockedPayment->resolved_receipt_disk;
            $hash = $lockedPayment->receipt_sha256;
            $fromStatus = $locked->status;

            $lockedPayment->forceFill([
                'receipt_path' => null,
                'receipt_disk' => null,
                'receipt_sha256' => null,
                'receipt_size' => null,
                'receipt_mime_type' => null,
                'receipt_uploaded_by' => null,
                'receipt_uploaded_at' => null,
            ])->save();

            if ($locked->status === 'approved') {
                $locked->forceFill(['status' => 'awaiting_receipt'])->save();
            }

            $this->audit($locked, $actor, 'measurement_receipt_deleted', [
                'stage' => (int) $locked->current_stage,
                'payment_id' => $lockedPayment->getKey(),
                'hash' => $hash,
                'from_status' => $fromStatus,
                'to_status' => $locked->status,
                'responsibility' => 'payment_receipt_uploader_user_id',
                'expected_responsible_user_id' => $locked->operation->payment_receipt_uploader_user_id,
            ]);

            $this->advanceRevision($locked);

            return compact('locked', 'path', 'disk');
        });

        rescue(
            fn (): bool => Storage::disk($result['disk'])->delete($result['path']),
            report: true,
        );
    }

    public function finalize(
        Measurement $measurement,
        User $actor,
        ?int $expectedRevision = null,
        ?string $expectedStatus = null,
    ): void {
        $expectedRevision ??= (int) $measurement->workflow_revision;
        $expectedStatus ??= (string) $measurement->status;

        $locked = DB::transaction(function () use ($measurement, $actor, $expectedRevision, $expectedStatus): Measurement {
            $locked = $this->lockMeasurement($measurement);

            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_FINALIZATION, $expectedStatus);

            if (! $this->canFinalize($locked, $actor)) {
                $this->throwAuthorizationOrState(
                    $this->authorization->canFinalize($actor, $locked),
                    $locked,
                    'A medição ainda não está formalmente pronta para Finalização.',
                );
            }

            $approvedStages = $locked->reviews()
                ->whereIn('stage', [1, 2, 3, self::STAGE_PAYMENT])
                ->where('status', 'approved')
                ->distinct('stage')
                ->count('stage');

            if ($approvedStages !== self::MAX_STAGE) {
                throw $this->invalidState($locked, 'Todas as etapas anteriores precisam estar formalmente aprovadas.');
            }

            $this->ensureValidPaymentExists($locked);

            if (! $this->allPaymentsHaveReceipts($locked)) {
                throw $this->invalidState($locked, 'Todos os pagamentos precisam possuir comprovante antes da Finalização.');
            }

            $this->ensureEngineeringCoverageIsIntact($locked);
            $this->ensureStoredFilesAreIntact($locked);

            $fromStatus = $locked->status;
            $locked->reviews()->updateOrCreate(
                ['stage' => self::STAGE_FINALIZATION],
                [
                    'reviewer_user_id' => $actor->getKey(),
                    'status' => 'approved',
                    'reviewed_at' => now(),
                ],
            );

            $locked->forceFill([
                'status' => 'finalized',
                'current_stage' => self::STAGE_FINALIZATION,
                'analyzed_by' => $actor->getKey(),
                'analyzed_at' => now(),
            ])->save();
            $this->advanceRevision($locked);

            $this->audit($locked, $actor, 'measurement_finalized', [
                'stage' => self::STAGE_FINALIZATION,
                'from_status' => $fromStatus,
                'to_status' => 'finalized',
                'responsibility' => 'payment_finalizer_user_id',
                'expected_responsible_user_id' => $locked->operation->payment_finalizer_user_id,
            ]);

            return $locked;
        });

        $this->notifyUsers($locked, 'finalized', [$locked->uploaded_by, $locked->operation->assigned_user_id]);
    }

    private function lockMeasurement(Measurement $measurement): Measurement
    {
        $locked = Measurement::query()
            ->with('operation')
            ->whereKey($measurement->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Measurement) {
            throw new MeasurementWorkflowException('A medição não foi encontrada.');
        }

        return $locked;
    }

    /**
     * Engineering approval shares this deterministic lock order with material
     * Operation mutation: Operation, Measurement, review, then snapshot rows.
     */
    private function lockEngineeringMeasurement(Measurement $measurement): Measurement
    {
        $operationId = Measurement::query()
            ->whereKey($measurement->getKey())
            ->value('operation_id');

        $operation = filled($operationId)
            ? Operation::query()->whereKey($operationId)->lockForUpdate()->first()
            : null;

        if (! $operation instanceof Operation) {
            throw new MeasurementWorkflowException('A operação da medição não foi encontrada.');
        }

        $locked = Measurement::query()
            ->whereKey($measurement->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Measurement) {
            throw new MeasurementWorkflowException('A medição não foi encontrada.');
        }

        if ((int) $locked->operation_id !== (int) $operation->getKey()) {
            throw $this->invalidState($locked, 'A operação da medição foi alterada durante a aprovação da Engenharia.');
        }

        $locked->setRelation('operation', $operation);

        return $locked;
    }

    private function authorizeStageDecision(Measurement $measurement, User $actor, int $stage): void
    {
        if (! $this->authorization->canDecideStage($actor, $measurement, $stage)) {
            throw new AuthorizationException('Você não possui permissão e responsabilidade para decidir esta etapa.');
        }

        if (! $this->hasPendingDecisionState($measurement, $stage)) {
            throw $this->invalidState($measurement, 'Esta etapa não está mais pendente ou a medição está pausada.');
        }
    }

    private function lockPendingReview(Measurement $measurement, int $stage): MeasurementReview
    {
        $review = $measurement->reviews()->where('stage', $stage)->lockForUpdate()->first();

        if (! $review instanceof MeasurementReview || $review->status !== 'pending' || $review->isPaused()) {
            throw $this->invalidState($measurement, 'Esta etapa já foi decidida por outro usuário ou está pausada.');
        }

        return $review;
    }

    private function hasPendingDecisionState(Measurement $measurement, int $stage): bool
    {
        if ($measurement->status === 'paused' || $stage < 1 || $stage > self::STAGE_PAYMENT) {
            return false;
        }

        $hasExpectedStatus = $stage === self::STAGE_PAYMENT
            ? $measurement->status === 'awaiting_payment' && (int) $measurement->current_stage === self::STAGE_PAYMENT
            : in_array($measurement->status, ['pending', 'in_review'], true)
                && (int) $measurement->current_stage === $stage;

        $hasPendingReview = $measurement->relationLoaded('reviews')
            ? $measurement->reviews->contains(fn (MeasurementReview $review): bool => (int) $review->stage === $stage
                && $review->status === 'pending'
                && $review->paused_at === null)
            : $measurement->reviews()->where('stage', $stage)->where('status', 'pending')->whereNull('paused_at')->exists();

        return $hasExpectedStatus && $hasPendingReview;
    }

    private function reopenStage(Measurement $measurement, User $actor, int $target, ?string $note): void
    {
        $previousSnapshot = $target === self::STAGE_ENGINEERING
            ? $measurement->engineering_snapshot
            : null;

        $measurement->reviews()->updateOrCreate(
            ['stage' => $target],
            [
                'reviewer_user_id' => null,
                'status' => 'pending',
                'notes' => $note,
                'reviewed_at' => null,
                'paused_at' => null,
                'paused_by' => null,
                'pause_reason' => null,
                'paused_operation_status' => null,
            ],
        );

        $state = [
            'current_stage' => $target,
            'status' => $target === self::STAGE_PAYMENT ? 'awaiting_payment' : 'in_review',
        ];

        if ($target === self::STAGE_ENGINEERING) {
            $state['engineering_snapshot'] = null;
        }

        $measurement->forceFill($state)->save();

        if (is_array($previousSnapshot) && $previousSnapshot !== []) {
            $this->audit($measurement, $actor, 'measurement_engineering_snapshot_invalidated', [
                'reason' => $note,
                'snapshot_schema_version' => $previousSnapshot['schema_version'] ?? null,
                'snapshot_sha256' => $this->snapshotHash($previousSnapshot),
                'engineering_snapshot' => $previousSnapshot,
                'workflow_revision' => (int) $measurement->workflow_revision + 1,
            ]);
        }
    }

    private function ensureValidPaymentExists(Measurement $measurement): void
    {
        $paymentCount = $measurement->payments()->count();
        $validPaymentCount = $measurement->payments()
            ->where('amount', '>', 0)
            ->whereNotNull('pay_date')
            ->count();

        if ($paymentCount < 1 || $paymentCount !== $validPaymentCount) {
            throw ValidationException::withMessages([
                'payments' => 'Cadastre ao menos um pagamento válido antes de aprovar a etapa Pagamento.',
            ]);
        }

        $approvedPlanSetIds = collect($measurement->engineering_snapshot['plan_sets'] ?? [])
            ->pluck('plan_set_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($approvedPlanSetIds->isEmpty()
            || $measurement->payments()->whereNotIn('plan_set_id', $approvedPlanSetIds->all())->exists()
            || $measurement->payments()->whereNull('plan_set_id')->exists()) {
            throw ValidationException::withMessages([
                'payments' => 'Todos os pagamentos devem pertencer ao contexto aprovado pela Engenharia.',
            ]);
        }
    }

    private function allPaymentsHaveReceipts(Measurement $measurement): bool
    {
        return $measurement->payments()->exists()
            && $measurement->payments()
                ->where(fn ($payments) => $payments->whereNull('receipt_path')->orWhere('receipt_path', ''))
                ->doesntExist();
    }

    private function ensureStoredFilesAreIntact(Measurement $measurement): void
    {
        $files = collect();

        if (filled($measurement->storage_path)) {
            $files->push([
                'path' => $measurement->storage_path,
                'disk' => $measurement->resolved_storage_disk,
                'hash' => $measurement->sha256,
                'label' => 'arquivo principal da medição',
            ]);
        }

        $measurement->assets()->get()->each(function ($asset) use ($files, $measurement): void {
            try {
                $this->fileValidation->validateStoredAsset($asset->storage_path, $asset->resolved_storage_disk);
            } catch (ValidationException) {
                throw $this->invalidState($measurement, "O arquivo de medição #{$asset->getKey()} é inválido ou está ausente.");
            }

            $files->push([
                'path' => $asset->storage_path,
                'disk' => $asset->resolved_storage_disk,
                'hash' => $asset->sha256,
                'label' => "arquivo de medição #{$asset->getKey()}",
            ]);
        });

        $measurement->payments()->get()->each(function (MeasurementPayment $payment) use ($files, $measurement): void {
            try {
                $this->fileValidation->validateStoredReceipt($payment->receipt_path, $payment->resolved_receipt_disk);
            } catch (ValidationException) {
                throw $this->invalidState($measurement, "O comprovante #{$payment->getKey()} é inválido ou está ausente.");
            }

            $files->push([
                'path' => $payment->receipt_path,
                'disk' => $payment->resolved_receipt_disk,
                'hash' => $payment->receipt_sha256,
                'label' => "comprovante #{$payment->getKey()}",
            ]);
        });

        foreach ($files as $file) {
            $hash = $file['hash'];
            $actualHash = filled($file['path'])
                ? $this->storage->checksum($file['path'], $file['disk'])
                : null;

            if (! is_string($hash)
                || mb_strlen($hash) !== 64
                || ! is_string($actualHash)
                || ! hash_equals($hash, $actualHash)) {
                throw $this->invalidState(
                    $measurement,
                    "O {$file['label']} está ausente, sem SHA-256 ou não corresponde ao conteúdo auditado.",
                );
            }
        }
    }

    private function ensureEngineeringCoverageIsIntact(Measurement $measurement): void
    {
        $this->ensureEngineeringSnapshotTopLevelIsIntact($measurement);

        $snapshot = $measurement->engineering_snapshot;
        $requirements = collect(is_array($snapshot) ? ($snapshot['plan_sets'] ?? []) : []);

        if (($measurement->reference_month?->toDateString() ?? '') !== ($snapshot['reference_month'] ?? null)
            || $requirements->isEmpty()
            || $requirements->pluck('plan_set_id')->filter()->unique()->count() !== $requirements->count()) {
            throw $this->invalidState($measurement, 'O snapshot obrigatório da Engenharia está ausente, desatualizado ou possui cardinalidade inválida.');
        }

        $expectedPlanSetIds = $requirements->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all();
        $expectedConstructionIds = $requirements->pluck('construction_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all();
        $expectedLineIds = $requirements->pluck('plan_line_id')->map(fn (mixed $id): int => (int) $id)->all();
        $planSets = $measurement->operation->planSets()
            ->whereKey($expectedPlanSetIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $constructions = Construction::query()
            ->whereKey($expectedConstructionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $lines = MeasurementPlanLine::query()
            ->whereKey($expectedLineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $assets = $measurement->assets()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($planSets->count() !== count($expectedPlanSetIds)
            || $lines->count() !== count($expectedLineIds)
            || $constructions->count() !== count($expectedConstructionIds)
            || $assets->count() !== count($expectedPlanSetIds)
            || $assets->pluck('plan_set_id')->filter()->unique()->count() !== count($expectedPlanSetIds)) {
            throw $this->invalidState($measurement, 'A cobertura atual não corresponde ao contexto aprovado pela Engenharia.');
        }

        $assetsByPlanSet = $assets->keyBy('plan_set_id');

        foreach ($requirements as $requirement) {
            $planSetId = (int) ($requirement['plan_set_id'] ?? 0);
            $planSet = $planSets->get($planSetId);
            $constructionId = $requirement['construction_id'] ?? null;
            $construction = filled($constructionId) ? $constructions->get((int) $constructionId) : null;
            $line = $lines->get((int) ($requirement['plan_line_id'] ?? 0));
            $asset = $assetsByPlanSet->get($planSetId);

            if (! $planSet instanceof MeasurementPlanSet
                || ! $line instanceof MeasurementPlanLine
                || ! $asset instanceof MeasurementAsset
                || ! $this->nullableIntMatches($planSet->construction_id, $constructionId)
                || ! $this->nullableDecimalMatches($planSet->construction_fund_amount, $requirement['construction_fund_amount'] ?? null)
                || ! $this->nullableDecimalMatches($planSet->initial_incurred_amount, $requirement['initial_incurred_amount'] ?? null)
                || (filled($constructionId) && ! $construction instanceof Construction)
                || ($construction instanceof Construction
                    && (! $this->nullableIntMatches($construction->emission_id, $requirement['construction_emission_id'] ?? null)
                        || $construction->development_cnpj !== ($requirement['construction_cnpj'] ?? null)))
                || (int) $line->plan_set_id !== $planSetId
                || (int) $line->operation_id !== (int) $measurement->operation_id
                || (int) $line->measurement_id !== (int) $measurement->getKey()
                || ($line->measurement_date?->toDateString() ?? '') !== ($requirement['measurement_date'] ?? null)
                || (int) $line->sequence_number !== (int) ($requirement['sequence_number'] ?? 0)
                || ! $this->decimalMatches($line->planned_monthly_percent, $requirement['planned_monthly_percent'] ?? null)
                || ! $this->decimalMatches($line->planned_cumulative_percent, $requirement['planned_cumulative_percent'] ?? null)
                || ! $this->decimalMatches($line->initial_realized_cumulative_percent, $requirement['initial_realized_cumulative_percent'] ?? null)
                || ! $this->decimalMatches($line->realized_monthly_percent, $requirement['realized_monthly_percent'] ?? null)
                || ! $this->decimalMatches($line->realized_cumulative_percent, $requirement['realized_cumulative_percent'] ?? null)
                || (int) $asset->measurement_id !== (int) $measurement->getKey()
                || (int) $asset->getKey() !== (int) ($requirement['asset_id'] ?? 0)
                || (int) $asset->plan_line_id !== (int) $line->getKey()
                || $asset->storage_path !== ($requirement['storage_path'] ?? null)
                || $asset->resolved_storage_disk !== ($requirement['storage_disk'] ?? null)
                || $asset->mime_type !== ($requirement['mime_type'] ?? null)
                || (int) $asset->size !== (int) ($requirement['file_size'] ?? -1)
                || ! is_string($asset->sha256)
                || ! hash_equals((string) ($requirement['sha256'] ?? ''), $asset->sha256)) {
                throw $this->invalidState($measurement, "O contexto aprovado pela Engenharia para o plano #{$planSetId} foi alterado.");
            }
        }
    }

    private function ensureEngineeringSnapshotTopLevelIsIntact(Measurement $measurement): void
    {
        $snapshot = $measurement->engineering_snapshot;

        if (! is_array($snapshot)
            || (int) ($snapshot['schema_version'] ?? 0) !== MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION
            || (int) ($snapshot['measurement_id'] ?? 0) !== (int) $measurement->getKey()
            || (int) ($snapshot['operation_id'] ?? 0) !== (int) $measurement->operation_id
            || ! $this->nullableIntMatches($measurement->operation->emission_id, $snapshot['emission_id'] ?? null)) {
            throw $this->invalidState($measurement, 'O contexto principal aprovado pela Engenharia está ausente ou divergente.');
        }
    }

    private function decimalMatches(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual)
            && is_numeric($expected)
            && number_format((float) $actual, 2, '.', '') === number_format((float) $expected, 2, '.', '');
    }

    private function nullableDecimalMatches(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        return $this->decimalMatches($actual, $expected);
    }

    private function nullableIntMatches(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        return (int) $actual === (int) $expected;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function assertExpectedState(
        Measurement $measurement,
        int $expectedRevision,
        ?int $expectedStage = null,
        ?string $expectedStatus = null,
    ): void {
        if ((int) $measurement->workflow_revision !== $expectedRevision
            || ($expectedStage !== null && $this->unifiedStage($measurement) !== $expectedStage)
            || ($expectedStatus !== null && $measurement->status !== $expectedStatus)) {
            throw $this->invalidState(
                $measurement,
                'A etapa desta medição foi alterada por outra ação. Atualize a página.',
            );
        }
    }

    private function advanceRevision(Measurement $measurement): void
    {
        $measurement->forceFill([
            'workflow_revision' => (int) $measurement->workflow_revision + 1,
        ])->save();
    }

    private function throwAuthorizationOrState(
        bool $authorized,
        Measurement $measurement,
        string $stateMessage,
    ): never {
        if (! $authorized) {
            throw new AuthorizationException('Você não possui permissão e responsabilidade para esta ação.');
        }

        throw $this->invalidState($measurement, $stateMessage);
    }

    private function invalidState(Measurement $measurement, string $message): MeasurementWorkflowException
    {
        return new MeasurementWorkflowException($message, [
            'measurement_id' => $measurement->getKey(),
            'operation_id' => $measurement->operation_id,
            'status' => $measurement->status,
            'stage' => $this->unifiedStage($measurement),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(Measurement $measurement, User $actor, string $event, array $properties): void
    {
        $delegationContext = $this->resolveDelegationContext(
            $measurement,
            $actor,
            $properties['responsibility'] ?? $properties['stage'] ?? null,
        );

        activity('measurement_workflow')
            ->performedOn($measurement)
            ->causedBy($actor)
            ->withProperties(array_merge([
                'operation_id' => $measurement->operation_id,
                'measurement_id' => $measurement->getKey(),
                'delegated' => $delegationContext !== null,
                'delegation_id' => $delegationContext?->getKey(),
                'delegator_user_id' => $delegationContext?->delegator_user_id,
                'delegation_scope' => $delegationContext ? [
                    'type' => $delegationContext->scope_type,
                    'operation_id' => $delegationContext->scope_operation_id,
                    'stage' => $delegationContext->scope_stage,
                    'responsibility' => $delegationContext->scope_responsibility,
                ] : null,
                'actual_actor_user_id' => $actor->getKey(),
                'workflow_revision' => (int) $measurement->workflow_revision,
            ], $properties))
            ->log($event);
    }

    private function resolveDelegationContext(Measurement $measurement, User $actor, mixed $responsibility): ?ResponsibilityDelegation
    {
        $resolved = is_int($responsibility)
            ? MeasurementResponsibility::primaryForStage($responsibility)
            : MeasurementResponsibility::fromOperationColumn((string) $responsibility);

        if (! $resolved instanceof MeasurementResponsibility) {
            return null;
        }

        // If actor is directly responsible, no delegation
        $operation = $measurement->operation;
        if (! $operation instanceof Operation) {
            return null;
        }

        if ($operation->hasDirectResponsibility($actor, $resolved)) {
            return null;
        }

        if (app(MeasurementAuthorizationService::class)->isWorkflowAdministrator($actor)) {
            return null;
        }

        return app(MeasurementAuthorizationService::class)->activeDelegationFor($actor, $measurement, $resolved);
    }

    /**
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

        try {
            Notification::send(
                $recipients,
                (new MeasurementWorkflowNotification($measurement->fresh(), $event))->afterCommit(),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
