<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementRevisionAdjustmentStatus;
use App\Enums\MeasurementRevisionDifferenceType;
use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementReview;
use App\Models\MeasurementRevisionDifference;
use App\Models\Operation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Support\BusinessTime;
use App\Support\Delegations\ResponsibilityAuthorization;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Revisões de medição: a R1, a R2... de uma medição lógica.
 *
 * Cada revisão é uma medição com fluxo próprio (Engenharia, Gestão,
 * Compliance, Pagamento, Finalização), herdando o contexto congelado da
 * original -- competência, versão do plano, medição prevista e Fundo de Obra
 * -- e os arquivos da revisão que substitui. Nada da revisão anterior é
 * reescrito: o snapshot, as análises, os arquivos e os pagamentos dela ficam
 * como estavam.
 *
 * Marcos:
 * - criada (rascunho): editável, não substitui nada, não congela nada;
 * - enviada (em análise): o fluxo da revisão começa; se a vigente ainda tem o
 *   fluxo aberto (etapa Pagamento sem pagamento, ou Finalização com pagamento
 *   e documentação completa), o fluxo dela fica suspenso enquanto a revisão
 *   corre;
 * - aprovada pela Compliance = vigente: Engenharia, Gestão e Compliance
 *   aprovaram a correção, e a revisão passa a ser a contribuição física da
 *   medição lógica, numa transação só: a anterior vira substituída (com o
 *   fluxo aberto dela encerrado, e a análise que esperava decisão encerrada
 *   sem decisão), a ocupação da linha passa para a revisão, a linha do
 *   cronograma passa a mostrar a revisão e a diferença financeira é
 *   registrada;
 * - etapa Pagamento e Finalização da própria revisão resolvem (pagamento
 *   complementar) ou reconhecem expressamente o ajuste financeiro -- sem
 *   estorno, devolução nem compensação, que o sistema não tem: o valor pago a
 *   maior reconhecido continua em aberto.
 *
 * Ordem de locks, a canônica do módulo: Operation primeiro (primeira
 * instrução da transação), depois as medições da família por id, pela chave
 * primária -- nunca por intervalo de um índice não único --, depois as
 * análises, planos, obras, linhas e arquivos, pagamentos.
 */
class MeasurementRevisionService
{
    public const REASON_MAX_LENGTH = 5000;

    public const ELIGIBILITY_REFUSAL = 'Só se revisa a revisão vigente de uma medição finalizada; a que espera o pagamento na etapa Pagamento, sem pagamento registrado; ou a que tem pagamento registrado e está na Finalização com a documentação financeira completa (todos os comprovantes aprovados). Durante a análise da Engenharia, da Gestão e da Compliance, a correção segue pelo próprio fluxo.';

    /**
     * Pago e ainda na etapa Pagamento: os pagamentos viram o histórico da
     * família, e precisam estar comprovados antes.
     */
    public const PAID_PAYMENT_STAGE_REFUSAL = 'Esta medição tem pagamento registrado e a etapa Pagamento ainda está aberta. Para revisá-la, aprove a etapa Pagamento e conclua a conferência dos comprovantes (situação "Documentação financeira completa"): a revisão preserva os pagamentos como histórico, e eles precisam estar comprovados.';

    public const PAID_RECEIPTS_PENDING_REFUSAL = 'Há comprovante de pagamento ausente, em conferência ou rejeitado nesta medição. Conclua a conferência documental (situação "Documentação financeira completa") antes de revisá-la: a revisão preserva os pagamentos como histórico, e eles precisam estar comprovados.';

    public const PAUSED_REFUSAL = 'A medição está pausada: retome a etapa antes de revisá-la.';

    /**
     * Argumento: a etapa em que a medição paga está de novo em análise.
     */
    public const PAID_IN_ANALYSIS_REFUSAL = 'Esta medição tem pagamento registrado e está de novo em análise (etapa %s). Aprove as etapas até a Finalização, com a documentação financeira completa, e então crie a revisão: a correção dos dados aprovados de uma medição paga é sempre uma revisão formal.';

    /**
     * Argumento: a etapa em que a medição sem pagamento está em análise.
     */
    public const IN_ANALYSIS_REFUSAL = 'A medição está em análise (etapa %s): a correção segue pelo próprio fluxo. A revisão formal é da medição finalizada, da parada na etapa Pagamento sem pagamento, ou da paga com a documentação financeira completa.';

    public const UNPAID_FINALIZATION_REFUSAL = 'Esta revisão está na Finalização sem pagamento próprio. Para revisá-la, finalize-a ou devolva-a à etapa Pagamento.';

    public const FROZEN_WORKFLOW_REFUSAL = 'Esta medição tem a revisão %s em análise: o fluxo dela fica suspenso até a revisão ser recusada ou passar a valer.';

    public const STALE_REVISION_REFUSAL = 'A medição mudou depois que esta janela foi aberta. Atualize a página.';

    public function __construct(
        private MeasurementAuthorizationService $authorization,
        private MeasurementPhysicalProgressService $physicalProgress,
        private MeasurementFinancialReconciliationService $reconciliation,
        private DocumentStorageService $storage,
        private MeasurementFileValidationService $fileValidation,
    ) {}

    /**
     * Por que não se pode criar uma revisão desta medição agora -- para a tela
     * dizer antes de tentar --, ou `null`. A criação confere tudo de novo sob
     * o lock da operação e da família.
     */
    public function creationBlockReason(Measurement $measurement): ?string
    {
        if (! $measurement->isEffectiveRevision()) {
            return 'Só a revisão vigente de uma medição pode ser revisada.';
        }

        if ($measurement->revisionFamily()->get(['id', 'revision_status'])->contains(
            fn (Measurement $member): bool => $member->isPendingRevision(),
        )) {
            return 'Esta medição já tem uma revisão em andamento: conclua, recuse ou cancele aquela antes de abrir outra.';
        }

        return $this->eligibilityError($measurement);
    }

    public function canCreate(Measurement $measurement, User $actor): bool
    {
        return $this->authorization->canReviseMeasurement($actor, $measurement)
            && $this->creationBlockReason($measurement) === null;
    }

    /**
     * Cria a R(n+1) como rascunho, herdando o contexto congelado e os arquivos
     * da revisão vigente.
     *
     * Os arquivos herdados passam pela varredura antivírus antes da transação
     * -- fora dos locks; o arquivo pode ter chegado antes do antivírus -- e, sob
     * o lock, pelo SHA-256 do que a Engenharia aprovou: o conteúdo varrido é o
     * que a revisão herda.
     *
     * @throws AuthorizationException|MeasurementWorkflowException|ValidationException
     */
    public function create(Measurement $base, User $actor, string $reason, ?int $expectedWorkflowRevision = null): Measurement
    {
        $reason = $this->normalizedReason($reason, 'reason', 'Informe o motivo da revisão.');

        if (! $this->authorization->canReviseMeasurement($actor, $base)) {
            throw new AuthorizationException('Você não pode revisar medições desta operação.');
        }

        $this->scanFilesToInherit($base);
        $verifiedSourceWorkflowRevision = $this->verifyPaidSourceEvidence((int) $base->getKey());

        $revision = DB::transaction(function () use ($base, $actor, $reason, $expectedWorkflowRevision, $verifiedSourceWorkflowRevision): Measurement {
            ['operation' => $operation, 'members' => $members] = $this->lockFamilyWithOperation($base);

            $effective = $members->first(fn (Measurement $member): bool => $member->isEffectiveRevision());

            if (! $effective instanceof Measurement || (int) $effective->getKey() !== (int) $base->getKey()) {
                throw new MeasurementWorkflowException(self::STALE_REVISION_REFUSAL, [
                    'measurement_id' => $base->getKey(),
                    'effective_measurement_id' => $effective?->getKey(),
                ]);
            }

            if ($expectedWorkflowRevision !== null && (int) $effective->workflow_revision !== $expectedWorkflowRevision) {
                throw new MeasurementWorkflowException(self::STALE_REVISION_REFUSAL, ['measurement_id' => $effective->getKey()]);
            }

            $this->assertConsistentReadOf($effective);
            $this->assertPaidSourceEvidenceVerified($effective, $verifiedSourceWorkflowRevision);

            $pending = $members->first(fn (Measurement $member): bool => $member->isPendingRevision());

            if ($pending instanceof Measurement) {
                throw new MeasurementWorkflowException(sprintf(
                    'Esta medição já tem a revisão %s em andamento: conclua, recuse ou cancele aquela antes de abrir outra.',
                    $pending->revisionLabel(),
                ), ['measurement_id' => $effective->getKey(), 'pending_measurement_id' => $pending->getKey()]);
            }

            $effective->setRelation('operation', $operation);

            if (! $this->authorization->canReviseMeasurement($actor, $effective)) {
                throw new AuthorizationException('Você não pode revisar medições desta operação.');
            }

            $eligibility = $this->eligibilityError($effective);

            if ($eligibility !== null) {
                throw new MeasurementWorkflowException($eligibility, ['measurement_id' => $effective->getKey()]);
            }

            $familyId = $this->healRoot($members);
            $number = (int) $members->max(fn (Measurement $member): int => $member->revisionNumber()) + 1;

            $revision = new Measurement;
            $revision->forceFill([
                'operation_id' => $effective->operation_id,
                'reference_month' => $effective->reference_month?->toDateString(),
                'status' => 'pending',
                'current_stage' => 0,
                'uploaded_by' => $actor->getKey(),
                'uploaded_at' => null,
                'filename' => null,
                'storage_path' => null,
                'revision_family_id' => $familyId,
                'revision_root_id' => $familyId,
                'revision_number' => $number,
                'previous_revision_id' => $effective->getKey(),
                'previous_revision_number' => $effective->revisionNumber(),
                'revision_status' => MeasurementRevisionStatus::Draft,
                'revision_reason' => $reason,
                'revision_created_by' => $actor->getKey(),
                'previous_snapshot_sha256' => self::canonicalSnapshotHash($effective->engineering_snapshot),
            ])->save();

            $inherited = [];

            foreach ($effective->assets()->orderBy('id')->get() as $source) {
                $asset = new MeasurementAsset;
                $asset->forceFill([
                    'measurement_id' => $revision->getKey(),
                    'inherited_from_asset_id' => $source->getKey(),
                    'plan_set_id' => $source->plan_set_id,
                    'plan_line_id' => $source->plan_line_id,
                    'plan_version_id' => $source->plan_version_id,
                    'filename' => $source->filename,
                    'storage_path' => $source->storage_path,
                    'storage_disk' => $source->storage_disk,
                    'sha256' => $source->sha256,
                    'mime_type' => $source->mime_type,
                    'size' => $source->size,
                    'uploaded_at' => now(),
                ])->save();

                $inherited[] = [
                    'asset_id' => (int) $asset->getKey(),
                    'inherited_from_asset_id' => (int) $source->getKey(),
                    'plan_set_id' => (int) $source->plan_set_id,
                    'plan_line_id' => (int) $source->plan_line_id,
                    'plan_version_id' => (int) $source->plan_version_id,
                    'storage_disk' => $asset->storage_disk,
                    'sha256' => $asset->sha256,
                ];
            }

            $this->audit($revision, $actor, 'measurement_revision_created', [
                'revision_status_from' => null,
                'revision_status_to' => MeasurementRevisionStatus::Draft->value,
                'reason' => $reason,
                'inherited_assets' => $inherited,
                'plan_context' => $this->planContext($effective),
                'source_status' => $effective->status,
                'source_eligibility' => $this->eligibilityCase($effective),
                'source_payments' => $this->paymentSummary($effective),
            ], $this->reviseAuthorization($actor, $operation), $effective);

            return $revision;
        });

        return $revision->refresh();
    }

