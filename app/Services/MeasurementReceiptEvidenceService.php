<?php

namespace App\Services;

use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementResponsibility;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\User;
use App\Support\Delegations\ResponsibilityAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeasurementReceiptEvidenceService
{
    public function __construct(
        private MeasurementAuthorizationService $authorization,
        private DocumentStorageService $storage,
        private MeasurementFileValidationService $validation,
    ) {}

    public function canUpload(Measurement $measurement, User $actor): bool
    {
        return $this->isDocumentaryStage($measurement)
            && $this->authorization->canManageReceipts($actor, $measurement);
    }

    public function canReview(Measurement $measurement, User $actor): bool
    {
        return $this->isDocumentaryStage($measurement)
            && $this->authorization->canFinalize($actor, $measurement);
    }

    public function upload(
        MeasurementPayment $payment,
        User $actor,
        UploadedFile $file,
        ?int $expectedEvidenceId = null,
        ?string $correctionReason = null,
        ?int $expectedRevision = null,
    ): MeasurementPaymentReceiptEvidence {
        return $this->store($payment, $actor, $file, $expectedEvidenceId, $correctionReason, false, $expectedRevision);
    }

    public function correctFinalizedReceipt(
        MeasurementPayment $payment,
        User $actor,
        UploadedFile $file,
        string $correctionReason,
        ?int $expectedEvidenceId,
    ): MeasurementPaymentReceiptEvidence {
        return $this->store($payment, $actor, $file, $expectedEvidenceId, $correctionReason, true, null);
    }

    private function store(
        MeasurementPayment $payment,
        User $actor,
        UploadedFile $file,
        ?int $expectedEvidenceId,
        ?string $correctionReason,
        bool $postFinalization,
        ?int $expectedRevision,
    ): MeasurementPaymentReceiptEvidence {
        $stored = null;

        try {
            return DB::transaction(function () use ($payment, $actor, $file, $expectedEvidenceId, $correctionReason, $postFinalization, $expectedRevision, &$stored): MeasurementPaymentReceiptEvidence {
                $measurement = $this->lockMeasurement((int) $payment->measurement_id);
                $authorization = $this->authorize($measurement, $actor, MeasurementResponsibility::ReceiptUploader);
                $lockedPayment = $measurement->payments()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
                $current = $lockedPayment->receiptEvidences()->lockForUpdate()->first();

                if ($current === null && filled($lockedPayment->receipt_path)) {
                    throw new MeasurementWorkflowException('Materialize o comprovante legado pela migration antes de enviar uma nova versão.');
                }

                if ($postFinalization !== ($measurement->status === 'finalized')) {
                    throw new MeasurementWorkflowException('Use a ação Corrigir comprovante para uma medição finalizada.');
                }

                if ($expectedRevision !== null && $expectedRevision !== (int) $measurement->workflow_revision) {
                    throw new MeasurementWorkflowException('A medição foi alterada. Atualize a página antes de enviar.');
                }

                if (($current?->getKey()) !== $expectedEvidenceId) {
                    throw new MeasurementWorkflowException('O comprovante atual mudou. Atualize a página antes de enviar uma nova versão.');
                }

                $reason = is_string($correctionReason) ? trim($correctionReason) : null;
                Validator::make(['receipt' => $file, 'correction_reason' => $reason, 'original_filename' => $file->getClientOriginalName()], [
                    'receipt' => ['required', 'file', 'extensions:'.implode(',', config('uploads.measurement_receipt.allowed_extensions', [])), 'max:'.config('uploads.measurement_receipt.max_kb', 10240)],
                    'original_filename' => ['required', 'string', 'max:255'],
                    'correction_reason' => [$postFinalization || $current !== null ? 'required' : 'nullable', 'string', 'max:5000'],
                ])->validate();

                $directory = 'measurements/receipts/'.Str::uuid();
                $stored = [
                    'disk' => DocumentStorageService::privateDisk(),
                    'path' => $this->storage->privateDirectoryPath($directory).'/'.$file->hashName(),
                ];
                $this->registerRollbackCompensation($stored);
                $this->storage->storePrivateFile($file, $directory);
                $this->validation->validateReceipt($stored['path'], $stored['disk']);
                $hash = $this->storage->checksum($stored['path'], $stored['disk']);

                if (! is_string($hash) || mb_strlen($hash) !== 64) {
                    throw ValidationException::withMessages(['receipt' => 'Não foi possível calcular o SHA-256 do comprovante.']);
                }

                $metadata = $this->storage->metadata($stored['path'], $stored['disk']);
                $evidence = $lockedPayment->receiptEvidences()->create([
                    'version' => ($current?->version ?? 0) + 1,
                    'supersedes_id' => $current?->getKey(),
                    'storage_disk' => $stored['disk'],
                    'storage_path' => $stored['path'],
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $metadata['mime_type'],
                    'size' => $metadata['size_bytes'],
                    'sha256' => $hash,
                    'uploaded_by' => $actor->getKey(),
                    'uploaded_at' => now(),
                    'review_status' => MeasurementReceiptReviewStatus::Pending,
                    'correction_reason' => $reason,
                    'is_post_finalization' => $postFinalization,
                ]);

                $this->updateOpenDocumentaryState($measurement);
                $this->audit($measurement, $evidence, $actor, $authorization, 'receipt_evidence_uploaded');

                if ($postFinalization) {
                    $this->audit($measurement, $evidence, $actor, $authorization, 'receipt_evidence_corrected_after_finalization');
                }

                return $evidence;
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                $this->discardUnreferencedUpload($stored);
            }

            throw $exception;
        }
    }

    /**
     * Cada transação ancestral precisa compensar o arquivo se desfizer um
     * savepoint já concluído. O commit externo descarta estes callbacks.
     *
     * @param  array{disk: string, path: string}  $stored
     */
    private function registerRollbackCompensation(array $stored): void
    {
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => $this->discardUnreferencedUpload($stored));
            }
        }
    }

    /** @param array{disk: string, path: string} $stored */
    private function discardUnreferencedUpload(array $stored): void
    {
        rescue(function () use ($stored): void {
            if (MeasurementPaymentReceiptEvidence::query()->where('storage_disk', $stored['disk'])
                ->where('storage_path', $stored['path'])->exists()) {
                return;
            }

            if (! Storage::disk($stored['disk'])->delete($stored['path'])) {
                throw new \RuntimeException('Não foi possível compensar o arquivo do upload documental interrompido.');
            }
        }, report: true);
    }

    public function review(
        MeasurementPaymentReceiptEvidence $evidence,
        User $actor,
        MeasurementReceiptReviewStatus $decision,
        bool $confirmed,
        ?string $notes = null,
        ?string $rejectionReason = null,
    ): MeasurementPaymentReceiptEvidence {
        return DB::transaction(function () use ($evidence, $actor, $decision, $confirmed, $notes, $rejectionReason): MeasurementPaymentReceiptEvidence {
            $payment = $evidence->payment()->firstOrFail();
            $measurement = $this->lockMeasurement((int) $payment->measurement_id);
            $authorization = $this->authorize($measurement, $actor, MeasurementResponsibility::Finalizer);
            $lockedPayment = $measurement->payments()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $current = $lockedPayment->receiptEvidences()->lockForUpdate()->first();

            if ($current?->getKey() !== $evidence->getKey() || $current->review_status !== MeasurementReceiptReviewStatus::Pending) {
                throw new MeasurementWorkflowException('Esta versão já foi decidida ou substituída. Atualize a página.');
            }

            if (! in_array($decision, [MeasurementReceiptReviewStatus::Approved, MeasurementReceiptReviewStatus::Rejected], true)) {
                throw ValidationException::withMessages(['decision' => 'Escolha aprovar ou rejeitar o comprovante.']);
            }

            $reason = is_string($rejectionReason) ? trim($rejectionReason) : null;
            Validator::make(['confirmed' => $confirmed, 'notes' => $notes, 'rejection_reason' => $reason], [
                'confirmed' => ['accepted'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'rejection_reason' => [$decision === MeasurementReceiptReviewStatus::Rejected ? 'required' : 'nullable', 'string', 'max:5000'],
            ])->validate();

            if ($decision === MeasurementReceiptReviewStatus::Approved) {
                $this->ensureIntegrity($current);
            }

            $current->forceFill([
                'review_status' => $decision,
                'reviewer_user_id' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'rejection_reason' => $decision === MeasurementReceiptReviewStatus::Rejected ? $reason : null,
            ])->save();

            $this->updateOpenDocumentaryState($measurement);
            $this->audit($measurement, $current, $actor, $authorization, $decision === MeasurementReceiptReviewStatus::Approved ? 'receipt_evidence_approved' : 'receipt_evidence_rejected');

            return $current;
        });
    }

    public function allPaymentsApproved(Measurement $measurement): bool
    {
        // A revisão que não precisou de pagamento novo -- a família já pagou o
        // que ela aprova, o que falta foi justificado, ou não há nada a pagar --
        // não tem comprovante a esperar; a medição sem revisão continua
        // precisando de pagamento.
        if (! $measurement->payments()->exists()) {
            return $measurement->isRevision()
                && app(MeasurementRevisionService::class)->allowsApprovalWithoutOwnPayment($measurement);
        }

        return $measurement->payments()->whereDoesntHave('currentReceiptEvidence', fn ($query) => $query
            ->where('review_status', MeasurementReceiptReviewStatus::Approved->value))->doesntExist();
    }

    public function ensurePaymentsApproved(Measurement $measurement): void
    {
        if ($measurement->payments()->whereHas('currentReceiptEvidence', fn ($query) => $query
            ->where('review_status', MeasurementReceiptReviewStatus::Rejected->value))->exists()) {
            throw new MeasurementWorkflowException('Existem comprovantes rejeitados que precisam ser substituídos.');
        }

        if (! $this->allPaymentsApproved($measurement)) {
            throw new MeasurementWorkflowException('Todos os pagamentos precisam de comprovante atual aprovado. Existem comprovantes ausentes ou aguardando aprovação documental.');
        }
    }

    /**
     * Confere que o comprovante ainda está num armazenamento permitido e bate
     * com o SHA-256 gravado no envio -- na conferência documental, na
     * Finalização e no download.
     *
     * A recusa continua chegando à pessoa como antes, e agora vai também para
     * o log como aviso: comprovante que some do armazenamento ou muda de
     * conteúdo é incidente de infraestrutura, e sem o registro a operação só o
     * descobria pela reclamação de quem não conseguia conferir ou finalizar.
     *
     * @throws ValidationException
     */
    public function ensureIntegrity(MeasurementPaymentReceiptEvidence $evidence, bool $allowMissingLegacyHash = false): void
    {
        try {
            $this->validation->validateStoredReceipt($evidence->storage_path, $evidence->resolved_storage_disk);
        } catch (ValidationException $exception) {
            $this->logIntegrityRefusal($evidence, 'arquivo_ausente_ou_invalido');

            throw $exception;
        }

        if ($allowMissingLegacyHash && $evidence->sha256 === null && $evidence->original_filename === null
            && $evidence->version === 1 && $evidence->supersedes_id === null && ! $evidence->is_post_finalization) {
            return;
        }

        $actualHash = $this->storage->checksum($evidence->storage_path, $evidence->resolved_storage_disk);

        if (! is_string($evidence->sha256) || mb_strlen($evidence->sha256) !== 64
            || ! is_string($actualHash) || ! hash_equals($evidence->sha256, $actualHash)) {
            $this->logIntegrityRefusal($evidence, match (true) {
                ! is_string($evidence->sha256) || mb_strlen($evidence->sha256) !== 64 => 'sha256_ausente',
                ! is_string($actualHash) => 'arquivo_ausente_ou_ilegivel',
                default => 'sha256_divergente',
            });

            throw ValidationException::withMessages(['receipt' => 'O comprovante está ausente, sem SHA-256 ou não corresponde ao conteúdo auditado.']);
        }
    }

    /**
     * O que a operação precisa para achar o comprovante: a medição, o
     * pagamento, a versão, o disco e o caminho relativo -- nunca o caminho
     * absoluto nem o conteúdo.
     */
    private function logIntegrityRefusal(MeasurementPaymentReceiptEvidence $evidence, string $reason): void
    {
        Log::warning('Comprovante de pagamento recusado na conferência de integridade.', [
            'reason' => $reason,
            'measurement_id' => $evidence->payment()->value('measurement_id'),
            'payment_id' => $evidence->measurement_payment_id,
            'evidence_id' => $evidence->getKey(),
            'version' => $evidence->version,
            'disk' => $evidence->resolved_storage_disk,
            'relative_path' => $evidence->storage_path,
        ]);
    }

    public function documentaryStatus(Measurement $measurement): string
    {
        $measurement->loadMissing('payments.currentReceiptEvidence');
        $corrections = $measurement->payments->pluck('currentReceiptEvidence')->filter()
            ->filter(fn (MeasurementPaymentReceiptEvidence $evidence): bool => $evidence->is_post_finalization);

        return match (true) {
            $corrections->contains('review_status', MeasurementReceiptReviewStatus::Rejected)
                && $corrections->contains('review_status', MeasurementReceiptReviewStatus::Pending) => 'Correções documentais: substituir e conferir',
            $corrections->contains('review_status', MeasurementReceiptReviewStatus::Rejected) => 'Correção documental rejeitada',
            $corrections->contains('review_status', MeasurementReceiptReviewStatus::Pending) => 'Correção documental pendente',
            $corrections->isNotEmpty() => 'Correção documental regularizada',
            default => 'Sem pendência',
        };
    }

    /** @return array{missing: int, rejected: int, pending: int, approved: int} */
    public function documentarySummary(Measurement $measurement): array
    {
        $measurement->loadMissing('payments.currentReceiptEvidence');
        $counts = ['missing' => 0, 'rejected' => 0, 'pending' => 0, 'approved' => 0];

        foreach ($measurement->payments as $payment) {
            $evidence = $payment->currentReceiptEvidence;

            if ($measurement->status === 'finalized' && ! $evidence?->is_post_finalization) {
                continue;
            }

            $key = match ($evidence?->review_status) {
                MeasurementReceiptReviewStatus::Pending => 'pending',
                MeasurementReceiptReviewStatus::Approved => 'approved',
                MeasurementReceiptReviewStatus::Rejected => 'rejected',
                default => 'missing',
            };
            $counts[$key]++;
        }

        return $counts;
    }

    /** @return list<MeasurementResponsibility> */
    public function pendingResponsibilities(Measurement $measurement): array
    {
        if (! $this->isDocumentaryStage($measurement)) {
            return [];
        }

        $counts = $this->documentarySummary($measurement);
        $responsibilities = [];

        if ($counts['missing'] + $counts['rejected'] > 0
            || ($measurement->status === 'awaiting_receipt' && $measurement->payments->isEmpty())) {
            $responsibilities[] = MeasurementResponsibility::ReceiptUploader;
        }

        if ($counts['pending'] > 0
            || ($measurement->status !== 'finalized' && $responsibilities === [])) {
            $responsibilities[] = MeasurementResponsibility::Finalizer;
        }

        return $responsibilities;
    }

    public function pendingActionLabel(Measurement $measurement, MeasurementResponsibility $responsibility): string
    {
        $counts = $this->documentarySummary($measurement);

        if ($responsibility === MeasurementResponsibility::ReceiptUploader) {
            return match (true) {
                $measurement->status === 'finalized' => 'Substituir correção documental rejeitada',
                $counts['rejected'] > 0 && $counts['missing'] > 0 => 'Enviar e substituir comprovantes',
                $counts['rejected'] > 0 => 'Substituir comprovante rejeitado',
                default => 'Enviar comprovante',
            };
        }

        return match (true) {
            $measurement->status === 'finalized' => 'Conferir correção documental',
            $counts['pending'] > 0 => 'Conferir comprovante',
            default => 'Finalizar medição',
        };
    }

    private function lockMeasurement(int $measurementId): Measurement
    {
        return Measurement::query()->with('operation')->whereKey($measurementId)->lockForUpdate()->firstOrFail();
    }

    private function isDocumentaryStage(Measurement $measurement): bool
    {
        return (int) $measurement->current_stage === MeasurementWorkflow::STAGE_FINALIZATION
            && in_array($measurement->status, ['awaiting_receipt', 'approved', 'finalized'], true);
    }

    private function authorize(Measurement $measurement, User $actor, MeasurementResponsibility $responsibility): ResponsibilityAuthorization
    {
        $authorization = $this->authorization->resolveAuthorization($actor, $measurement, $responsibility);

        if (! $authorization->authorizes()) {
            throw new AuthorizationException;
        }

        if (! $this->isDocumentaryStage($measurement)) {
            throw new MeasurementWorkflowException('A conferência documental está disponível somente na etapa de Finalização.');
        }

        return $authorization;
    }

    private function updateOpenDocumentaryState(Measurement $measurement): void
    {
        if ($measurement->status === 'finalized') {
            return;
        }

        $measurement->forceFill([
            'status' => $this->allPaymentsApproved($measurement) ? 'approved' : 'awaiting_receipt',
            'workflow_revision' => (int) $measurement->workflow_revision + 1,
        ])->saveQuietly();
    }

    private function audit(
        Measurement $measurement,
        MeasurementPaymentReceiptEvidence $evidence,
        User $actor,
        ResponsibilityAuthorization $authorization,
        string $event,
    ): void {
        activity('measurement_evidence')
            ->performedOn($evidence)
            ->causedBy($actor)
            ->withProperties([
                'measurement_id' => $measurement->getKey(),
                'operation_id' => $measurement->operation_id,
                'payment_id' => $evidence->measurement_payment_id,
                'evidence_id' => $evidence->getKey(),
                'version' => $evidence->version,
                'sha256' => $evidence->sha256,
                'supersedes_id' => $evidence->supersedes_id,
                'previous_version' => $evidence->version > 1 ? $evidence->version - 1 : null,
                'review_status' => $evidence->review_status->value,
                'reviewer_user_id' => $evidence->reviewer_user_id,
                'review_notes' => $evidence->review_notes,
                'rejection_reason' => $evidence->rejection_reason,
                'correction_reason' => $evidence->correction_reason,
                'is_post_finalization' => $evidence->is_post_finalization,
                'actor_id' => $actor->getKey(),
                'authorization_source' => $authorization->source->value,
                'delegation_id' => $authorization->delegation?->getKey(),
                'delegator_user_id' => $authorization->delegation?->delegator_user_id,
                'timestamp' => now()->toIso8601String(),
            ])->log($event);
    }
}
