<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementFinancialReconciliationLine;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Support\Delegations\ResponsibilityAuthorization;
use App\Support\Delegations\ResponsibilityAuthorizationCapture;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

    /**
     * Por que a Engenharia não pode encerrar por recusa uma medição paga.
     *
     * A mesma frase serve à recusa do domínio e ao motivo exibido no botão
     * desabilitado: a pessoa lê, antes de tentar, exatamente o que o domínio
     * responderia.
     */
    public const TERMINAL_REJECTION_WITH_PAYMENTS = 'Esta medição tem pagamento registrado e não pode ser encerrada por recusa. Corrija os dados e aprove a Engenharia: o pagamento continua vinculado a esta medição.';

    /**
     * Por que a Engenharia não pode encerrar por recusa a revisão vigente: ela
     * já substituiu a anterior, que não volta a valer -- recusá-la deixaria a
     * medição lógica sem nenhuma revisão vigente.
     */
    public const TERMINAL_REJECTION_OF_EFFECTIVE_REVISION = 'Esta revisão já substituiu a revisão anterior e não pode ser encerrada por recusa: a anterior não volta a valer. Corrija os dados e aprove a Engenharia de novo.';

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

    public function canApprove(
        Measurement $measurement,
        User $actor,
        ?ResponsibilityAuthorizationCapture $capture = null,
    ): bool {
        $stage = $this->unifiedStage($measurement);

        return $this->hasPendingDecisionState($measurement, $stage)
            && $this->authorizes($actor, $measurement, $stage, $capture);
    }

    /** @return list<MeasurementResponsibility> */
    public function pendingResponsibilities(Measurement $measurement): array
    {
        // Suspensa por uma revisão em análise: ninguém age até ela terminar. O
        // rascunho de revisão (etapa 0) também não pede nada.
        if ($measurement->isFrozenByRevision()) {
            return [];
        }

        if (in_array($measurement->status, ['awaiting_receipt', 'approved', 'finalized'], true)) {
            return app(MeasurementReceiptEvidenceService::class)->pendingResponsibilities($measurement);
        }

        $responsibility = match ($measurement->status) {
            'pending', 'in_review', 'paused' => MeasurementResponsibility::primaryForStage($this->unifiedStage($measurement)),
            'awaiting_payment' => MeasurementResponsibility::PaymentManager,
            default => null,
        };

        return $responsibility === null ? [] : [$responsibility];
    }

    public function canReject(Measurement $measurement, User $actor): bool
    {
        return $this->canApprove($measurement, $actor);
    }

    /**
     * Por que a recusa não pode encerrar esta medição, ou `null` quando pode.
     *
     * Só a recusa na Engenharia encerra a medição ('rejected'); nas demais
     * etapas ela devolve à etapa anterior. Medição com pagamento registrado só
     * termina 'finalized': o pagamento não tem estorno nem cancelamento no
     * domínio e já conta como custo incorrido do plano, então uma medição
     * encerrada por recusa deixaria esse dinheiro fora de qualquer fluxo -- sem
     * pendência, sem conferência de comprovante, sem Finalização. A correção
     * continua aberta: a Engenharia corrige e aprova de novo.
     *
     * Serve à interface, que mantém o botão visível e o desabilita com este
     * motivo; usa a relação já carregada pela página e só consulta o banco sem
     * ela. A recusa em si confere de novo, sob o lock da medição.
     */
    public function terminalRejectionBlockReason(Measurement $measurement): ?string
    {
        if ($this->unifiedStage($measurement) !== self::STAGE_ENGINEERING) {
            return null;
        }

        if ($measurement->isRevision() && $measurement->isEffectiveRevision()) {
            return self::TERMINAL_REJECTION_OF_EFFECTIVE_REVISION;
        }

        $hasPayment = $measurement->relationLoaded('payments')
            ? $measurement->payments->isNotEmpty()
            : $measurement->payments()->exists();

        return $hasPayment ? self::TERMINAL_REJECTION_WITH_PAYMENTS : null;
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

    public function canResume(
        Measurement $measurement,
        User $actor,
        ?ResponsibilityAuthorizationCapture $capture = null,
    ): bool {
        $stage = $this->unifiedStage($measurement);

        return $measurement->status === 'paused'
            && $stage >= self::STAGE_ENGINEERING
            && $stage <= self::STAGE_PAYMENT
            && $this->authorizes($actor, $measurement, $stage, $capture);
    }

    public function canRegisterPayment(
        Measurement $measurement,
        User $actor,
        ?ResponsibilityAuthorizationCapture $capture = null,
    ): bool {
        $hasPendingReview = $measurement->relationLoaded('reviews')
            ? $measurement->reviews->contains(fn (MeasurementReview $review): bool => (int) $review->stage === self::STAGE_PAYMENT && $review->status === 'pending')
            : $measurement->reviews()->where('stage', self::STAGE_PAYMENT)->where('status', 'pending')->exists();

        return $measurement->status === 'awaiting_payment'
            && (int) $measurement->current_stage === self::STAGE_PAYMENT
            && $hasPendingReview
            && ! $measurement->isFrozenByRevision()
            && $this->authorizes($actor, $measurement, MeasurementResponsibility::PaymentManager, $capture);
    }

    public function canManageReceipts(
        Measurement $measurement,
        User $actor,
        ?ResponsibilityAuthorizationCapture $capture = null,
    ): bool {
        return in_array($measurement->status, ['awaiting_receipt', 'approved'], true)
            && (int) $measurement->current_stage === self::STAGE_FINALIZATION
            && $this->authorizes($actor, $measurement, MeasurementResponsibility::ReceiptUploader, $capture);
    }

    public function canFinalize(
        Measurement $measurement,
        User $actor,
        ?ResponsibilityAuthorizationCapture $capture = null,
    ): bool {
        return in_array($measurement->status, ['approved', 'awaiting_receipt'], true)
            && (int) $measurement->current_stage === self::STAGE_FINALIZATION
            && $this->authorizes($actor, $measurement, MeasurementResponsibility::Finalizer, $capture);
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
            $locked = $this->lockMeasurementWithOperation($measurement);

            // A revisão entra no fluxo pelo envio do serviço de revisões, que
            // a passa a "em análise" antes, na mesma transação, e a conduz quem
            // revisa -- não quem envia medições.
            if ($locked->isRevision()) {
                if (! $this->authorization->canReviseMeasurement($actor, $locked)) {
                    throw new AuthorizationException('Você não pode revisar medições desta operação.');
                }

                if ($locked->revisionStatus() !== MeasurementRevisionStatus::UnderReview) {
                    throw $this->invalidState($locked, 'A revisão entra no fluxo pelo envio da revisão.');
                }
            } elseif (! $this->authorization->canCreateMeasurement($actor, $locked->operation)) {
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

        $this->notifyUsers($locked, $locked->isRevision() ? 'revision_submitted' : 'submitted', [$locked->operation->stageResponsibleId(self::STAGE_ENGINEERING)]);
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
            // A revisão trava a Operation e a família inteira (a vigente que ela
            // vai substituir inclusive) na Engenharia e na Compliance: a
            // Engenharia confere a ocupação da linha pela vigente e o teto sem
            // ela, e a Compliance faz a revisão valer. A identidade da revisão é
            // imutável, então ler `revision_number` antes do lock é seguro.
            $members = null;

            if ($measurement->isRevision() && in_array($expectedStage, [self::STAGE_ENGINEERING, 3], true)) {
                ['members' => $members] = $this->revisions()->lockFamilyWithOperation($measurement);
                $locked = $this->lockedMember($members, $measurement);
            } else {
                $locked = $expectedStage === self::STAGE_ENGINEERING
                    ? $this->lockMeasurementWithOperation($measurement)
                    : $this->lockMeasurement($measurement);
            }

            $stage = $this->unifiedStage($locked);

            $this->assertExpectedState($locked, $expectedRevision, $expectedStage);
            $this->assertNotFrozenByRevision($locked);

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

            $unpaidPlanSets = [];
            $revisionOverpayments = [];

            if ($stage === self::STAGE_PAYMENT) {
                $this->ensureValidPaymentExists($locked);
                $unpaidPlanSets = $this->ensureUnpaidPlanSetsAreJustified($locked, $notes);
                $revisionOverpayments = $this->ensureRevisionOverpaymentsAreJustified($locked, $notes);
            }

            $fromStatus = $locked->status;

            $review->forceFill([
                'reviewer_user_id' => $actor->getKey(),
                'status' => 'approved',
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'reviewed_at' => now(),
            ])->save();

            // A Compliance aprovou a revisão em análise: ela passa a valer agora,
            // antes de a situação do fluxo dela avançar para a etapa Pagamento.
            if ($stage === 3 && $locked->isRevision() && $locked->isPendingRevision()) {
                if (! $members instanceof EloquentCollection) {
                    throw $this->invalidState($locked, 'A revisão só passa a valer com a família travada.');
                }

                $this->revisions()->makeEffective(
                    $locked,
                    $members,
                    $actor,
                    $this->authorization->resolveAuthorization($actor, $locked, 3),
                );
            }

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
                $toStatus = app(MeasurementReceiptEvidenceService::class)->allPaymentsApproved($locked) ? 'approved' : 'awaiting_receipt';

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

            $approval = [
                'stage' => $stage,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $notes,
                'responsibility' => $this->authorization->responsibilityForStage($stage),
                'expected_responsible_user_id' => $locked->operation->stageResponsibleId($stage),
            ];

            if ($stage === self::STAGE_PAYMENT) {
                $approval['unpaid_plan_sets'] = $unpaidPlanSets;

                if ($locked->isRevision()) {
                    $approval['revision_overpayments'] = $revisionOverpayments;
                }
            }

            $this->audit($locked, $actor, 'measurement_stage_approved', $approval);

            // A revisão vigente reaprovada pela Engenharia (depois de devolvida)
            // tem um registro novo: a diferença financeira é recalculada sobre
            // ele, e a anterior fica como histórico.
            if ($stage === self::STAGE_ENGINEERING && $locked->isRevision() && $locked->isEffectiveRevision()) {
                $differences = $this->revisions()->recordDifferences($locked, $members);

                $this->audit($locked, $actor, 'measurement_revision_financial_difference_recomputed', [
                    'stage' => self::STAGE_ENGINEERING,
                    'differences' => $differences->map(fn ($difference): array => [
                        'plan_set_id' => (int) $difference->plan_set_id,
                        'computation' => (int) $difference->computation,
                        'engineering_snapshot_sha256' => $difference->engineering_snapshot_sha256,
                        'financial_difference_amount' => $difference->financial_difference_amount === null ? null : (string) $difference->financial_difference_amount,
                        'difference_type' => $difference->difference_type?->value,
                        'unresolved_overpayment_amount' => (string) $difference->unresolved_overpayment_amount,
                    ])->values()->all(),
                ]);
            }

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

    /**
     * Recusa a etapa pendente: na Engenharia encerra a medição; nas demais,
     * devolve à etapa anterior.
     *
     * A recusa que encerra é negada a medição com pagamento registrado
     * ({@see self::terminalRejectionBlockReason()}). A leitura dos pagamentos
     * sob o lock da medição basta: pagamento só nasce em
     * {@see self::registerPayments()}, sob esse mesmo lock e com a medição na
     * etapa Pagamento -- nunca na Engenharia que está sendo recusada.
     */
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
            // A recusa terminal (na Engenharia) solta as medições previstas que a
            // medição ocupava (Measurement::updated), e a disponibilidade delas
            // é lida pelo envio sob o lock da Operation: a recusa trava a
            // Operation antes, como o envio e a aprovação da Engenharia. As
            // demais recusas só devolvem a etapa e travam só a medição. A
            // recusa de uma revisão na Engenharia trava a família: a vigente
            // pode ganhar a pausa do intervalo em que ficou suspensa.
            $members = null;

            if ($measurement->isRevision() && $expectedStage === self::STAGE_ENGINEERING) {
                ['members' => $members] = $this->revisions()->lockFamilyWithOperation($measurement);
                $locked = $this->lockedMember($members, $measurement);
            } else {
                $locked = $expectedStage === self::STAGE_ENGINEERING
                    ? $this->lockMeasurementWithOperation($measurement)
                    : $this->lockMeasurement($measurement);
            }

            $stage = $this->unifiedStage($locked);

            $this->assertExpectedState($locked, $expectedRevision, $expectedStage);
            $this->assertNotFrozenByRevision($locked);

            $this->authorizeStageDecision($locked, $actor, $stage);
            $review = $this->lockPendingReview($locked, $stage);

            if ($stage === self::STAGE_ENGINEERING && $locked->isRevision() && $locked->isEffectiveRevision()) {
                throw $this->invalidState($locked, self::TERMINAL_REJECTION_OF_EFFECTIVE_REVISION);
            }

            if ($stage === self::STAGE_ENGINEERING && $locked->payments()->exists()) {
                throw $this->invalidState($locked, self::TERMINAL_REJECTION_WITH_PAYMENTS);
            }

            $closesPendingRevision = $stage === self::STAGE_ENGINEERING
                && $locked->isRevision()
                && $locked->isPendingRevision();

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
                // A revisão recusada na Engenharia encerra como revisão também, na
                // mesma gravação: a vigente continua sendo a anterior.
                $locked->forceFill(array_merge([
                    'status' => $toStatus,
                    'analyzed_by' => $actor->getKey(),
                    'analyzed_at' => now(),
                ], $closesPendingRevision ? [
                    'revision_status' => MeasurementRevisionStatus::Rejected,
                    'revision_closed_at' => now(),
                    'revision_closed_by' => $actor->getKey(),
                    'revision_closed_reason' => $notes,
                ] : []))->save();
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

            if ($closesPendingRevision) {
                if (! $members instanceof EloquentCollection) {
                    throw $this->invalidState($locked, 'A revisão só é recusada com a família travada.');
                }

                $this->revisions()->afterPendingRevisionRejected(
                    $locked,
                    $members,
                    $actor,
                    $notes,
                    $this->authorization->resolveAuthorization($actor, $locked, self::STAGE_ENGINEERING),
                );
            }

            return compact('locked', 'stage', 'targetStage', 'closesPendingRevision');
        });

        /** @var Measurement $locked */
        $locked = $result['locked'];

        // A revisão recusada não encerra a medição: a vigente segue. Quem criou
        // a revisão é avisado; a lista de recusa da Engenharia não, porque ela
        // trata do encerramento de medições.
        if ($result['closesPendingRevision']) {
            $this->notifyUsers($locked, 'revision_rejected', [$locked->revision_created_by, $locked->uploaded_by]);

            return;
        }

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
            $this->assertNotFrozenByRevision($locked);

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
     * Registra os pagamentos da etapa Pagamento.
     *
     * Trava a Operation antes da medição ({@see self::lockMeasurementWithOperation()}):
     * o registro trava os planos aprovados e insere `measurement_payments`, filho
     * de `operations` -- a chave estrangeira pede lock na operação no INSERT.
     * Travando só a medição, o pedido da Operation chegava por último, depois
     * da avaliação financeira, e fechava ciclo com quem a trava primeiro: a
     * aprovação da Engenharia de outra medição (Operation, depois os planos), o
     * encerramento da operação (Operation, depois as medições) e o envio de
     * medição (Operation, depois os arquivos que referenciam os planos).
     *
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
            $locked = $this->lockMeasurementWithOperation($measurement);

            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_PAYMENT, 'awaiting_payment');
            $this->assertNotFrozenByRevision($locked);

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

            // Os nomes vão para a mensagem: sem eles a pessoa lia a chave
            // técnica ("O campo payments.0.amount deve ser maior que 0.").
            $validated = Validator::make(['payments' => $rows], [
                'payments' => ['required', 'array', 'min:1'],
                'payments.*.pay_date' => ['required', 'date'],
                'payments.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99'],
                'payments.*.method' => ['nullable', 'string', 'max:255'],
                'payments.*.notes' => ['nullable', 'string'],
                'payments.*.plan_set_id' => ['nullable', 'integer'],
                'payments.*.financial_rule_id' => ['nullable', 'integer'],
                'payments.*.financial_justification' => ['nullable', 'string', 'max:5000'],
                'payments.*.financial_support' => ['nullable', 'file', 'max:10240'],
            ], [], [
                'payments' => 'pagamentos',
                'payments.*.pay_date' => 'data do pagamento',
                'payments.*.amount' => 'valor do pagamento',
                'payments.*.method' => 'método',
                'payments.*.notes' => 'observações',
                'payments.*.plan_set_id' => 'empreendimento',
                'payments.*.financial_rule_id' => 'regra financeira',
                'payments.*.financial_justification' => 'justificativa da divergência',
                'payments.*.financial_support' => 'documento de suporte',
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

            $payments = collect($validated)->map(function (array $row, int $index) use ($locked, $actor, $defaultPlanSetId): MeasurementPayment {
                $row['plan_set_id'] ??= $defaultPlanSetId;
                $row['pay_date'] = Carbon::parse($row['pay_date'])->toDateString();
                // Na revisão, cada linha é conferida contra o saldo em aberto já
                // descontado do que a família pagou antes e das linhas anteriores
                // deste mesmo registro: o que já foi pago não se paga de novo.
                $this->revisions()->assertRevisionPaymentFits($locked, $row, $index);
                try {
                    $assessment = app(MeasurementPaymentFinancialService::class)->assess($locked, $row);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => ["payments.{$index}.{$field}" => $messages])->all());
                }

                return $locked->payments()->create([
                    'operation_id' => $locked->operation_id,
                    'plan_set_id' => $row['plan_set_id'],
                    'pay_date' => $row['pay_date'],
                    'amount' => $row['amount'],
                    'method' => $row['method'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'created_by' => $actor->getKey(),
                    'financial_rule_id' => $assessment['rule']['id'] ?? null,
                    'financial_assessment' => $assessment,
                ]);
            })->values();

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
        UploadedFile $file,
        ?int $expectedEvidenceId = null,
        ?string $correctionReason = null,
        ?int $expectedRevision = null,
    ): void {
        app(MeasurementReceiptEvidenceService::class)->upload(
            $payment,
            $actor,
            $file,
            $expectedEvidenceId,
            $correctionReason,
            $expectedRevision,
        );
    }

    /** @param array<string, mixed> $data */
    public function reassessPayment(MeasurementPayment $payment, User $actor, array $data, int $expectedRevision): void
    {
        DB::transaction(function () use ($payment, $actor, $data, $expectedRevision): void {
            $locked = $this->lockMeasurement($payment->measurement);
            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_PAYMENT, 'awaiting_payment');
            $this->assertNotFrozenByRevision($locked);
            if (! $this->authorization->canRegisterPayment($actor, $locked)) {
                throw new AuthorizationException;
            }
            $this->ensureEngineeringSnapshotTopLevelIsIntact($locked);
            $payment = $locked->payments()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $assessment = app(MeasurementPaymentFinancialService::class)->assess($locked, array_merge($data, [
                'plan_set_id' => $payment->plan_set_id, 'amount' => $payment->amount, 'pay_date' => $payment->pay_date->toDateString(),
            ]), $payment);
            $payment->update(['financial_rule_id' => $assessment['rule']['id'] ?? null, 'financial_assessment' => $assessment]);
            $this->advanceRevision($locked);
            $this->audit($locked, $actor, 'measurement_payment_reassessed', [
                'payment_id' => $payment->id, 'assessment' => $assessment, 'responsibility' => 'payment_manager_user_id',
            ]);
        });
    }

    public function deleteReceipt(
        MeasurementPayment $payment,
        User $actor,
        ?int $expectedRevision = null,
        ?string $expectedStatus = null,
    ): never {
        if (! $this->authorization->canManageReceipts($actor, $payment->measurement)) {
            throw new AuthorizationException;
        }

        throw new MeasurementWorkflowException('Comprovantes não podem ser excluídos. Envie uma nova versão com justificativa.');
    }

    /**
     * Encerra a medição depois de reconferir aprovações, comprovantes, o
     * contexto da Engenharia, a integridade dos arquivos e o enquadramento
     * financeiro -- inclusive a ausência justificada de pagamento de algum
     * empreendimento, que exige o mesmo aceite expresso das divergências.
     *
     * Trava a Operation antes da medição ({@see self::lockMeasurementWithOperation()}):
     * a conferência trava planos, obras e linhas, os mesmos que a aprovação da
     * Engenharia de outra medição trava depois da Operation. Sem a Operation à
     * frente, a ordem entre planos e entre linhas dependeria do plano de
     * execução do banco, e as duas podiam se travar em ciclo.
     */
    public function finalize(
        Measurement $measurement,
        User $actor,
        ?int $expectedRevision = null,
        ?string $expectedStatus = null,
        bool $acceptFinancialExceptions = false,
    ): void {
        $expectedRevision ??= (int) $measurement->workflow_revision;
        $expectedStatus ??= (string) $measurement->status;

        $locked = DB::transaction(function () use ($measurement, $actor, $expectedRevision, $expectedStatus, $acceptFinancialExceptions): Measurement {
            $locked = $this->lockMeasurementWithOperation($measurement);

            $this->assertExpectedState($locked, $expectedRevision, self::STAGE_FINALIZATION, $expectedStatus);

            if (! $this->canFinalize($locked, $actor)) {
                $this->throwAuthorizationOrState(
                    $this->authorization->canFinalize($actor, $locked),
                    $locked,
                    'A medição ainda não está formalmente pronta para Finalização.',
                );
            }

            app(MeasurementReceiptEvidenceService::class)->ensurePaymentsApproved($locked);

            $approvedStages = $locked->reviews()
                ->whereIn('stage', [1, 2, 3, self::STAGE_PAYMENT])
                ->where('status', 'approved')
                ->distinct('stage')
                ->count('stage');

            if ($approvedStages !== self::MAX_STAGE) {
                throw $this->invalidState($locked, 'Todas as etapas anteriores precisam estar formalmente aprovadas.');
            }

            $this->ensureValidPaymentExists($locked);

            $this->ensureEngineeringCoverageIsIntact($locked);
            $this->ensureStoredFilesAreIntact($locked);

            $financial = app(MeasurementPaymentFinancialService::class);
            $financialExceptions = $financial->acceptForFinalization($locked, $acceptFinancialExceptions);
            $unpaidPlanSetsAccepted = $financial->acceptUnpaidPlanSetsForFinalization($locked, $acceptFinancialExceptions);
            $revisionOverpaymentsAccepted = $financial->acceptRevisionOverpaymentsForFinalization($locked, $acceptFinancialExceptions);

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
                'financial_exceptions_accepted' => $financialExceptions,
                'unpaid_plan_sets_accepted' => $unpaidPlanSetsAccepted,
                ...($locked->isRevision() ? [
                    'revision_overpayments_accepted' => $revisionOverpaymentsAccepted,
                    'revision_positions' => array_map(
                        fn ($position): array => $position->toArray(),
                        $this->revisions()->positions($locked),
                    ),
                ] : []),
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

    /**
     * Trava só a medição, para os caminhos que não pedem nada à operação:
     * aprovações das etapas 2 a 4, recusas que devolvem a etapa, devoluções da
     * Finalização, pausas, retomadas e a reavaliação de pagamento. Nenhum deles
     * trava plano, versão, obra ou linha nem insere linha filha de
     * `operations`, então não fecham ciclo com quem trava a Operation primeiro.
     * Um caminho que passe a fazer isso usa
     * {@see self::lockMeasurementWithOperation()} -- como a recusa terminal na
     * Engenharia, que solta as medições previstas da medição.
     */
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
     * Trava a Operation e depois a medição: o começo da ordem canônica de locks
     * do módulo, a mesma do lifecycle da operação, da mutação de contexto, do
     * envio e da edição de medição e das delegações com escopo na operação --
     *
     *   Operation (X) → medições por id → revisões e pausas → planos (o
     *   plano, depois as versões dele por id) → obras → { linhas, arquivos da
     *   medição } → pagamentos → regra financeira → evidências.
     *
     * Linhas e arquivos da medição só são travados por quem já segura a
     * Operation, e por isso a ordem entre eles é indiferente: a aprovação da
     * Engenharia trava os arquivos e depois a linha de cada um, a Finalização
     * trava as linhas e depois os arquivos, e as duas nunca correm juntas na
     * mesma operação. É a Operation que serializa; quem não a segura não está
     * protegido por esta ordem.
     *
     * Quem trava plano, versão, obra, linha ou arquivo da medição, ou insere
     * linha filha de `operations` (pagamentos, planos, versões, linhas,
     * medições, destinatários de recusa, delegações), trava a Operation antes
     * -- o INSERT do filho pede lock na operação pela chave estrangeira. Aqui
     * isso vale para o início da análise, a aprovação da Engenharia, o registro
     * de pagamento e a Finalização; a edição da medição trava as duas na
     * própria página, no `beforeValidate()` de Editar Medição, antes de gravar
     * os arquivos, e o envio trava a Operation no `beforeValidate()` de Enviar
     * Medição; o resto do fluxo usa {@see self::lockMeasurement()}. Não há
     * retry: a ordem é a defesa, e uma repetição esconderia a regressão que a
     * quebrar.
     *
     * Toda escrita de plano, de versão e de linha do cronograma passa por
     * {@see MeasurementPlanVersionService} -- criação e edição do plano, revisão,
     * edição do rascunho, ativação, cancelamento e o `syncDevelopmentPlans` da
     * gravação da operação -- com a Operation travada primeiro; a exclusão do
     * plano trava na própria aba. A escrita de obra (Construction) que mexe no
     * que a Engenharia congela trava antes as operações que a planejam
     * ({@see Construction::save()}).
     *
     * O envio da medição depende da situação da operação, e a situação da
     * operação depende de não haver medição aberta. As duas leituras precisam
     * acontecer sob o mesmo lock da operação, senão uma passa entre a
     * verificação e a gravação da outra.
     *
     * O lock da Operation é a primeira instrução da transação, com o
     * `operation_id` já carregado -- ele é imutável (hook do model). No MySQL
     * em REPEATABLE READ a fotografia da transação nasce na primeira leitura
     * comum; nascendo depois deste lock, ela já inclui tudo o que a aprovação
     * anterior da mesma operação commitou ao liberá-lo. É isso que deixa o
     * progresso físico, o teto de 100% e as guardas da linha lerem o estado
     * atual sem travar as outras medições.
     */
    private function lockMeasurementWithOperation(Measurement $measurement): Measurement
    {
        $operationId = $measurement->getAttribute('operation_id') ?? Measurement::query()
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
            throw $this->invalidState($locked, 'A operação da medição foi alterada durante esta ação.');
        }

        $locked->setRelation('operation', $operation);

        return $locked;
    }

    /**
     * @param  EloquentCollection<int, Measurement>  $members
     */
    private function lockedMember(EloquentCollection $members, Measurement $measurement): Measurement
    {
        $locked = $members->firstWhere('id', (int) $measurement->getKey());

        if (! $locked instanceof Measurement) {
            throw new MeasurementWorkflowException('A medição não foi encontrada.');
        }

        return $locked;
    }

    /**
     * A revisão vigente parada na etapa Pagamento fica suspensa enquanto a
     * revisão dela está em análise: nenhuma transição do fluxo dela. Lido sob o
     * lock da medição; o envio, a recusa e a vigência da revisão travam esta
     * mesma linha.
     */
    private function assertNotFrozenByRevision(Measurement $measurement): void
    {
        if (! $measurement->isFrozenByRevision()) {
            return;
        }

        $successor = $measurement->reviewingSuccessor()->first(['id', 'revision_number']);

        throw $this->invalidState($measurement, sprintf(
            MeasurementRevisionService::FROZEN_WORKFLOW_REFUSAL,
            $successor instanceof Measurement ? $successor->revisionLabel() : 'seguinte',
        ));
    }

    private function revisions(): MeasurementRevisionService
    {
        return app(MeasurementRevisionService::class);
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

    /**
     * Resolve a autorização da ação UMA vez e, quando pedido, deposita a mesma
     * resolução no slot de quem chamou.
     *
     * Chamar `resolveAuthorization()` aqui em vez dos atalhos `can*()` de
     * {@see MeasurementAuthorizationService} não muda a regra -- aqueles atalhos
     * são invólucros de uma linha sobre esta mesma resolução --, mas devolve o
     * objeto resolvido, e não só o booleano, para que o consumidor não precise
     * resolver de novo para saber por qual autoridade a ação foi permitida.
     */
    private function authorizes(
        User $actor,
        Measurement $measurement,
        MeasurementResponsibility|int $responsibility,
        ?ResponsibilityAuthorizationCapture $capture,
    ): bool {
        $authorization = $this->authorization->resolveAuthorization($actor, $measurement, $responsibility);

        $capture?->capture($authorization);

        return $authorization->authorizes();
    }

    private function hasPendingDecisionState(Measurement $measurement, int $stage): bool
    {
        if ($measurement->status === 'paused' || $stage < 1 || $stage > self::STAGE_PAYMENT || $measurement->isFrozenByRevision()) {
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

        // O registro da Engenharia deixou de valer, e a diferença financeira
        // calculada sobre ele também; a reaprovação grava outra.
        if ($target === self::STAGE_ENGINEERING && $measurement->isRevision()) {
            $this->revisions()->supersedeCurrentDifferences($measurement);
        }

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

    /**
     * Se a medição tem o pagamento que a aprovação da etapa Pagamento exige:
     * ao menos um, e todos com valor positivo e data.
     *
     * É a mesma conta da recusa em {@see self::ensureValidPaymentExists()},
     * exposta para o modal da etapa: sem pagamento válido, a justificativa da
     * ausência não resolve, e a tela não deve oferecê-la como saída.
     */
    public function hasValidPayment(Measurement $measurement): bool
    {
        $paymentCount = $measurement->payments()->count();

        // A revisão cuja família já pagou, ou que não tem nada a pagar, não
        // precisa de pagamento novo: o que falta ou sobra é a posição
        // financeira dela, conferida à parte (saldo em aberto justificado, valor
        // pago a maior decidido).
        if ($paymentCount === 0 && $measurement->isRevision()) {
            return $this->revisions()->allowsApprovalWithoutOwnPayment($measurement);
        }

        return $paymentCount > 0
            && $paymentCount === $measurement->payments()
                ->where('amount', '>', 0)
                ->whereNotNull('pay_date')
                ->count();
    }

    private function ensureValidPaymentExists(Measurement $measurement): void
    {
        if (! $this->hasValidPayment($measurement)) {
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

    /**
     * Empreendimento que a Engenharia mediu com valor esperado e que ficou sem
     * pagamento nesta competência só passa pela etapa Pagamento com a
     * justificativa de quem decidiu não pagar, na nota da própria aprovação.
     *
     * A justificativa e o aceite de divergência moram em cada pagamento; um
     * empreendimento sem pagamento não tem onde guardá-los, e por isso
     * atravessava a etapa e a Finalização em silêncio. Reter o pagamento pode
     * ser legítimo (fundo esgotado, retenção contratual, pendência documental),
     * então a etapa não bloqueia: exige o porquê, que o Finalizador precisará
     * aceitar expressamente. A lista volta para a auditoria da aprovação.
     *
     * Leitura simples, sob o lock da medição que a aprovação já detém: o
     * pagamento só nasce sob esse mesmo lock, e nenhum lock novo entra aqui.
     *
     * @return list<array<string, mixed>>
     */
    private function ensureUnpaidPlanSetsAreJustified(Measurement $measurement, ?string $notes): array
    {
        $financial = app(MeasurementPaymentFinancialService::class);
        $unpaid = $financial->unpaidRequiredPlanSets($measurement);

        if ($unpaid !== [] && blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Justifique a ausência de pagamento nesta competência de: '.$financial->describeUnpaidPlanSets($unpaid).'; o Finalizador precisará aceitar expressamente.',
            ]);
        }

        return array_map(
            fn (MeasurementFinancialReconciliationLine $line): array => $line->toArray(),
            $unpaid,
        );
    }

    /**
     * A revisão que aprova menos do que a família já pagou deixa valor pago a
     * maior. O sistema não tem estorno, devolução nem compensação, e os
     * pagamentos registrados nunca são alterados: a etapa Pagamento não
     * bloqueia, mas exige a decisão financeira expressa, na nota da aprovação,
     * que o Finalizador precisará aceitar.
     *
     * @return list<array<string, mixed>>
     */
    private function ensureRevisionOverpaymentsAreJustified(Measurement $measurement, ?string $notes): array
    {
        if (! $measurement->isRevision()) {
            return [];
        }

        $overpayments = $this->revisions()->unresolvedOverpayments($measurement);

        if ($overpayments !== [] && blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Esta revisão aprova menos do que já foi pago antes dela -- '.$this->revisions()->describeOverpayments($overpayments).'. Registre a decisão financeira sobre o valor pago a maior; o Finalizador precisará aceitá-la expressamente. Os pagamentos registrados não são alterados nem estornados.',
            ]);
        }

        return array_map(fn ($position): array => $position->toArray(), $overpayments);
    }

    /**
     * Confere, na Finalização, que cada arquivo e cada comprovante ainda estão
     * no armazenamento e batem com o SHA-256 auditado.
     *
     * Toda recusa vai também para o log, como aviso: a pessoa lê a notificação,
     * mas arquivo que some do armazenamento ou muda de conteúdo é incidente de
     * infraestrutura -- sem o registro, a operação só o descobria pela
     * reclamação de quem não conseguia finalizar. A recusa do comprovante é
     * registrada por {@see MeasurementReceiptEvidenceService::ensureIntegrity()}.
     */
    private function ensureStoredFilesAreIntact(Measurement $measurement): void
    {
        $files = collect();

        if (filled($measurement->storage_path)) {
            $files->push([
                'path' => $measurement->storage_path,
                'disk' => $measurement->resolved_storage_disk,
                'hash' => $measurement->sha256,
                'label' => 'arquivo principal da medição',
                'asset_id' => null,
            ]);
        }

        $measurement->assets()->get()->each(function ($asset) use ($files, $measurement): void {
            try {
                $this->fileValidation->validateStoredAsset($asset->storage_path, $asset->resolved_storage_disk);
            } catch (ValidationException) {
                $this->logFileIntegrityRefusal($measurement, 'arquivo_ausente_ou_invalido', $asset->getKey(), $asset->resolved_storage_disk, $asset->storage_path);

                throw $this->invalidState($measurement, "O arquivo de medição #{$asset->getKey()} é inválido ou está ausente.");
            }

            $files->push([
                'path' => $asset->storage_path,
                'disk' => $asset->resolved_storage_disk,
                'hash' => $asset->sha256,
                'label' => "arquivo de medição #{$asset->getKey()}",
                'asset_id' => $asset->getKey(),
            ]);
        });

        $measurement->payments()->with('currentReceiptEvidence')->get()->each(function (MeasurementPayment $payment) use ($measurement): void {
            try {
                $evidence = $payment->currentReceiptEvidence;

                if ($evidence === null) {
                    throw $this->invalidState($measurement, "O pagamento #{$payment->getKey()} não possui evidência atual.");
                }

                app(MeasurementReceiptEvidenceService::class)->ensureIntegrity($evidence);
            } catch (ValidationException) {
                throw $this->invalidState($measurement, "O comprovante #{$payment->getKey()} é inválido, foi alterado ou está ausente.");
            }
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
                $this->logFileIntegrityRefusal(
                    $measurement,
                    match (true) {
                        ! is_string($hash) || mb_strlen($hash) !== 64 => 'sha256_ausente',
                        ! is_string($actualHash) => 'arquivo_ausente_ou_ilegivel',
                        default => 'sha256_divergente',
                    },
                    $file['asset_id'],
                    $file['disk'],
                    $file['path'],
                );

                throw $this->invalidState(
                    $measurement,
                    "O {$file['label']} está ausente, sem SHA-256 ou não corresponde ao conteúdo auditado.",
                );
            }
        }
    }

    /**
     * Registra no log a recusa de integridade de um arquivo de medição na
     * Finalização, com o que a operação precisa para achar o arquivo: a
     * medição, o arquivo (sem id no arquivo principal legado), o disco e o
     * caminho relativo -- nunca o caminho absoluto nem o conteúdo.
     */
    private function logFileIntegrityRefusal(Measurement $measurement, string $reason, mixed $assetId, ?string $disk, ?string $relativePath): void
    {
        Log::warning('Arquivo de medição recusado na conferência de integridade da Finalização.', [
            'reason' => $reason,
            'measurement_id' => $measurement->getKey(),
            'operation_id' => $measurement->operation_id,
            'asset_id' => $assetId,
            'disk' => $disk,
            'relative_path' => $relativePath,
        ]);
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
        $versions = MeasurementPlanVersion::query()
            ->whereKey($assets->pluck('plan_version_id')->filter()->unique()->values()->all())
            ->get()
            ->keyBy('id');

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
            $version = $asset instanceof MeasurementAsset && $asset->plan_version_id !== null
                ? $versions->get((int) $asset->plan_version_id)
                : null;
            // O Fundo de Obra conferido é o da versão em que a medição foi
            // enviada -- imutável depois de vigente --, e não o vigente hoje: uma
            // revisão de custo ativada no meio do fluxo não invalida a medição.
            // Arquivo de plano sem versão gravada não existe mais e não passa.
            $fundAmount = $version?->construction_fund_amount;

            if (! $planSet instanceof MeasurementPlanSet
                || ! $line instanceof MeasurementPlanLine
                || ! $asset instanceof MeasurementAsset
                || ! $version instanceof MeasurementPlanVersion
                || (int) $line->plan_version_id !== (int) $version->getKey()
                || (array_key_exists('plan_version_id', $requirement)
                    && (! $this->nullableIntMatches($asset->plan_version_id, $requirement['plan_version_id'])
                        || ! $this->nullableIntMatches($line->plan_version_id, $requirement['plan_version_id'])))
                || ! $this->nullableIntMatches($planSet->construction_id, $constructionId)
                || ! $this->nullableDecimalMatches($fundAmount, $requirement['construction_fund_amount'] ?? null)
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
        $authorization = $this->resolveAuthorizationSource(
            $measurement,
            $actor,
            $properties['responsibility'] ?? $properties['stage'] ?? null,
        );
        $delegationContext = $authorization->delegation;

        activity('measurement_workflow')
            ->performedOn($measurement)
            ->causedBy($actor)
            ->withProperties(array_merge([
                'operation_id' => $measurement->operation_id,
                'measurement_id' => $measurement->getKey(),
                'delegated' => $authorization->isDelegated(),
                'delegation_id' => $delegationContext?->getKey(),
                'delegator_user_id' => $delegationContext?->delegator_user_id,
                'delegation_scope' => $delegationContext ? [
                    'type' => $delegationContext->scope_type,
                    'operation_id' => $delegationContext->scope_operation_id,
                    'stage' => $delegationContext->scope_stage,
                    'responsibility' => $delegationContext->scope_responsibility,
                ] : null,
                'admin_override' => $authorization->isAdminOverride(),
                'actual_actor_user_id' => $actor->getKey(),
                'workflow_revision' => (int) $measurement->workflow_revision,
            ], $measurement->isRevision() ? [
                'revision_family_id' => $measurement->familyRootId(),
                'revision_number' => $measurement->revisionNumber(),
            ] : [], $properties))
            ->log($event);
    }

    /**
     * A mesma resolução que autorizou a ação, reaproveitada para descrevê-la:
     * `delegated` e `admin_override` saem de uma origem só, nunca de heurística
     * separada. Eventos sem responsabilidade associada (snapshots de
     * engenharia) não têm origem a atribuir e permanecem com ambas falsas.
     */
    private function resolveAuthorizationSource(
        Measurement $measurement,
        User $actor,
        mixed $responsibility,
    ): ResponsibilityAuthorization {
        $resolved = is_int($responsibility)
            ? MeasurementResponsibility::primaryForStage($responsibility)
            : MeasurementResponsibility::fromOperationColumn((string) $responsibility);

        if (! $resolved instanceof MeasurementResponsibility) {
            return ResponsibilityAuthorization::none();
        }

        return $this->authorization->resolveAuthorization($actor, $measurement, $resolved);
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

        // Os ids vêm de chaves estrangeiras antigas -- quem enviou a medição, quem
        // coordena a operação -- e uma chave estrangeira não sabe se a pessoa ainda
        // trabalha aqui. Notificação nova só para quem está elegível agora; o que já
        // foi enviado continua enviado, e nenhuma Activity antiga é tocada.
        $recipients = User::query()->operational()->whereKey($ids)->get();

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