    /**
     * Envia o rascunho para a análise da Engenharia. Confere de novo, sob o
     * lock, que a revisão vigente continua revisável (o rascunho pode ter
     * ficado velho: a vigente recebeu pagamento ou andou) e que os arquivos
     * são exatamente os herdados.
     */
    public function submit(Measurement $draft, User $actor, ?int $expectedWorkflowRevision = null): void
    {
        $verifiedSourceWorkflowRevision = $draft->previous_revision_id === null
            ? null
            : $this->verifyPaidSourceEvidence((int) $draft->previous_revision_id);

        DB::transaction(function () use ($draft, $actor, $expectedWorkflowRevision, $verifiedSourceWorkflowRevision): void {
            ['operation' => $operation, 'members' => $members] = $this->lockFamilyWithOperation($draft);
            $locked = $this->member($members, $draft);

            if (! $locked->isDraftRevision()
                || ($expectedWorkflowRevision !== null && (int) $locked->workflow_revision !== $expectedWorkflowRevision)) {
                throw new MeasurementWorkflowException(self::STALE_REVISION_REFUSAL, ['measurement_id' => $locked->getKey()]);
            }

            $locked->setRelation('operation', $operation);

            if (! $this->authorization->canReviseMeasurement($actor, $locked)) {
                throw new AuthorizationException('Você não pode revisar medições desta operação.');
            }

            $previous = $members->firstWhere('id', $locked->previous_revision_id);

            if (! $previous instanceof Measurement || ! $previous->isEffectiveRevision()) {
                throw new MeasurementWorkflowException('A revisão anterior deixou de ser a vigente: este rascunho ficou desatualizado e precisa ser cancelado.', [
                    'measurement_id' => $locked->getKey(),
                ]);
            }

            $this->assertConsistentReadOf($previous);
            $this->assertPaidSourceEvidenceVerified($previous, $verifiedSourceWorkflowRevision);

            // O rascunho só envelhece de verdade quando a Engenharia da anterior
            // aprovou outro registro: aí ele herdou um contexto que deixou de
            // valer. Uma anterior só temporariamente fora da revisão -- comprovante
            // em conferência, etapa Pagamento reaberta -- volta a aceitá-lo.
            $this->assertPreviousSnapshotUnchanged($locked, $previous);

            $eligibility = $this->eligibilityError($previous);

            if ($eligibility !== null) {
                throw new MeasurementWorkflowException(sprintf(
                    'A revisão %1$s mudou desde a criação deste rascunho e não pode mais ser revisada assim: %2$s O rascunho continua válido: envie-o de novo quando a %1$s puder ser revisada.',
                    $previous->revisionLabel(),
                    $eligibility,
                ), [
                    'measurement_id' => $locked->getKey(),
                ]);
            }

            $this->inheritedAssetPairs($locked);

            // "Enviada por" é quem envia a revisão à análise; quem a criou fica em
            // `revision_created_by`.
            $locked->forceFill([
                'revision_status' => MeasurementRevisionStatus::UnderReview,
                'uploaded_at' => now(),
                'uploaded_by' => $actor->getKey(),
            ])->save();

            app(MeasurementWorkflow::class)->startReview($locked, $actor);

            $locked->refresh();

            // A anterior com o fluxo aberto (etapa Pagamento sem pagamento, ou a
            // paga na Finalização) fica suspensa a partir daqui; a finalizada não
            // tem fluxo para suspender.
            $suspends = $previous->isOpen();
            $authorization = $this->reviseAuthorization($actor, $operation);
            $sourceStage = $suspends ? app(MeasurementWorkflow::class)->unifiedStage($previous) : null;
            $sourceEligibility = $this->eligibilityCase($previous);
            $sourcePayments = $this->paymentSummary($previous);

            $this->audit($locked, $actor, 'measurement_revision_submitted', [
                'revision_status_from' => MeasurementRevisionStatus::Draft->value,
                'revision_status_to' => MeasurementRevisionStatus::UnderReview->value,
                'suspends_measurement_id' => $suspends ? (int) $previous->getKey() : null,
                'suspended_source_status' => $suspends ? $previous->status : null,
                'suspended_source_stage' => $sourceStage,
                'source_eligibility' => $sourceEligibility,
                'source_payments' => $sourcePayments,
            ], $authorization, $previous);

            // A trilha da própria anterior registra a suspensão, como registra a
            // retomada ({@see self::afterPendingRevisionRejected()}) e o
            // encerramento: nada muda nela, mas nenhuma etapa dela anda enquanto
            // a revisão está em análise.
            if ($suspends) {
                $this->audit($previous, $actor, 'measurement_revision_source_suspended', [
                    'stage' => $sourceStage,
                    'status' => $previous->status,
                    'suspended_by_revision_id' => (int) $locked->getKey(),
                    'suspended_by_revision_number' => $locked->revisionNumber(),
                    'suspended_at' => $locked->uploaded_at->toIso8601String(),
                    'source_eligibility' => $sourceEligibility,
                    'source_payments' => $sourcePayments,
                ], $authorization, $locked);
            }
        });
    }

    /**
     * Cancela o rascunho: a revisão fica como histórico, com o motivo, e o
     * número nunca volta a ser usado.
     */
    public function cancel(Measurement $draft, User $actor, string $reason, ?int $expectedWorkflowRevision = null): void
    {
        $reason = $this->normalizedReason($reason, 'reason', 'Informe o motivo do cancelamento.');

        DB::transaction(function () use ($draft, $actor, $reason, $expectedWorkflowRevision): void {
            ['operation' => $operation, 'members' => $members] = $this->lockFamilyWithOperation($draft);
            $locked = $this->member($members, $draft);

            if (! $locked->isDraftRevision()
                || ($expectedWorkflowRevision !== null && (int) $locked->workflow_revision !== $expectedWorkflowRevision)) {
                throw new MeasurementWorkflowException(self::STALE_REVISION_REFUSAL, ['measurement_id' => $locked->getKey()]);
            }

            $locked->setRelation('operation', $operation);

            if (! $this->authorization->canManageRevision($actor, $locked)) {
                throw new AuthorizationException('Você não pode cancelar revisões de medição desta operação.');
            }

            $locked->forceFill([
                'revision_status' => MeasurementRevisionStatus::Cancelled,
                'status' => 'cancelled',
                'revision_closed_at' => now(),
                'revision_closed_by' => $actor->getKey(),
                'revision_closed_reason' => $reason,
                'workflow_revision' => (int) $locked->workflow_revision + 1,
            ])->save();

            $this->audit($locked, $actor, 'measurement_revision_cancelled', [
                'revision_status_from' => MeasurementRevisionStatus::Draft->value,
                'revision_status_to' => MeasurementRevisionStatus::Cancelled->value,
                'reason' => $reason,
            ], $this->reviseAuthorization($actor, $operation), $members->firstWhere('id', $locked->previous_revision_id));
        });
    }

    /**
     * Trava a Operation -- primeira instrução da transação, com o id que a
     * medição já carrega (imutável) -- e depois todas as revisões da família
     * pela chave primária, em ordem de id: a R0 antes da R1, a R1 antes da R2.
     * Os ids da família vêm de uma leitura comum feita já sob o lock da
     * Operation: só se entra numa família (envio da R0, criação de revisão)
     * segurando esse lock.
     *
     * @return array{operation: Operation, members: EloquentCollection<int, Measurement>}
     */
    public function lockFamilyWithOperation(Measurement $member): array
    {
        $operationId = $member->getAttribute('operation_id');
        $familyId = $member->familyRootId();

        $operation = filled($operationId)
            ? Operation::query()->whereKey($operationId)->lockForUpdate()->first()
            : null;

        if (! $operation instanceof Operation) {
            throw new MeasurementWorkflowException('A operação da medição não foi encontrada.', ['measurement_id' => $member->getKey()]);
        }

        $ids = Measurement::query()
            ->where('operation_id', $operation->getKey())
            ->where(fn ($family) => $family->where('revision_family_id', $familyId)->orWhere('id', $familyId))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $members = Measurement::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if (! $members->contains(fn (Measurement $locked): bool => (int) $locked->getKey() === (int) $member->getKey())) {
            throw new MeasurementWorkflowException('A medição não foi encontrada.', ['measurement_id' => $member->getKey()]);
        }

        $members->each(fn (Measurement $locked) => $locked->setRelation('operation', $operation));

        return ['operation' => $operation, 'members' => $members];
    }

    /**
     * A revisão em análise vira a vigente: chamada pela aprovação da
     * Compliance, dentro da transação dela e com a Operation e a família já
     * travadas ({@see MeasurementWorkflow::approve()}). Opera sobre a mesma
     * instância que a aprovação vai continuar gravando. A anterior paga
     * aguardando a Finalização só é substituída com os arquivos conferidos
     * antes da transação, na versão do fluxo que a aprovação informa
     * ({@see self::verifyPaidSourceEvidenceBeforeEffectiveness()}).
     *
     * @param  EloquentCollection<int, Measurement>  $members
     *
     * @throws MeasurementWorkflowException|ValidationException
     */
    public function makeEffective(Measurement $revision, EloquentCollection $members, User $actor, ResponsibilityAuthorization $authorization, ?int $verifiedSourceWorkflowRevision = null): void
    {
        if (! $revision->isRevision() || $revision->revisionStatus() !== MeasurementRevisionStatus::UnderReview) {
            throw new MeasurementWorkflowException(self::STALE_REVISION_REFUSAL, ['measurement_id' => $revision->getKey()]);
        }

        $previous = $members->firstWhere('id', $revision->previous_revision_id);

        if (! $previous instanceof Measurement
            || ! $previous->isEffectiveRevision()
            || (int) $members->first(fn (Measurement $member): bool => $member->isEffectiveRevision())?->getKey() !== (int) $previous->getKey()) {
            throw new MeasurementWorkflowException('A revisão anterior deixou de ser a vigente: esta revisão não pode mais substituí-la.', [
                'measurement_id' => $revision->getKey(),
            ]);
        }

        // As análises da anterior que ainda esperam decisão são encerradas aqui,
        // se ela tem o fluxo aberto. Travadas pela chave primária, antes dos
        // planos (Operation → medições → análises → planos), lidas por uma
        // leitura comum que basta: só quem segura a linha da anterior -- e a
        // família está travada -- escreve nelas.
        $pendingReviews = $this->lockPendingReviews($previous);

        $this->assertConsistentReadOf($previous);
        $this->assertPaidSourceEvidenceVerified($previous, $verifiedSourceWorkflowRevision);

        $eligibility = $this->eligibilityError($previous);

        if ($eligibility !== null) {
            throw new MeasurementWorkflowException('A revisão '.$previous->revisionLabel().' mudou e não pode mais ser substituída: '.$eligibility, [
                'measurement_id' => $revision->getKey(),
            ]);
        }

        $this->assertPreviousSnapshotUnchanged($revision, $previous);

        $snapshot = $revision->engineering_snapshot;

        if (! $revision->hasApprovedEngineering()
            || ! is_array($snapshot)
            || (int) ($snapshot['schema_version'] ?? 0) !== MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION
            || (int) ($snapshot['measurement_id'] ?? 0) !== (int) $revision->getKey()
            || ! is_array($snapshot['plan_sets'] ?? null)
            || $snapshot['plan_sets'] === []) {
            throw new MeasurementWorkflowException('A revisão precisa da Engenharia aprovada, com o registro do avanço físico, para passar a valer.', [
                'measurement_id' => $revision->getKey(),
            ]);
        }

        $entries = collect($snapshot['plan_sets'])->keyBy(fn (array $entry): int => (int) $entry['plan_set_id']);
        $planSets = MeasurementPlanSet::query()
            ->where('operation_id', $revision->operation_id)
            ->whereKey($entries->keys()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        MeasurementAsset::query()
            ->whereIn('measurement_id', [(int) $previous->getKey(), (int) $revision->getKey()])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $pairs = $this->inheritedAssetPairs($revision);

        if ($pairs->count() !== $entries->count()
            || $pairs->contains(fn (array $pair): bool => ! $entries->has((int) $pair['asset']->plan_set_id)
                || (int) ($entries->get((int) $pair['asset']->plan_set_id)['asset_id'] ?? 0) !== (int) $pair['asset']->getKey())) {
            throw new MeasurementWorkflowException('O registro da Engenharia da revisão não corresponde aos arquivos dela: devolva a revisão à Engenharia.', [
                'measurement_id' => $revision->getKey(),
            ]);
        }

        $lines = MeasurementPlanLine::query()
            ->whereKey($pairs->map(fn (array $pair): int => (int) $pair['asset']->plan_line_id)->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($pairs as $pair) {
            $line = $lines->get((int) $pair['asset']->plan_line_id);
            $holder = $line instanceof MeasurementPlanLine
                ? MeasurementAsset::query()->where('line_claim_key', $line->lineage_key)->value('id')
                : null;

            if (! $line instanceof MeasurementPlanLine
                || $pair['source']->line_claim_key !== $line->lineage_key
                || (int) $holder !== (int) $pair['source']->getKey()) {
                throw new MeasurementWorkflowException(sprintf(
                    'A medição prevista de %s não está mais ocupada pela revisão %s: a revisão não pode assumi-la. Atualize a página.',
                    $this->planSetLabel($planSets->firstWhere('id', (int) $pair['asset']->plan_set_id)),
                    $previous->revisionLabel(),
                ), ['measurement_id' => $revision->getKey(), 'plan_line_id' => $pair['asset']->plan_line_id]);
            }
        }

        $this->assertFitsThePhysicalCeiling($revision, $previous, $planSets, $entries);

        $now = now();
        // O fluxo ainda aberto da anterior -- etapa Pagamento sem pagamento (caso
        // B) ou Finalização com pagamento e documentação completa (caso C) --
        // termina aqui. A finalizada (caso A) não tem fluxo a encerrar.
        $closesPreviousWorkflow = $previous->isOpen();
        $closedStage = $closesPreviousWorkflow ? app(MeasurementWorkflow::class)->unifiedStage($previous) : null;
        $closedFromStatus = $previous->status;
        $closedReviews = [];

        // O intervalo em que a anterior ficou suspensa pela revisão em análise
        // não é tempo ativo da etapa dela: vira uma pausa encerrada, como na
        // recusa da revisão. Intervalo vazio (envio e vigência no mesmo
        // segundo) não é pausa nenhuma.
        if ($closesPreviousWorkflow
            && $revision->uploaded_at !== null
            && $revision->uploaded_at->getTimestamp() < $now->getTimestamp()) {
            $this->recordSuspensionPause($previous, $revision, $actor, (int) $closedStage, 'a revisão passou a valer', $now);
        }

        $previous->forceFill(array_merge([
            'revision_status' => MeasurementRevisionStatus::Superseded,
            'revision_superseded_at' => $now,
        ], $closesPreviousWorkflow ? [
            'status' => 'superseded',
            'analyzed_by' => $actor->getKey(),
            'analyzed_at' => $now,
            'workflow_revision' => (int) $previous->workflow_revision + 1,
        ] : []))->save();

        if ($closesPreviousWorkflow) {
            // A análise que esperava decisão fica encerrada pela substituição --
            // sem decisão da etapa: nem aprovada, nem recusada, sem revisor --,
            // e nunca mais volta ao fluxo (MeasurementReview::booted).
            foreach ($pendingReviews as $review) {
                $review->forceFill([
                    'status' => MeasurementReview::STATUS_SUPERSEDED,
                    'reviewer_user_id' => null,
                    'reviewed_at' => $now,
                    'notes' => sprintf(
                        'Encerrada pela revisão %s, que passou a valer na aprovação da Compliance em %s: a etapa não foi decidida nesta medição.',
                        $revision->revisionLabel(),
                        BusinessTime::at($now)->format('d/m/Y'),
                    ),
                    'paused_at' => null,
                    'paused_by' => null,
                    'pause_reason' => null,
                    'paused_operation_status' => null,
                ])->save();

                $closedReviews[] = ['review_id' => (int) $review->getKey(), 'stage' => (int) $review->stage];
            }
        }

        $revision->forceFill([
            'revision_status' => MeasurementRevisionStatus::Effective,
            'revision_effective_at' => $now,
        ])->save();

        // Uma linhagem por vez, em ordem de chave: solta a da revisão anterior e
        // ocupa a desta. Pela chave primária, sem gap lock; a unique do banco é
        // conferida a cada instrução, então a ordem "solta, depois ocupa" é a
        // que passa.
        $transfers = [];

        foreach ($pairs->sortBy(fn (array $pair): string => (string) $lines->get((int) $pair['asset']->plan_line_id)->lineage_key) as $pair) {
            $lineage = (string) $lines->get((int) $pair['asset']->plan_line_id)->lineage_key;

            MeasurementAsset::query()->whereKey($pair['source']->getKey())->update(['line_claim_key' => null]);
            MeasurementAsset::query()->whereKey($pair['asset']->getKey())->update(['line_claim_key' => $lineage]);

            $transfers[] = [
                'lineage_key' => $lineage,
                'plan_line_id' => (int) $pair['asset']->plan_line_id,
                'plan_version_id' => (int) $pair['asset']->plan_version_id,
                'from_asset_id' => (int) $pair['source']->getKey(),
                'to_asset_id' => (int) $pair['asset']->getKey(),
            ];
        }

        // A linha do cronograma passa a mostrar a revisão vigente: o realizado
        // e a medição que ela registrou. A Finalização da revisão confere a
        // linha contra o snapshot dela.
        foreach ($pairs as $pair) {
            $entry = $entries->get((int) $pair['asset']->plan_set_id);
            $lines->get((int) $pair['asset']->plan_line_id)->forceFill([
                'realized_monthly_percent' => $entry['realized_monthly_percent'],
                'realized_cumulative_percent' => $entry['realized_cumulative_percent'],
                'measurement_id' => $revision->getKey(),
            ])->save();
        }

        $differences = $this->recordDifferences($revision, $members);

        $positionsBefore = $this->physicalSummary($previous);
        $positionsAfter = $this->physicalSummary($revision);
        $common = [
            'previous_revision_id' => (int) $previous->getKey(),
            'previous_revision_number' => $previous->revisionNumber(),
        ];

        $this->audit($revision, $actor, 'measurement_revision_approved', $common + [
            'stage' => 3,
            'revision_status_from' => MeasurementRevisionStatus::UnderReview->value,
            'revision_status_to' => MeasurementRevisionStatus::Effective->value,
        ], $authorization, $previous);

        $this->audit($revision, $actor, 'measurement_revision_became_effective', $common + [
            'superseded_measurement_id' => (int) $previous->getKey(),
            'physical_before' => $positionsBefore,
            'physical_after' => $positionsAfter,
            'line_claims_transferred' => $transfers,
            'plan_context' => $this->planContext($revision),
            'closes_previous_workflow' => $closesPreviousWorkflow,
        ], $authorization, $previous);

        $this->audit($previous, $actor, 'measurement_revision_superseded', [
            'revision_status_from' => MeasurementRevisionStatus::Effective->value,
            'revision_status_to' => MeasurementRevisionStatus::Superseded->value,
            'superseding_measurement_id' => (int) $revision->getKey(),
            'superseding_revision_number' => $revision->revisionNumber(),
            'workflow_closed' => $closesPreviousWorkflow,
        ], $authorization, $revision);

        if ($closesPreviousWorkflow) {
            // O fluxo aberto da anterior (etapa Pagamento sem pagamento, ou
            // Finalização com pagamento e documentação completa) termina aqui:
            // evento de encerramento que o relatório de ciclo entende. A
            // autoridade é a da aprovação da Compliance que fez a revisão valer
            // -- a mesma resolução, a mesma responsabilidade --, não a do Gestor
            // de Pagamento nem a do Finalizador, que não decidiram nada aqui.
            $this->audit($previous, $actor, 'measurement_workflow_closed_by_revision', [
                'stage' => $closedStage,
                'target_stage' => null,
                'from_status' => $closedFromStatus,
                'to_status' => 'superseded',
                'responsibility' => MeasurementResponsibility::primaryForStage(3)?->operationColumn(),
                'expected_responsible_user_id' => $previous->operation?->stageResponsibleId(3),
                'superseding_measurement_id' => (int) $revision->getKey(),
                'superseding_revision_number' => $revision->revisionNumber(),
                'closed_reviews' => $closedReviews,
                'historical_payments' => $this->paymentSummary($previous),
            ], $authorization, $revision);
        }

        $this->audit($revision, $actor, 'measurement_revision_financial_difference_identified', $common + [
            'differences' => $differences->map(fn (MeasurementRevisionDifference $difference): array => $this->differenceSummary($difference))->values()->all(),
            'positions' => array_map(fn (MeasurementRevisionPlanSetPosition $position): array => $position->toArray(), $this->positions($revision)),
        ], $authorization, $previous);

        $this->notifyAfterCommit($revision, 'revision_effective', [(int) $revision->revision_created_by]);

        if ($closesPreviousWorkflow) {
            // Quem cuidava do fluxo encerrado: o Gestor de Pagamento na etapa
            // Pagamento; o Finalizador e quem envia os comprovantes na
            // Finalização da medição paga.
            $closedWithPayments = $closedStage === MeasurementWorkflow::STAGE_FINALIZATION;

            $this->notifyAfterCommit(
                $previous,
                $closedWithPayments ? 'paid_workflow_closed_by_revision' : 'workflow_closed_by_revision',
                $closedWithPayments
                    ? [$previous->operation?->payment_finalizer_user_id, $previous->operation?->payment_receipt_uploader_user_id]
                    : [$previous->operation?->payment_manager_user_id],
            );
        }
    }

    /**
     * A revisão em análise foi recusada na Engenharia: fica como histórico,
     * com o motivo, e a vigente segue intacta -- a ocupação da linha, o avanço
     * e os pagamentos dela nunca mudaram. Se o fluxo da vigente estava
     * suspenso (etapa Pagamento sem pagamento, ou Finalização da medição paga),
     * o intervalo da suspensão vira uma pausa encerrada dela, na etapa em que
     * ela parou, para o prazo da etapa não contar o tempo em que ninguém podia
     * agir; a vigente volta exatamente à situação em que estava.
     *
     * Devolve a vigente cujo fluxo voltou a correr, ou `null` quando nada
     * estava suspenso (a finalizada não tem fluxo).
     *
     * @param  EloquentCollection<int, Measurement>  $members
     */
    public function afterPendingRevisionRejected(Measurement $revision, EloquentCollection $members, User $actor, string $notes, ResponsibilityAuthorization $authorization): ?Measurement
    {
        $previous = $members->firstWhere('id', $revision->previous_revision_id);
        $resumed = $previous instanceof Measurement
            && $previous->isEffectiveRevision()
            && $previous->isOpen()
            && $revision->uploaded_at !== null;

        if ($resumed) {
            $stage = app(MeasurementWorkflow::class)->unifiedStage($previous);
            $pause = $this->recordSuspensionPause($previous, $revision, $actor, $stage, 'a revisão foi recusada');

            $this->audit($previous, $actor, 'measurement_revision_source_resumed', [
                'stage' => $stage,
                'status' => $previous->status,
                'rejected_revision_id' => (int) $revision->getKey(),
                'rejected_revision_number' => $revision->revisionNumber(),
                'suspended_from' => $revision->uploaded_at->toIso8601String(),
                'resumed_at' => now()->toIso8601String(),
                'suspension_pause_id' => (int) $pause->getKey(),
                'reason' => $notes,
            ], $authorization, $revision);
        }

        $this->audit($revision, $actor, 'measurement_revision_rejected', [
            'revision_status_from' => MeasurementRevisionStatus::UnderReview->value,
            'revision_status_to' => MeasurementRevisionStatus::Rejected->value,
            'reason' => $notes,
            'previous_revision_id' => $previous?->getKey(),
            'previous_remains_effective' => $previous?->isEffectiveRevision() ?? false,
            'previous_resumed' => $resumed,
        ], $authorization, $previous);

        return $resumed ? $previous : null;
    }

    /**
     * O intervalo em que a revisão em análise suspendeu o fluxo aberto da
     * anterior -- do envio da revisão até agora -- como pausa encerrada da
     * anterior, na etapa em que ela parou: o prazo da etapa (SLA) e o tempo
     * ativo do relatório de ciclo não contam o tempo em que ninguém podia agir.
     * Datas no calendário de negócio.
     *
     * Quem suspendeu foi quem enviou a revisão; quem encerrou a suspensão foi
     * quem decidiu a revisão. Na vigência, o fim da pausa é o mesmo instante
     * em que a anterior foi substituída ({@see MeasurementTimeline}): a
     * suspensão terminou com o encerramento, não com uma retomada.
     */
    private function recordSuspensionPause(Measurement $previous, Measurement $revision, User $actor, int $stage, string $outcome, ?CarbonInterface $endedAt = null): MeasurementPause
    {
        $now = $endedAt ?? now();

        return MeasurementPause::query()->create([
            'measurement_id' => $previous->getKey(),
            'stage' => $stage,
            'paused_by' => $revision->uploaded_by ?? $actor->getKey(),
            'pause_reason' => sprintf(
                'Fluxo suspenso pela revisão %s, em análise de %s a %s; %s.',
                $revision->revisionLabel(),
                BusinessTime::at($revision->uploaded_at)->format('d/m/Y'),
                BusinessTime::at($now)->format('d/m/Y'),
                $outcome,
            ),
            'paused_operation_status' => $previous->status,
            'paused_at' => $revision->uploaded_at,
            'resumed_at' => $now,
            'resumed_by' => $actor->getKey(),
        ]);
    }

    /**
     * Grava o conjunto de diferenças corrente da revisão, a partir do snapshot
     * persistido dela e da revisão que ela substitui, e marca o anterior como
     * substituído. Pela chave primária: nenhuma escrita por intervalo.
     *
     * @param  EloquentCollection<int, Measurement>|null  $members
     * @return Collection<int, MeasurementRevisionDifference>
     */
    public function recordDifferences(Measurement $revision, ?EloquentCollection $members = null): Collection
    {
        $this->supersedeCurrentDifferences($revision);

        $persistedSnapshot = Measurement::query()->whereKey($revision->getKey())->value('engineering_snapshot');
        $snapshot = is_string($persistedSnapshot) ? json_decode($persistedSnapshot, true) : $persistedSnapshot;

        if (! is_array($snapshot)) {
            return collect();
        }

        $hash = self::canonicalSnapshotHash($snapshot);
        $computation = (int) MeasurementRevisionDifference::query()->where('measurement_id', $revision->getKey())->max('computation') + 1;
        $previous = $members?->firstWhere('id', $revision->previous_revision_id) ?? Measurement::query()->find($revision->previous_revision_id);
        $positions = collect($this->positions($revision, $snapshot, $previous));

        // `unresolved_overpayment_amount` guarda, desde a Fase 3, o valor pago a
        // maior que esta revisão identificou e que nenhuma finalização anterior
        // reconheceu; a exposição total da família no momento é
        // `historical_paid_amount − revised_approved_amount`.
        return $positions->map(fn (MeasurementRevisionPlanSetPosition $position): MeasurementRevisionDifference => MeasurementRevisionDifference::query()->create([
            'measurement_id' => $revision->getKey(),
            'previous_measurement_id' => $previous?->getKey(),
            'revision_family_id' => $revision->familyRootId(),
            'operation_id' => $revision->operation_id,
            'plan_set_id' => $position->planSetId,
            'plan_version_id' => $position->planVersionId,
            'settled_measurement_id' => $position->settledMeasurementId,
            'computation' => $computation,
            'engineering_snapshot_sha256' => $hash,
            'previous_realized_monthly_percent' => $position->previousRealizedMonthlyPercent,
            'revised_realized_monthly_percent' => $position->revisedRealizedMonthlyPercent,
            'physical_difference_percent' => MeasurementPhysicalProgress::decimal($position->physicalDifferenceBasisPoints),
            'previous_fund_amount' => $position->previousFundAmount,
            'revised_fund_amount' => $position->revisedFundAmount,
            'previous_approved_amount' => $position->previousApprovedAmount,
            'revised_approved_amount' => $position->revisedApprovedAmount,
            'financial_difference_amount' => $position->financialDifferenceAmount,
            'difference_type' => $position->differenceType,
            'historical_paid_amount' => $position->historicalPaidAmount,
            'settled_approved_amount' => $position->settledApprovedAmount,
            'unresolved_overpayment_amount' => $position->unacknowledgedOverpaymentAmount ?? '0.00',
        ]))->values();
    }

    /**
     * A revisão vigente voltou à Engenharia: o registro dela deixou de valer, e
     * a diferença calculada sobre ele também. A reaprovação grava outra.
     */
    public function supersedeCurrentDifferences(Measurement $revision): void
    {
        MeasurementRevisionDifference::query()
            ->where('measurement_id', $revision->getKey())
            ->whereNull('superseded_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => MeasurementRevisionDifference::query()->whereKey($id)->update([
                'superseded_at' => now(),
                'updated_at' => now(),
            ]));
    }

    /**
     * A posição de cada empreendimento da revisão, calculada agora a partir do
     * snapshot da revisão, do da revisão que ela substitui e dos pagamentos da
     * família -- a mesma conta que as travas da etapa Pagamento e da
     * Finalização usam. Vazia para a medição que não é revisão ou sem
     * Engenharia registrada.
     *
     * O reconhecido anteriormente sai das finalizações anteriores da família:
     * cada uma reconheceu o que, ao finalizar, já estava pago acima do aprovado
     * dela; contra o aprovado de agora, ela cobre no máximo o que os pagamentos
     * que ela viu passam deste aprovado. Pagamento posterior à última
     * finalização -- o complementar de uma revisão substituída antes de
     * finalizar -- nunca entra como reconhecido.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return list<MeasurementRevisionPlanSetPosition>
     */
    public function positions(Measurement $revision, ?array $snapshot = null, ?Measurement $previous = null): array
    {
        if (! $revision->isRevision()) {
            return [];
        }

        $snapshot ??= $revision->engineering_snapshot;
        $previous ??= $revision->relationLoaded('previousRevision') ? $revision->previousRevision : $revision->previousRevision()->first();
        $previousSnapshot = $previous?->engineering_snapshot;

        if (! is_array($snapshot) || ! is_array($snapshot['plan_sets'] ?? null) || ! is_array($previousSnapshot)) {
            return [];
        }

        $previousEntries = $this->snapshotEntries($previousSnapshot);
        $ledger = $this->familyLedger($revision);
        $own = $this->ownPaidByPlanSet($revision);
        $settled = $ledger['finalized'] === [] ? null : $ledger['finalized'][array_key_last($ledger['finalized'])];
        $settledEntries = $settled === null ? collect() : $this->snapshotEntries($settled['snapshot']);
        $finalized = $revision->status === 'finalized';
        $superseded = $revision->revisionStatus() === MeasurementRevisionStatus::Superseded;
        $positions = [];

        foreach ($this->snapshotEntries($snapshot)->sortKeys() as $planSetId => $entry) {
            $previousEntry = $previousEntries->get($planSetId);
            $previousPercent = MeasurementPhysicalProgress::decimal(MeasurementPhysicalProgress::basisPoints($previousEntry['realized_monthly_percent'] ?? '0') ?? 0);
            $revisedPercent = MeasurementPhysicalProgress::decimal(MeasurementPhysicalProgress::basisPoints($entry['realized_monthly_percent'] ?? '0') ?? 0);
            $previousApproved = is_array($previousEntry) ? $this->reconciliation->expectedAmountForSnapshotEntry($previousEntry) : null;
            $revisedApproved = $this->reconciliation->expectedAmountForSnapshotEntry($entry);
            $difference = $previousApproved !== null && $revisedApproved !== null ? bcsub($revisedApproved, $previousApproved, 2) : null;
            $historicalPaid = $ledger['historical'][$planSetId] ?? '0.00';
            $ownPaid = $own[$planSetId] ?? '0.00';
            $familyPaid = bcadd($historicalPaid, $ownPaid, 2);
            $openBalance = $revisedApproved === null ? null : bcsub($revisedApproved, $familyPaid, 2);
            $settledEntry = $settledEntries->get($planSetId);
            $settledApproved = is_array($settledEntry) ? $this->reconciliation->expectedAmountForSnapshotEntry($settledEntry) : null;
            $outstanding = $openBalance === null ? null : self::nonNegative(bcsub('0', $openBalance, 2));
            $remaining = $openBalance === null ? null : self::nonNegative($openBalance);
            $historicalOverpayment = $revisedApproved === null ? null : self::nonNegative(bcsub($historicalPaid, $revisedApproved, 2));
            $acknowledged = $this->previouslyAcknowledgedOverpayment($ledger['finalized'], $planSetId, $revisedApproved);
            $previouslyAcknowledged = $outstanding === null ? null : self::minimum($acknowledged['amount'], $outstanding);
            $unacknowledged = $historicalOverpayment === null ? null : self::nonNegative(bcsub($historicalOverpayment, $acknowledged['amount'], 2));
            // O que passa do aprovado por pagamento da própria revisão -- só
            // possível sem pagamento anterior, dentro de uma regra financeira --
            // é divergência do próprio pagamento: justificada nele e aceita na
            // Finalização desta revisão.
            $ownExcess = $outstanding === null ? '0.00' : self::nonNegative(bcsub($outstanding, (string) $historicalOverpayment, 2));

            $positions[] = new MeasurementRevisionPlanSetPosition(
                planSetId: $planSetId,
                label: (string) (($entry['construction_name'] ?? null) ?: ($entry['plan_set_name'] ?? '—')),
                planVersionId: isset($entry['plan_version_id']) ? (int) $entry['plan_version_id'] : null,
                planVersionNumber: isset($entry['plan_version_number']) ? (int) $entry['plan_version_number'] : null,
                previousRealizedMonthlyPercent: $previousPercent,
                revisedRealizedMonthlyPercent: $revisedPercent,
                physicalDifferenceBasisPoints: (int) (MeasurementPhysicalProgress::basisPoints($revisedPercent) ?? 0) - (int) (MeasurementPhysicalProgress::basisPoints($previousPercent) ?? 0),
                previousFundAmount: $this->decimalOrNull($previousEntry['construction_fund_amount'] ?? null),
                revisedFundAmount: $this->decimalOrNull($entry['construction_fund_amount'] ?? null),
                previousApprovedAmount: $previousApproved,
                revisedApprovedAmount: $revisedApproved,
                financialDifferenceAmount: $difference,
                differenceType: MeasurementRevisionDifferenceType::fromDifference($difference),
                historicalPaidAmount: $historicalPaid,
                ownPaidAmount: $ownPaid,
                openBalanceAmount: $openBalance,
                settledMeasurementId: $settled === null ? null : $settled['id'],
                settledApprovedAmount: $settledApproved,
                unacknowledgedOverpaymentAmount: $unacknowledged,
                adjustmentStatus: $this->adjustmentStatus($openBalance, $unacknowledged, $ownExcess, $ownPaid, $historicalPaid, $finalized, $superseded),
                familyPaidAmount: $familyPaid,
                outstandingOverpaymentAmount: $outstanding,
                remainingPayableAmount: $remaining,
                previouslyAcknowledgedOverpaymentAmount: $previouslyAcknowledged,
                previouslyAcknowledgedByMeasurementIds: $previouslyAcknowledged !== null && bccomp($previouslyAcknowledged, '0', 2) > 0
                    ? $acknowledged['by']
                    : [],
                // O que ela era enquanto vigente: os pagamentos das anteriores, os
                // dela, o aprovado dela e as finalizações anteriores não mudam
                // depois que a revisão seguinte passa a valer.
                statusWhenEffective: $superseded
                    ? $this->adjustmentStatus($openBalance, $unacknowledged, $ownExcess, $ownPaid, $historicalPaid, $finalized, false)
                    : null,
            );
        }

        return $positions;
    }

    /**
     * Empreendimentos em que a família pagou, antes desta revisão, mais do que
     * ela aprova, numa parte que nenhuma finalização anterior reconheceu: é o
     * que pede a decisão financeira na etapa Pagamento e o aceite expresso na
     * Finalização desta revisão.
     *
     * @return list<MeasurementRevisionPlanSetPosition>
     */
    public function unacknowledgedOverpayments(Measurement $revision): array
    {
        return array_values(array_filter(
            $this->positions($revision),
            fn (MeasurementRevisionPlanSetPosition $position): bool => $position->hasUnacknowledgedOverpayment(),
        ));
    }

    /**
     * Os empreendimentos como a pessoa lê, com as contas fechando: o valor
     * pago a maior em aberto é o que já foi reconhecido antes mais o que esta
     * revisão precisa reconhecer.
     *
     * @param  list<MeasurementRevisionPlanSetPosition>  $positions
     */
    public function describeOverpayments(array $positions): string
    {
        return collect($positions)
            ->map(fn (MeasurementRevisionPlanSetPosition $position): string => sprintf(
                '%s (aprovado vigente %s; pago pela família %s; pago a maior em aberto %s, dos quais %s reconhecidos anteriormente e %s a reconhecer nesta revisão)',
                $position->label,
                MeasurementFinancialReconciliationService::formatCurrency($position->revisedApprovedAmount),
                MeasurementFinancialReconciliationService::formatCurrency($position->familyPaidAmount),
                MeasurementFinancialReconciliationService::formatCurrency($position->outstandingOverpaymentAmount),
                MeasurementFinancialReconciliationService::formatCurrency($position->previouslyAcknowledgedOverpaymentAmount ?? '0.00'),
                MeasurementFinancialReconciliationService::formatCurrency($position->unacknowledgedOverpaymentAmount),
            ))
            ->implode('; ');
    }

    /**
     * A família já tem pagamento registrado nas revisões anteriores a esta?
     */
    public function familyHasHistoricalPayments(Measurement $revision): bool
    {
        return $this->reconciliation->historicalPaidByPlanSet($revision) !== [];
    }

    /**
     * A revisão passa pela etapa Pagamento sem pagamento próprio? Quando a
     * família já pagou antes dela -- o que falta ou sobra é a posição
     * financeira, conferida à parte (saldo justificado, valor pago a maior
     * decidido) -- ou quando não há nada a pagar: todo empreendimento com
     * referência financeira, saldo zerado e nenhum valor pago a maior a
     * reconhecer (a correção para 0% de uma medição ainda não paga, por
     * exemplo). A medição sem revisão continua exigindo pagamento.
     */
    public function allowsApprovalWithoutOwnPayment(Measurement $revision): bool
    {
        if (! $revision->isRevision()) {
            return false;
        }

        if ($this->familyHasHistoricalPayments($revision)) {
            return true;
        }

        $positions = $this->positions($revision);

        return $positions !== [] && collect($positions)->every(
            fn (MeasurementRevisionPlanSetPosition $position): bool => $position->hasFinancialReference()
                && bccomp((string) $position->openBalanceAmount, '0', 2) <= 0
                && ! $position->hasUnacknowledgedOverpayment(),
        );
    }

    /**
     * Confere uma linha de pagamento da revisão antes de registrá-la: nada a
     * pagar onde o saldo não é positivo, nenhum pagamento enquanto a família
     * tiver valor pago a maior em aberto (reconhecido ou não), nenhum
     * pagamento sem referência financeira quando a família já pagou -- não há
     * como conciliar --, e o pagamento complementar (a família já pagou o
     * empreendimento antes) nunca passa do saldo a pagar, nem com regra
     * financeira. Sem pagamento anterior, o pagamento é o da competência:
     * acima do saldo, só dentro de uma regra financeira que cubra a
     * divergência, como na medição original.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws ValidationException
     */
    public function assertRevisionPaymentFits(Measurement $revision, array $row, int $index): void
    {
        if (! $revision->isRevision()) {
            return;
        }

        $planSetId = (int) ($row['plan_set_id'] ?? 0);
        $position = collect($this->positions($revision))->firstWhere('planSetId', $planSetId);

        if (! $position instanceof MeasurementRevisionPlanSetPosition) {
            return;
        }

        $field = "payments.{$index}.amount";

        if (! $position->hasFinancialReference()) {
            if ($position->hasHistoricalPayment()) {
                throw ValidationException::withMessages([$field => sprintf(
                    '%s não tem referência financeira (sem Fundo de Obra na versão do plano) e já recebeu %s nas revisões anteriores: um novo pagamento desta revisão não pode ser conciliado com o que já foi pago.',
                    $position->label,
                    MeasurementFinancialReconciliationService::formatCurrency($position->historicalPaidAmount),
                )]);
            }

            return;
        }

        if ($position->hasOutstandingOverpayment()) {
            throw ValidationException::withMessages([$field => sprintf(
                'A família já pagou %s a maior em %s, acima do aprovado vigente (%s): nenhum novo pagamento é registrado enquanto houver valor pago a maior em aberto. O sistema não registra devolução, estorno nem compensação.',
                MeasurementFinancialReconciliationService::formatCurrency($position->outstandingOverpaymentAmount),
                $position->label,
                MeasurementFinancialReconciliationService::formatCurrency($position->revisedApprovedAmount),
            )]);
        }

        if (bccomp((string) $position->openBalanceAmount, '0', 2) <= 0) {
            throw ValidationException::withMessages([$field => sprintf(
                'Não há valor a pagar nesta revisão para %s: o valor aprovado revisado (%s) já está coberto pelos pagamentos registrados (%s antes da revisão, %s nela).',
                $position->label,
                MeasurementFinancialReconciliationService::formatCurrency($position->revisedApprovedAmount),
                MeasurementFinancialReconciliationService::formatCurrency($position->historicalPaidAmount),
                MeasurementFinancialReconciliationService::formatCurrency($position->ownPaidAmount),
            )]);
        }

        $amount = $this->reconciliation->normalizeAmount($row['amount'] ?? null);

        if (bccomp($amount, (string) $position->openBalanceAmount, 2) <= 0) {
            return;
        }

        if ($position->hasHistoricalPayment()) {
            throw ValidationException::withMessages([$field => sprintf(
                'O valor informado para %s passa do saldo em aberto da revisão (%s): o que já foi pago nas revisões anteriores não se paga de novo, e o pagamento complementar não passa do saldo a pagar, nem com regra financeira.',
                $position->label,
                MeasurementFinancialReconciliationService::formatCurrency($position->openBalanceAmount),
            )]);
        }

        if (blank($row['financial_rule_id'] ?? null)) {
            throw ValidationException::withMessages([$field => sprintf(
                'O valor informado para %s passa do saldo em aberto da revisão (%s). Acima do saldo, só com uma regra financeira que cubra a divergência.',
                $position->label,
                MeasurementFinancialReconciliationService::formatCurrency($position->openBalanceAmount),
            )]);
        }
    }

    /**
     * O id da revisão vigente da família.
     */
    public function effectiveMemberId(Measurement $member): ?int
    {
        $id = Measurement::query()
            ->where('revision_family_id', $member->familyRootId())
            ->where('revision_status', MeasurementRevisionStatus::Effective->value)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * A contribuição física que a conta deixa de fora ao validar esta medição:
     * a própria (reaprovação) ou, na revisão pendente, a da vigente que ela vai
     * substituir -- atual − vigente + revisão, nunca atual + revisão.
     */
    public function progressExclusionId(Measurement $measurement): int
    {
        if ($measurement->isRevision() && $measurement->isPendingRevision()) {
            return $this->effectiveMemberId($measurement) ?? (int) $measurement->getKey();
        }

        return (int) $measurement->getKey();
    }

    /**
     * Os arquivos da revisão casados um a um com os da revisão que ela
     * substitui, pela herança: mesmo número, cada um herdado uma vez, mesmo
     * plano, versão e medição prevista.
     *
     * @return Collection<int, array{asset: MeasurementAsset, source: MeasurementAsset}>
     *
     * @throws MeasurementWorkflowException
     */
    public function inheritedAssetPairs(Measurement $revision): Collection
    {
        $assets = $revision->assets()->orderBy('id')->get();
        $sources = MeasurementAsset::query()
            ->where('measurement_id', $revision->previous_revision_id)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $matches = $assets->count() === $sources->count()
            && $assets->pluck('inherited_from_asset_id')->filter()->unique()->count() === $sources->count()
            && $assets->every(function (MeasurementAsset $asset) use ($sources): bool {
                $source = $sources->get((int) $asset->inherited_from_asset_id);

                return $source instanceof MeasurementAsset
                    && (int) $source->plan_set_id === (int) $asset->plan_set_id
                    && (int) $source->plan_line_id === (int) $asset->plan_line_id
                    && (int) $source->plan_version_id === (int) $asset->plan_version_id;
            });

        if (! $matches) {
            throw new MeasurementWorkflowException(MeasurementAsset::REVISION_ASSET_SET_REFUSAL, [
                'measurement_id' => $revision->getKey(),
            ]);
        }

        return $assets->map(fn (MeasurementAsset $asset): array => [
            'asset' => $asset,
            'source' => $sources->get((int) $asset->inherited_from_asset_id),
        ])->values();
    }

    /**
     * Por que a linha não pode ser aprovada para a revisão pendente, ou `null`:
     * a ocupação tem de continuar com o arquivo da vigente de que este herdou.
     */
    public function pendingRevisionClaimError(Measurement $revision, MeasurementAsset $asset, MeasurementPlanLine $line, string $label): ?string
    {
        $source = MeasurementAsset::query()->find($asset->inherited_from_asset_id);
        $holder = MeasurementAsset::query()->where('line_claim_key', $line->lineage_key)->value('id');

        if ($source instanceof MeasurementAsset
            && $source->line_claim_key === $line->lineage_key
            && (int) $holder === (int) $source->getKey()) {
            return null;
        }

        return sprintf(
            'A medição prevista %s (%s) de %s não está mais ocupada pela revisão vigente que esta revisão corrige. Atualize a página.',
            str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT),
            $line->measurement_date?->format('m/Y') ?? 'sem data',
            $label,
        );
    }

    /**
     * A família de uma medição, da R0 à mais recente, com o que a tela de
     * histórico mostra.
     *
     * @return EloquentCollection<int, Measurement>
     */
    public function family(Measurement $member): EloquentCollection
    {
        return Measurement::query()
            ->where('revision_family_id', $member->familyRootId())
            ->with(['revisionCreator', 'uploadedByUser', 'revisionCloser', 'reviews', 'payments'])
            ->orderBy('revision_number')
            ->get();
    }

    /**
     * O que a tela de uma medição mostra das revisões dela: a família da R0 à
     * mais recente, a situação desta revisão e, para a revisão com Engenharia
     * registrada, a comparação com a revisão que ela substitui e a posição
     * financeira de cada empreendimento. Datas em DD/MM/AAAA.
     *
     * Na página de uma revisão substituída (ou da original já revisada), a
     * posição financeira vigente da família -- a da revisão que vale hoje --
     * aparece à parte, sem mudar a situação desta. Na revisão vigente em
     * reanálise da Engenharia, sem registro aprovado, vale a última posição
     * conhecida: a da última diferença registrada, com os pagamentos de agora.
     *
     * @return array{
     *     is_revision: bool,
     *     label: string,
     *     reason: string|null,
     *     previous_label: string|null,
     *     frozen_by: string|null,
     *     superseded_by: array{label: string, at: string|null, url: string|null}|null,
     *     family: list<array<string, mixed>>,
     *     positions: list<MeasurementRevisionPlanSetPosition>,
     *     last_known_positions: list<array<string, mixed>>,
     *     has_unresolved_difference: bool,
     *     family_position: array<string, mixed>|null,
     *     acknowledgments: list<array<string, mixed>>,
     *     paid_source: array<string, mixed>|null
     * }
     */
    public function overview(Measurement $measurement): array
    {
        $family = $this->family($measurement);
        $date = fn (mixed $value): ?string => $value instanceof \DateTimeInterface ? BusinessTime::at($value)->format('d/m/Y') : null;
        $url = fn (Measurement $member): ?string => rescue(
            fn (): string => MeasurementResource::getUrl('view', ['record' => $member]),
            null,
            report: false,
        );
        $positions = $measurement->isRevision() ? $this->positions($measurement) : [];
        $lastKnown = $measurement->isRevision() && $positions === [] && $measurement->isEffectiveRevision()
            ? $this->lastKnownPositions($measurement)
            : [];
        $successor = $family->first(fn (Measurement $member): bool => (int) $member->previous_revision_id === (int) $measurement->getKey()
            && in_array($member->revisionStatus(), [MeasurementRevisionStatus::Effective, MeasurementRevisionStatus::Superseded], true));
        $reviewing = $family->first(fn (Measurement $member): bool => (int) $member->previous_revision_id === (int) $measurement->getKey()
            && $member->revisionStatus() === MeasurementRevisionStatus::UnderReview);
        $previous = $family->firstWhere('id', (int) $measurement->previous_revision_id);
        $effective = $family->first(fn (Measurement $member): bool => $member->isEffectiveRevision());

        return [
            'is_revision' => $measurement->isRevision(),
            'label' => $measurement->isRevision() ? 'Revisão '.$measurement->revisionLabel() : 'Medição original (R0)',
            'reason' => $measurement->revision_reason,
            'previous_label' => $previous instanceof Measurement ? $previous->revisionLabel() : null,
            'frozen_by' => $measurement->isOpen() && $measurement->isEffectiveRevision() && $reviewing instanceof Measurement
                ? $reviewing->revisionLabel()
                : null,
            'superseded_by' => $measurement->revisionStatus() === MeasurementRevisionStatus::Superseded && $successor instanceof Measurement
                ? ['label' => $successor->revisionLabel(), 'at' => $date($measurement->revision_superseded_at), 'url' => $url($successor)]
                : null,
            'family' => $family->map(fn (Measurement $member): array => [
                'id' => (int) $member->getKey(),
                'label' => $member->isRevision() ? $member->revisionLabel() : 'R0 · Original',
                'is_current_page' => (int) $member->getKey() === (int) $measurement->getKey(),
                'revision_status' => $member->revisionStatus(),
                'workflow_status' => $member->workflowStatusLabel(),
                'reason' => $member->revision_reason,
                'created_by' => $member->isRevision() ? $member->revisionCreator?->name : $member->uploadedByUser?->name,
                'created_at' => $date($member->created_at),
                'submitted_at' => $date($member->uploaded_at),
                'effective_at' => $date($member->revision_effective_at),
                'superseded_at' => $date($member->revision_superseded_at),
                'closed_at' => $date($member->revision_closed_at),
                'closed_reason' => $member->revision_closed_reason,
                'closed_by' => $member->revisionCloser?->name,
                'paid_amount' => $member->payments->reduce(fn (string $total, MeasurementPayment $payment): string => bcadd($total, (string) $payment->amount, 2), '0.00'),
                'payments_count' => $member->payments->count(),
                'url' => $url($member),
            ])->values()->all(),
            'positions' => $positions,
            'last_known_positions' => $lastKnown,
            // A revisão substituída é histórico: o que está em aberto hoje é a
            // posição da vigente ('family_position').
            'has_unresolved_difference' => collect($positions)->contains(
                fn (MeasurementRevisionPlanSetPosition $position): bool => ! $position->isHistorical()
                    && ($position->adjustmentStatus->isUnresolved() || $position->isExposureUnmeasurable()),
            ) || collect($lastKnown)->contains(fn (array $position): bool => $position['unresolved']),
            'family_position' => $effective instanceof Measurement
                && (int) $effective->getKey() !== (int) $measurement->getKey()
                && $effective->isRevision()
                ? $this->familyPosition($effective, $url($effective))
                : null,
            'acknowledgments' => $measurement->isRevision() ? $this->acknowledgments($measurement, $positions, $family) : [],
            'paid_source' => $measurement->isRevision() && $previous instanceof Measurement && $previous->payments->isNotEmpty()
                ? [
                    'label' => $previous->revisionLabel(),
                    'finalized' => $previous->status === 'finalized',
                    'paid_amount' => $previous->payments->reduce(fn (string $total, MeasurementPayment $payment): string => bcadd($total, (string) $payment->amount, 2), '0.00'),
                    'payments_count' => $previous->payments->count(),
                ]
                : null,
        ];
    }

    /**
     * A posição financeira vigente da família, lida da revisão que vale hoje:
     * a calculada, ou a última conhecida enquanto a Engenharia reanalisa a
     * revisão vigente.
     *
     * @return array{label: string, url: string|null, positions: list<MeasurementRevisionPlanSetPosition>, last_known_positions: list<array<string, mixed>>, has_unresolved_difference: bool}
     */
    private function familyPosition(Measurement $effective, ?string $url): array
    {
        $positions = $this->positions($effective);
        $lastKnown = $positions === [] ? $this->lastKnownPositions($effective) : [];

        return [
            'label' => $effective->revisionLabel(),
            'url' => $url,
            'positions' => $positions,
            'last_known_positions' => $lastKnown,
            'has_unresolved_difference' => collect($positions)->contains(
                fn (MeasurementRevisionPlanSetPosition $position): bool => $position->adjustmentStatus->isUnresolved() || $position->isExposureUnmeasurable(),
            ) || collect($lastKnown)->contains(fn (array $position): bool => $position['unresolved']),
        ];
    }

    /**
     * A última posição financeira conhecida da revisão vigente que voltou à
     * Engenharia: a da última diferença registrada (o aprovado e o pago antes
     * dela no momento), com o que a própria revisão pagou até agora. O
     * aprovado é o do registro invalidado -- a reanálise pode mudá-lo.
     *
     * @return list<array{plan_set_id: int, label: string, revised_approved_amount: string|null, family_paid_amount: string, outstanding_overpayment_amount: string|null, remaining_payable_amount: string|null, invalidated_at: string|null, unresolved: bool}>
     */
    public function lastKnownPositions(Measurement $revision): array
    {
        if (! $revision->isRevision()) {
            return [];
        }

        $rows = MeasurementRevisionDifference::query()
            ->where('measurement_id', $revision->getKey())
            ->with('planSet.construction')
            ->orderByDesc('computation')
            ->orderBy('plan_set_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $latest = $rows->where('computation', $rows->max('computation'));
        $own = $this->ownPaidByPlanSet($revision);

        return $latest->map(function (MeasurementRevisionDifference $row) use ($own): array {
            $planSetId = (int) $row->plan_set_id;
            $approved = $row->revised_approved_amount === null ? null : (string) $row->revised_approved_amount;
            $familyPaid = bcadd((string) $row->historical_paid_amount, $own[$planSetId] ?? '0.00', 2);
            $balance = $approved === null ? null : bcsub($approved, $familyPaid, 2);

            return [
                'plan_set_id' => $planSetId,
                'label' => (string) ($row->planSet?->construction?->development_name ?? $row->planSet?->name ?? '—'),
                'revised_approved_amount' => $approved,
                'family_paid_amount' => $familyPaid,
                'outstanding_overpayment_amount' => $balance === null ? null : self::nonNegative(bcsub('0', $balance, 2)),
                'remaining_payable_amount' => $balance === null ? null : self::nonNegative($balance),
                'invalidated_at' => $row->superseded_at === null ? null : BusinessTime::at($row->superseded_at)->format('d/m/Y'),
                'unresolved' => $balance === null
                    ? bccomp((string) $row->historical_paid_amount, '0', 2) > 0
                    : bccomp($balance, '0', 2) !== 0 && bccomp((string) $row->historical_paid_amount, '0', 2) > 0,
            ];
        })->values()->all();
    }

    /**
     * Quem reconheceu o valor pago a maior em aberto: a própria revisão, se
     * finalizada com decisão a reconhecer, e as finalizações anteriores cuja
     * cobertura as posições apontam -- nunca uma revisão que não reconheceu
     * nada. A decisão é a nota da aprovação da etapa Pagamento; o aceite, a
     * Finalização.
     *
     * @param  list<MeasurementRevisionPlanSetPosition>  $positions
     * @param  EloquentCollection<int, Measurement>  $family
     * @return list<array{label: string, decision: string|null, decided_by: string|null, decided_at: string|null, accepted_by: string|null, accepted_at: string|null, scope: string}>
     */
    private function acknowledgments(Measurement $revision, array $positions, EloquentCollection $family): array
    {
        $records = [];
        // Substituída depois de finalizada, a revisão continua tendo reconhecido
        // o que a Finalização dela aceitou: vale a situação de quando era vigente.
        $ownAcknowledgment = $revision->status === 'finalized' && collect($positions)->contains(
            fn (MeasurementRevisionPlanSetPosition $position): bool => in_array($position->statusWhenEffective ?? $position->adjustmentStatus, [
                MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered,
                MeasurementRevisionAdjustmentStatus::OmissionAccepted,
            ], true),
        );

        if ($ownAcknowledgment) {
            $records[] = $this->acknowledgmentRecord($revision, 'nesta revisão');
        }

        $origins = collect($positions)->flatMap(fn (MeasurementRevisionPlanSetPosition $position): array => $position->previouslyAcknowledgedByMeasurementIds)->unique()->sort()->values();

        foreach ($origins as $originId) {
            $origin = $family->firstWhere('id', (int) $originId);

            if ($origin instanceof Measurement) {
                $records[] = $this->acknowledgmentRecord($origin, 'anteriormente');
            }
        }

        return $records;
    }

    /**
     * @return array{label: string, decision: string|null, decided_by: string|null, decided_at: string|null, accepted_by: string|null, accepted_at: string|null, scope: string}
     */
    private function acknowledgmentRecord(Measurement $member, string $scope): array
    {
        $reviews = $member->relationLoaded('reviews') ? $member->reviews : $member->reviews()->get();
        $decision = $reviews->first(fn (MeasurementReview $review): bool => (int) $review->stage === MeasurementWorkflow::STAGE_PAYMENT && $review->status === 'approved');
        $acceptance = $reviews->first(fn (MeasurementReview $review): bool => (int) $review->stage === MeasurementWorkflow::STAGE_FINALIZATION && $review->status === 'approved');
        $userName = fn (?int $id): ?string => $id === null ? null : User::query()->whereKey($id)->value('name');

        return [
            'label' => $member->isRevision() ? $member->revisionLabel() : 'R0',
            'decision' => filled($decision?->notes) ? (string) $decision->notes : null,
            'decided_by' => $userName($decision?->reviewer_user_id === null ? null : (int) $decision->reviewer_user_id),
            'decided_at' => $decision?->reviewed_at === null ? null : BusinessTime::at($decision->reviewed_at)->format('d/m/Y'),
            'accepted_by' => $userName($acceptance?->reviewer_user_id === null ? null : (int) $acceptance->reviewer_user_id),
            'accepted_at' => $acceptance?->reviewed_at === null ? null : BusinessTime::at($acceptance->reviewed_at)->format('d/m/Y'),
            'scope' => $scope,
        ];
    }

    /**
     * O rascunho foi criado sobre o registro da Engenharia que a revisão
     * anterior tinha naquele momento. Se ela voltou à Engenharia e foi aprovada
     * de novo com outro registro -- outro arquivo, outro percentual --, a
     * revisão herdou um contexto que deixou de valer e não segue.
     */
    private function assertPreviousSnapshotUnchanged(Measurement $revision, Measurement $previous): void
    {
        $expected = $revision->previous_snapshot_sha256;
        $snapshot = $previous->engineering_snapshot;

        if (! is_string($expected) || ! is_array($snapshot) || ! hash_equals($expected, self::canonicalSnapshotHash($snapshot))) {
            throw new MeasurementWorkflowException(sprintf(
                'A Engenharia da revisão %s aprovou outro registro depois da criação desta revisão: os arquivos e o contexto herdados deixaram de valer. Cancele a revisão %s e crie uma nova.',
                $previous->revisionLabel(),
                $revision->revisionLabel(),
            ), ['measurement_id' => $revision->getKey(), 'previous_revision_id' => $previous->getKey()]);
        }
    }

    /**
     * SHA-256 do snapshot em forma canônica: chaves ordenadas em todos os
     * níveis e flags fixas. O MySQL reordena as chaves de um objeto JSON ao
     * gravar; sem a forma canônica, o mesmo snapshot teria um hash na memória
     * e outro relido do banco.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function canonicalSnapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonical($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }

    /**
     * Por que a medição vigente não pode ser revisada agora, ou `null`.
     */
    private function eligibilityError(Measurement $measurement): ?string
    {
        if (! $measurement->isEffectiveRevision()) {
            return 'Só a revisão vigente de uma medição pode ser revisada.';
        }

        $stateError = $this->eligibleStateError($measurement);

        if ($stateError !== null) {
            return $stateError;
        }

        $snapshot = $measurement->engineering_snapshot;

        if (! $measurement->hasApprovedEngineering()
            || ! is_array($snapshot)
            || (int) ($snapshot['schema_version'] ?? 0) !== MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION
            || (int) ($snapshot['measurement_id'] ?? 0) !== (int) $measurement->getKey()
            || ! is_array($snapshot['plan_sets'] ?? null)
            || $snapshot['plan_sets'] === []) {
            return 'A medição não tem o registro detalhado da aprovação da Engenharia (aprovada antes desse registro existir): não há contexto congelado para revisá-la.';
        }

        $assets = $measurement->assets()->orderBy('id')->get();
        $snapshotAssetIds = collect($snapshot['plan_sets'])->pluck('asset_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($assets->isEmpty()
            || $assets->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all() !== $snapshotAssetIds
            || $assets->contains(fn (MeasurementAsset $asset): bool => blank($asset->plan_set_id) || blank($asset->plan_line_id) || blank($asset->plan_version_id))) {
            return 'Os arquivos da medição não correspondem ao registro aprovado pela Engenharia: não há contexto congelado para revisá-la.';
        }

        if ($assets->contains(fn (MeasurementAsset $asset): bool => ! $this->storage->isAllowedMeasurementWriteDisk($asset->resolved_storage_disk))) {
            return 'Um arquivo desta medição ainda está no armazenamento legado: proteja os arquivos legados (measurements:secure-legacy-files) antes de revisá-la.';
        }

        $snapshotPlanSetIds = collect($snapshot['plan_sets'])->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all();
        $strayPayments = MeasurementPayment::query()
            ->whereIn('measurement_id', Measurement::query()
                ->where('revision_family_id', $measurement->familyRootId())
                ->where('revision_number', '<=', $measurement->revisionNumber())
                ->select('id'))
            ->where(fn ($payments) => $payments->whereNull('plan_set_id')->orWhereNotIn('plan_set_id', $snapshotPlanSetIds))
            ->exists();

        if ($strayPayments) {
            return 'Há pagamento desta medição sem empreendimento ou fora do contexto aprovado pela Engenharia: não é possível calcular a diferença financeira de uma revisão.';
        }

        return $this->eligibilityCase($measurement) === 'paid_awaiting_finalization'
            ? $this->paidSourceDocumentationError($measurement)
            : null;
    }

    /**
     * Os três estados de que se revisa a vigente -- A, a finalizada; B, a
     * parada na etapa Pagamento sem pagamento; C, a paga e ainda não
     * finalizada, na Finalização com a documentação financeira completa --, ou
     * por que o estado atual não serve, com o caminho até um deles. Lido da
     * linha travada (a situação e a etapa) e de leituras comuns das análises e
     * dos pagamentos, que só quem segura a linha da medição escreve.
     */
    private function eligibleStateError(Measurement $measurement): ?string
    {
        $stage = (int) $measurement->current_stage;
        $stageLabel = MeasurementWorkflow::STAGE_LABELS[$stage] ?? (string) $stage;
        $hasOwnPayments = $measurement->payments()->exists();

        return match (true) {
            $measurement->status === 'finalized' => null,
            $measurement->status === 'awaiting_payment' && $hasOwnPayments => self::PAID_PAYMENT_STAGE_REFUSAL,
            $measurement->status === 'awaiting_payment' => $stage === MeasurementWorkflow::STAGE_PAYMENT
                && $this->hasOpenReview($measurement, MeasurementWorkflow::STAGE_PAYMENT)
                ? null
                : self::ELIGIBILITY_REFUSAL,
            in_array($measurement->status, ['approved', 'awaiting_receipt'], true) && ! $hasOwnPayments => self::UNPAID_FINALIZATION_REFUSAL,
            $measurement->status === 'awaiting_receipt' => self::PAID_RECEIPTS_PENDING_REFUSAL,
            $measurement->status === 'approved' => $stage === MeasurementWorkflow::STAGE_FINALIZATION
                && $this->hasOpenReview($measurement, MeasurementWorkflow::STAGE_FINALIZATION)
                ? null
                : self::ELIGIBILITY_REFUSAL,
            $measurement->status === 'paused' => self::PAUSED_REFUSAL,
            in_array($measurement->status, ['pending', 'in_review'], true) && $hasOwnPayments => sprintf(self::PAID_IN_ANALYSIS_REFUSAL, $stageLabel),
            in_array($measurement->status, ['pending', 'in_review'], true) => sprintf(self::IN_ANALYSIS_REFUSAL, $stageLabel),
            default => self::ELIGIBILITY_REFUSAL,
        };
    }

    private function hasOpenReview(Measurement $measurement, int $stage): bool
    {
        return $measurement->reviews()
            ->where('stage', $stage)
            ->where('status', 'pending')
            ->whereNull('paused_at')
            ->exists();
    }

    /**
     * Qual dos casos de revisão a vigente é agora: 'finalized' (A),
     * 'awaiting_payment_unpaid' (B), 'paid_awaiting_finalization' (C) -- ou
     * `null` fora deles. Só descreve; quem decide é {@see self::eligibilityError()}.
     */
    private function eligibilityCase(Measurement $measurement): ?string
    {
        return match (true) {
            $measurement->status === 'finalized' => 'finalized',
            $measurement->status === 'awaiting_payment' && ! $measurement->payments()->exists() => 'awaiting_payment_unpaid',
            $measurement->status === 'approved' && $measurement->payments()->exists() => 'paid_awaiting_finalization',
            default => null,
        };
    }

    /**
     * A medição paga ainda não finalizada só é revisada com os pagamentos
     * comprovados e enquadrados: cada um com o comprovante atual aprovado e com
     * o enquadramento financeiro congelado no registro da Engenharia vigente --
     * o que a Finalização dela exigiria. Os pagamentos ficam com ela e viram o
     * histórico da família; depois da substituição ninguém mais os confere.
     * Leituras comuns, sem lock: os pagamentos e os comprovantes só mudam sob o
     * lock da linha da medição, que quem chama já segura (ou, na tela, ainda
     * será conferido de novo sob ele).
     */
    private function paidSourceDocumentationError(Measurement $measurement): ?string
    {
        $financial = app(MeasurementPaymentFinancialService::class);

        foreach (MeasurementPayment::query()
            ->where('measurement_id', $measurement->getKey())
            ->with('currentReceiptEvidence')
            ->orderBy('id')
            ->get() as $payment) {
            if ($payment->currentReceiptEvidence?->review_status !== MeasurementReceiptReviewStatus::Approved) {
                return self::PAID_RECEIPTS_PENDING_REFUSAL;
            }

            if (! $financial->assessmentMatchesSnapshot($measurement, $payment)) {
                return sprintf(
                    'O enquadramento financeiro do pagamento #%d não corresponde mais ao registro aprovado pela Engenharia: devolva a medição à etapa Pagamento para reavaliá-lo antes de revisá-la.',
                    $payment->getKey(),
                );
            }
        }

        return null;
    }

    /**
     * Os arquivos que provam os pagamentos da medição paga ainda não
     * finalizada -- o comprovante atual de cada pagamento e o documento de
     * suporte do enquadramento financeiro -- estão íntegros (SHA-256 do que foi
     * aprovado)? Depois da substituição, nenhuma Finalização os confere de
     * novo, então a conferência se repete em cada decisão que leva à
     * substituição: a criação do rascunho, o envio e a aprovação da Compliance.
     *
     * Roda antes da transação, como a varredura dos arquivos herdados: lê o
     * armazenamento, e não roda com a Operation travada. Lê a medição de novo
     * (o model de quem chama pode estar velho) e devolve a versão do fluxo que
     * conferiu -- null se ela não estava paga aguardando a Finalização --, que
     * a transação confirma sob o lock ({@see self::assertPaidSourceEvidenceVerified()}).
     *
     * @throws MeasurementWorkflowException
     */
    private function verifyPaidSourceEvidence(int $sourceId, bool $suspendedByRevision = false): ?int
    {
        $source = Measurement::query()->find($sourceId);

        if (! $source instanceof Measurement || $source->status !== 'approved' || ! $source->isEffectiveRevision()) {
            return null;
        }

        // Com a revisão em análise, a anterior está suspensa e não recebe
        // comprovante nem volta à etapa Pagamento: só a recusa da revisão na
        // Engenharia a devolve ao fluxo para substituir o arquivo.
        $label = $source->revisionLabel();
        $receiptGuidance = $suspendedByRevision
            ? sprintf('a revisão não pode substituir a %1$s assim. A %1$s está suspensa por esta revisão e só volta ao fluxo, para receber o comprovante novo, com a recusa da revisão na Engenharia.', $label)
            : 'envie uma nova versão do comprovante antes de revisar a medição.';
        $supportGuidance = $suspendedByRevision
            ? sprintf('a revisão não pode substituir a %1$s assim. A %1$s está suspensa por esta revisão e só volta ao fluxo, para substituir o documento na etapa Pagamento, com a recusa da revisão na Engenharia.', $label)
            : 'devolva a medição à etapa Pagamento para substituí-lo antes de revisá-la.';

        $financial = app(MeasurementPaymentFinancialService::class);
        $receipts = app(MeasurementReceiptEvidenceService::class);

        foreach ($source->payments()->with('currentReceiptEvidence')->orderBy('id')->get() as $payment) {
            try {
                if ($payment->currentReceiptEvidence instanceof MeasurementPaymentReceiptEvidence) {
                    $receipts->ensureIntegrity($payment->currentReceiptEvidence);
                }
            } catch (ValidationException) {
                throw new MeasurementWorkflowException(sprintf(
                    'O comprovante do pagamento #%d está ausente ou não corresponde ao conteúdo aprovado: %s',
                    $payment->getKey(),
                    $receiptGuidance,
                ), ['measurement_id' => $source->getKey(), 'payment_id' => $payment->getKey()]);
            }

            $support = $payment->financial_assessment['support'] ?? null;

            if (is_array($support)) {
                try {
                    $financial->ensureSupportIntegrity($support);
                } catch (ValidationException) {
                    throw new MeasurementWorkflowException(sprintf(
                        'O documento de suporte do enquadramento do pagamento #%d está ausente ou inválido: %s',
                        $payment->getKey(),
                        $supportGuidance,
                    ), ['measurement_id' => $source->getKey(), 'payment_id' => $payment->getKey()]);
                }
            }
        }

        return (int) $source->workflow_revision;
    }

    /**
     * Sob o lock da família: a anterior paga aguardando a Finalização só segue
     * se os arquivos dela foram conferidos nesta mesma versão do fluxo. Se ela
     * chegou a esse estado, ou mudou, depois da conferência feita fora da
     * transação, o pedido ficou velho.
     *
     * @throws MeasurementWorkflowException
     */
    private function assertPaidSourceEvidenceVerified(Measurement $source, ?int $verifiedWorkflowRevision): void
    {
        if ($source->status !== 'approved' || ! $source->isEffectiveRevision()) {
            return;
        }

        if ($verifiedWorkflowRevision === null || $verifiedWorkflowRevision !== (int) $source->workflow_revision) {
            throw new MeasurementWorkflowException(self::STALE_REVISION_REFUSAL, [
                'measurement_id' => $source->getKey(),
                'verified_workflow_revision' => $verifiedWorkflowRevision,
                'locked_workflow_revision' => (int) $source->workflow_revision,
            ]);
        }
    }

    /**
     * A conferência dos arquivos da anterior paga, antes da aprovação da
     * Compliance que faz a revisão em análise valer (a transação da aprovação
     * confirma a versão em {@see self::makeEffective()}).
     *
     * @throws MeasurementWorkflowException
     */
    public function verifyPaidSourceEvidenceBeforeEffectiveness(Measurement $revision): ?int
    {
        return $revision->isRevision() && $revision->previous_revision_id !== null
            ? $this->verifyPaidSourceEvidence((int) $revision->previous_revision_id, suspendedByRevision: true)
            : null;
    }

    /**
     * Os pagamentos de uma medição como a auditoria registra: o histórico que a
     * revisão preserva, nunca copiado.
     *
     * @return array{count: int, total_amount: string, payment_ids: list<int>}
     */
    private function paymentSummary(Measurement $measurement): array
    {
        $payments = MeasurementPayment::query()
            ->where('measurement_id', $measurement->getKey())
            ->orderBy('id')
            ->get(['id', 'amount']);

        return [
            'count' => $payments->count(),
            'total_amount' => $payments->reduce(fn (string $total, MeasurementPayment $payment): string => bcadd($total, (string) $payment->amount, 2), '0.00'),
            'payment_ids' => $payments->map(fn (MeasurementPayment $payment): int => (int) $payment->getKey())->values()->all(),
        ];
    }

    /**
     * As análises da medição que ainda esperam decisão, travadas pela chave
     * primária -- lidas por uma leitura comum que basta, porque só quem segura
     * a linha da medição as escreve --, sem nunca travar chave inexistente nem
     * intervalo do índice (measurement_id, stage).
     *
     * @return EloquentCollection<int, MeasurementReview>
     */
    private function lockPendingReviews(Measurement $measurement): EloquentCollection
    {
        $ids = MeasurementReview::query()
            ->where('measurement_id', $measurement->getKey())
            ->where('status', 'pending')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return new EloquentCollection;
        }

        return MeasurementReview::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * A fotografia desta transação (REPEATABLE READ) nasce na leitura comum
     * que antecede o lock da família ({@see self::lockFamilyWithOperation()}).
     * Um caminho que só trava a linha da medição -- comprovantes, devolução da
     * Finalização, decisão das etapas 2 a 4 -- pode ter commitado entre as
     * duas: a linha travada é a atual, mas as leituras comuns das análises,
     * pagamentos e comprovantes viriam da fotografia anterior. Todo caminho
     * desses grava `workflow_revision` na mesma linha, então a comparação da
     * linha travada com a leitura comum dela denuncia a fotografia velha, e a
     * revisão é recusada em vez de decidida sobre ela.
     *
     * @throws MeasurementWorkflowException
     */
    private function assertConsistentReadOf(Measurement $locked): void
    {
        $observed = Measurement::query()->whereKey($locked->getKey())->value('workflow_revision');

        if ((int) $observed !== (int) $locked->workflow_revision) {
            throw new MeasurementWorkflowException(self::STALE_REVISION_REFUSAL, [
                'measurement_id' => $locked->getKey(),
                'locked_workflow_revision' => (int) $locked->workflow_revision,
                'observed_workflow_revision' => (int) $observed,
            ]);
        }
    }

    /**
     * O teto de 100% com a revisão no lugar da anterior: atual − anterior +
     * revisão, com a fotografia nascida depois do lock da Operation.
     *
     * @param  EloquentCollection<int, MeasurementPlanSet>  $planSets
     * @param  Collection<int, array<string, mixed>>  $entries
     *
     * @throws ValidationException
     */
    private function assertFitsThePhysicalCeiling(Measurement $revision, Measurement $previous, EloquentCollection $planSets, Collection $entries): void
    {
        $progress = $this->physicalProgress->compose(
            $this->physicalProgress->sources((int) $revision->operation_id),
            $planSets,
            (int) $previous->getKey(),
        );
        $errors = [];

        foreach ($planSets as $planSet) {
            $entry = $entries->get((int) $planSet->getKey());
            $monthly = (int) (MeasurementPhysicalProgress::basisPoints($entry['realized_monthly_percent'] ?? null) ?? -1);
            $current = $progress[(int) $planSet->getKey()] ?? null;
            $label = $this->planSetLabel($planSet);

            if (! $current instanceof MeasurementPhysicalProgress || $monthly < 0) {
                $errors["revision.ceiling.{$planSet->getKey()}"] = "Não foi possível conferir o avanço físico de {$label} para a revisão passar a valer.";

                continue;
            }

            if (! $current->isVerifiable()) {
                $errors["revision.ceiling.{$planSet->getKey()}"] = sprintf(
                    'Não foi possível conferir o limite de 100%% de %s: a medição #%d tem a Engenharia aprovada sem o avanço físico registrado.',
                    $label,
                    $current->unverifiedMeasurementIds[0],
                );

                continue;
            }

            if ($current->exceedsLimitWith($monthly)) {
                $errors["revision.ceiling.{$planSet->getKey()}"] = sprintf(
                    'A revisão %s não cabe mais no limite de 100%% de %s: depois da Engenharia dela, outra medição foi aprovada. Avanço atual sem a %s: %s. Avanço da revisão: %s. Máximo restante: %s. Recuse para devolver a revisão à Gestão e à Engenharia.',
                    $revision->revisionLabel(),
                    $label,
                    $previous->revisionLabel(),
                    MeasurementPhysicalProgress::format($current->currentBasisPoints()),
                    MeasurementPhysicalProgress::format($monthly),
                    MeasurementPhysicalProgress::format($current->remainingBasisPoints()),
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A família da revisão até ela, numa leitura: o que as revisões anteriores
     * pagaram por empreendimento (em qualquer situação -- a substituída antes
     * de finalizar também pagou) e, para cada uma finalizada, o que a família
     * tinha pago até ela, com o snapshot que ela aprovou.
     *
     * @return array{historical: array<int, string>, finalized: list<array{id: int, number: int, snapshot: mixed, paid: array<int, string>}>}
     */
    private function familyLedger(Measurement $revision): array
    {
        $members = Measurement::query()
            ->where('revision_family_id', $revision->familyRootId())
            ->where('revision_number', '<', $revision->revisionNumber())
            ->orderBy('revision_number')
            ->get(['id', 'revision_number', 'revision_family_id', 'revision_status', 'status', 'engineering_snapshot']);
        $paidByMember = [];

        if ($members->isNotEmpty()) {
            foreach (MeasurementPayment::query()
                ->whereIn('measurement_id', $members->modelKeys())
                ->whereNotNull('plan_set_id')
                ->orderBy('id')
                ->get(['id', 'measurement_id', 'plan_set_id', 'amount']) as $payment) {
                $memberId = (int) $payment->measurement_id;
                $planSetId = (int) $payment->plan_set_id;
                $paidByMember[$memberId][$planSetId] = bcadd($paidByMember[$memberId][$planSetId] ?? '0.00', (string) $payment->amount, 2);
            }
        }

        $running = [];
        $finalized = [];

        foreach ($members as $member) {
            foreach ($paidByMember[(int) $member->getKey()] ?? [] as $planSetId => $amount) {
                $running[$planSetId] = bcadd($running[$planSetId] ?? '0.00', $amount, 2);
            }

            if ($member->status === 'finalized') {
                $finalized[] = [
                    'id' => (int) $member->getKey(),
                    'number' => $member->revisionNumber(),
                    'snapshot' => $member->engineering_snapshot,
                    'paid' => $running,
                ];
            }
        }

        return ['historical' => $running, 'finalized' => $finalized];
    }

    /**
     * A parte do valor pago a maior que uma finalização anterior já reconheceu,
     * medida contra o aprovado de agora. Cada finalização F reconheceu o que,
     * ao finalizar, a família tinha pago acima do aprovado dela (X_F); contra o
     * aprovado de agora, ela cobre no máximo o que os pagamentos que ela viu
     * passam deste aprovado. Vale a finalização que cobre mais (nunca a soma:
     * os reconhecimentos se sobrepõem). Pagamento feito depois da última
     * finalização nunca é reconhecido por ela.
     *
     * @param  list<array{id: int, number: int, snapshot: mixed, paid: array<int, string>}>  $finalized
     * @return array{amount: string, by: list<int>}
     */
    private function previouslyAcknowledgedOverpayment(array $finalized, int $planSetId, ?string $revisedApproved): array
    {
        $best = '0.00';
        $by = [];

        if ($revisedApproved === null) {
            return ['amount' => $best, 'by' => $by];
        }

        foreach ($finalized as $member) {
            $entry = $this->snapshotEntries($member['snapshot'])->get($planSetId);
            $approvedThen = is_array($entry) ? $this->reconciliation->expectedAmountForSnapshotEntry($entry) : null;

            if ($approvedThen === null) {
                continue;
            }

            $paidThen = $member['paid'][$planSetId] ?? '0.00';
            $covered = self::minimum(
                self::nonNegative(bcsub($paidThen, $approvedThen, 2)),
                self::nonNegative(bcsub($paidThen, $revisedApproved, 2)),
            );
            // No empate vale a finalização mais antiga: a seguinte só confirmou o
            // que ela já tinha reconhecido, sem decidir nada.
            if (bccomp($covered, $best, 2) > 0) {
                $best = $covered;
                $by = [$member['id']];
            }
        }

        return ['amount' => $best, 'by' => $by];
    }

    /**
     * As entradas `plan_sets[]` de um snapshot, indexadas por empreendimento.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function snapshotEntries(mixed $snapshot): Collection
    {
        return collect(is_array($snapshot) && is_array($snapshot['plan_sets'] ?? null) ? $snapshot['plan_sets'] : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->keyBy(fn (array $entry): int => (int) ($entry['plan_set_id'] ?? 0));
    }

    private static function nonNegative(string $amount): string
    {
        return bccomp($amount, '0', 2) > 0 ? bcadd($amount, '0', 2) : '0.00';
    }

    private static function minimum(string $left, string $right): string
    {
        return bccomp($left, $right, 2) <= 0 ? bcadd($left, '0', 2) : bcadd($right, '0', 2);
    }

    /**
     * A situação do ajuste de um empreendimento. A revisão substituída não tem
     * ajuste próprio: a posição que vale é a da revisão seguinte. Sem pagamento
     * da família antes da revisão, o pagamento dela não é "complementar" -- é o
     * pagamento da competência, na etapa Pagamento de sempre. Regularizada só
     * depois da Finalização, que confere os comprovantes; antes, o pagamento
     * está registrado. Valor pago a maior nunca é regularizado: o sistema não
     * registra devolução, e o reconhecimento só o torna expresso.
     */
    private function adjustmentStatus(?string $openBalance, ?string $unacknowledged, string $ownExcess, string $ownPaid, string $historicalPaid, bool $finalized, bool $superseded): MeasurementRevisionAdjustmentStatus
    {
        if ($openBalance === null) {
            return MeasurementRevisionAdjustmentStatus::ReferenceUnavailable;
        }

        if ($superseded) {
            return MeasurementRevisionAdjustmentStatus::PositionSuperseded;
        }

        $hasOwnPayment = bccomp($ownPaid, '0', 2) > 0;
        $hasHistoricalPayment = bccomp($historicalPaid, '0', 2) > 0;
        // Reconhecido nesta revisão: o pago a maior que nenhuma finalização
        // anterior cobre, ou o que passa do aprovado por pagamento desta.
        $acknowledgedHere = bccomp((string) $unacknowledged, '0', 2) > 0 || bccomp($ownExcess, '0', 2) > 0;

        return match (bccomp($openBalance, '0', 2)) {
            -1 => match (true) {
                $acknowledgedHere && $finalized => MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered,
                $acknowledgedHere => MeasurementRevisionAdjustmentStatus::OverpaymentPendingDecision,
                default => MeasurementRevisionAdjustmentStatus::OverpaymentPreviouslyAccepted,
            },
            1 => match (true) {
                $finalized => MeasurementRevisionAdjustmentStatus::OmissionAccepted,
                $hasHistoricalPayment && $hasOwnPayment => MeasurementRevisionAdjustmentStatus::PartialSupplementaryPayment,
                $hasHistoricalPayment => MeasurementRevisionAdjustmentStatus::AwaitingSupplementaryPayment,
                default => MeasurementRevisionAdjustmentStatus::AwaitingRevisionPayment,
            },
            default => match (true) {
                ! $hasOwnPayment => MeasurementRevisionAdjustmentStatus::NoAdjustment,
                $hasHistoricalPayment => $finalized
                    ? MeasurementRevisionAdjustmentStatus::SettledBySupplementaryPayment
                    : MeasurementRevisionAdjustmentStatus::SupplementaryPaymentRegistered,
                default => $finalized
                    ? MeasurementRevisionAdjustmentStatus::SettledByRevisionPayment
                    : MeasurementRevisionAdjustmentStatus::RevisionPaymentRegistered,
            },
        };
    }

    /**
     * @return array<int, string>
     */
    private function ownPaidByPlanSet(Measurement $revision): array
    {
        $totals = [];

        foreach (MeasurementPayment::query()
            ->where('measurement_id', $revision->getKey())
            ->whereNotNull('plan_set_id')
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'amount']) as $payment) {
            $planSetId = (int) $payment->plan_set_id;
            $totals[$planSetId] = bcadd($totals[$planSetId] ?? '0.00', (string) $payment->amount, 2);
        }

        return $totals;
    }

    /**
     * Varredura antivírus dos arquivos que a revisão vai herdar, antes da
     * transação: a varredura pode levar segundos por arquivo, e não roda com a
     * Operation travada.
     */
    private function scanFilesToInherit(Measurement $base): void
    {
        foreach ($base->assets()->orderBy('id')->get() as $asset) {
            if (blank($asset->storage_path) || ! $this->storage->isAllowedMeasurementWriteDisk($asset->resolved_storage_disk)) {
                continue;
            }

            $this->fileValidation->scanInheritedAsset((string) $asset->storage_path, $asset->resolved_storage_disk);
        }
    }

    /**
     * A R0 que ficou sem família (inserida sem o gancho `created`) é a raiz da
     * própria família: corrige pela chave primária, sob o lock.
     *
     * @param  EloquentCollection<int, Measurement>  $members
     */
    private function healRoot(EloquentCollection $members): int
    {
        $root = $members->first(fn (Measurement $member): bool => $member->revisionNumber() === 0);

        if (! $root instanceof Measurement) {
            throw new MeasurementWorkflowException('A medição original da família não foi encontrada.');
        }

        if ($root->getAttribute('revision_family_id') === null) {
            DB::table('measurements')
                ->where('id', $root->getKey())
                ->whereNull('revision_family_id')
                ->update(['revision_family_id' => $root->getKey()]);
            $root->setAttribute('revision_family_id', (int) $root->getKey());
            $root->syncOriginalAttribute('revision_family_id');
        }

        return (int) $root->getKey();
    }

    /**
     * @param  EloquentCollection<int, Measurement>  $members
     */
    private function member(EloquentCollection $members, Measurement $measurement): Measurement
    {
        $locked = $members->firstWhere('id', (int) $measurement->getKey());

        if (! $locked instanceof Measurement) {
            throw new MeasurementWorkflowException('A medição não foi encontrada.', ['measurement_id' => $measurement->getKey()]);
        }

        return $locked;
    }

    private function normalizedReason(string $reason, string $field, string $requiredMessage): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([$field => $requiredMessage]);
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::withMessages([$field => 'O motivo pode ter no máximo '.self::REASON_MAX_LENGTH.' caracteres.']);
        }

        return $reason;
    }

    /**
     * A autoridade de quem cria, envia ou cancela uma revisão: participação
     * direta na operação, ou o bypass administrativo quando é a única origem.
     * Revisar não é responsabilidade delegável.
     */
    private function reviseAuthorization(User $actor, Operation $operation): ResponsibilityAuthorization
    {
        // Só o super-admin revisa sem participar da operação
        // ({@see MeasurementAuthorizationService::hasRevisionParticipation()}).
        return $operation->hasParticipant($actor) || ! $actor->hasRole('super-admin')
            ? ResponsibilityAuthorization::direct()
            : ResponsibilityAuthorization::adminOverride();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planContext(Measurement $measurement): array
    {
        return $measurement->assets()
            ->with('planVersion:id,version_number')
            ->orderBy('id')
            ->get()
            ->map(fn (MeasurementAsset $asset): array => [
                'plan_set_id' => (int) $asset->plan_set_id,
                'plan_version_id' => $asset->plan_version_id === null ? null : (int) $asset->plan_version_id,
                'plan_version_number' => $asset->planVersion?->version_number,
                'plan_line_id' => $asset->plan_line_id === null ? null : (int) $asset->plan_line_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{plan_set_id: int, realized_monthly_percent: string|null}>
     */
    private function physicalSummary(Measurement $measurement): array
    {
        $snapshot = $measurement->engineering_snapshot;

        return collect(is_array($snapshot) ? ($snapshot['plan_sets'] ?? []) : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): array => [
                'plan_set_id' => (int) ($entry['plan_set_id'] ?? 0),
                'realized_monthly_percent' => isset($entry['realized_monthly_percent']) ? (string) $entry['realized_monthly_percent'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function differenceSummary(MeasurementRevisionDifference $difference): array
    {
        return [
            'plan_set_id' => (int) $difference->plan_set_id,
            'plan_version_id' => (int) $difference->plan_version_id,
            'computation' => (int) $difference->computation,
            'engineering_snapshot_sha256' => $difference->engineering_snapshot_sha256,
            'previous_realized_monthly_percent' => (string) $difference->previous_realized_monthly_percent,
            'revised_realized_monthly_percent' => (string) $difference->revised_realized_monthly_percent,
            'physical_difference_percent' => (string) $difference->physical_difference_percent,
            'previous_fund_amount' => $difference->previous_fund_amount === null ? null : (string) $difference->previous_fund_amount,
            'revised_fund_amount' => $difference->revised_fund_amount === null ? null : (string) $difference->revised_fund_amount,
            'previous_approved_amount' => $difference->previous_approved_amount === null ? null : (string) $difference->previous_approved_amount,
            'revised_approved_amount' => $difference->revised_approved_amount === null ? null : (string) $difference->revised_approved_amount,
            'financial_difference_amount' => $difference->financial_difference_amount === null ? null : (string) $difference->financial_difference_amount,
            'difference_type' => $difference->difference_type?->value,
            'historical_paid_amount' => (string) $difference->historical_paid_amount,
            'settled_measurement_id' => $difference->settled_measurement_id,
            'settled_approved_amount' => $difference->settled_approved_amount === null ? null : (string) $difference->settled_approved_amount,
            'unresolved_overpayment_amount' => (string) $difference->unresolved_overpayment_amount,
        ];
    }

    private function decimalOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function planSetLabel(?MeasurementPlanSet $planSet): string
    {
        return $planSet?->construction?->development_name ?? $planSet?->name ?? 'o empreendimento';
    }

    /**
     * Trilha protegida `measurement_workflow`, na medição a que o evento se
     * refere. A outra revisão vai em chaves próprias -- nunca em
     * `measurement_id`, que o histórico de ciclo exige igual ao sujeito -- e a
     * origem da autoridade sai da mesma resolução que autorizou a ação.
     *
     * @param  array<string, mixed>  $properties
     */
    private function audit(Measurement $measurement, User $actor, string $event, array $properties, ResponsibilityAuthorization $authorization, ?Measurement $related = null): void
    {
        $delegation = $authorization->delegation;

        activity('measurement_workflow')
            ->performedOn($measurement)
            ->causedBy($actor)
            ->withProperties(array_merge($properties, [
                'operation_id' => (int) $measurement->operation_id,
                'measurement_id' => (int) $measurement->getKey(),
                'revision_family_id' => $measurement->familyRootId(),
                'revision_number' => $measurement->revisionNumber(),
                'revision_status' => $measurement->revisionStatus()->value,
                'related_measurement_id' => $related?->getKey() === null ? null : (int) $related->getKey(),
                'related_revision_number' => $related?->revisionNumber(),
                'delegated' => $authorization->isDelegated(),
                'delegation_id' => $delegation?->getKey(),
                'delegator_user_id' => $delegation?->delegator_user_id,
                'delegation_scope' => $delegation ? [
                    'type' => $delegation->scope_type,
                    'operation_id' => $delegation->scope_operation_id,
                    'stage' => $delegation->scope_stage,
                    'responsibility' => $delegation->scope_responsibility,
                ] : null,
                'admin_override' => $authorization->isAdminOverride(),
                'actual_actor_user_id' => (int) $actor->getKey(),
                'workflow_revision' => (int) $measurement->workflow_revision,
            ]))
            ->log($event);
    }

    /**
     * @param  array<int, int|null>  $userIds
     */
    private function notifyAfterCommit(Measurement $measurement, string $event, array $userIds): void
    {
        $ids = array_values(array_unique(array_filter($userIds)));

        if ($ids === []) {
            return;
        }

        try {
            $recipients = User::query()->operational()->whereKey($ids)->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, (new MeasurementWorkflowNotification($measurement, $event))->afterCommit());
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
