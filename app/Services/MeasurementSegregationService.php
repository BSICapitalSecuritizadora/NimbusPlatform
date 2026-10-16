<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Exceptions\MeasurementSegregationException;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\User;
use App\Support\Delegations\ResponsibilityAuthorization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Segregação de funções da medição (maker/checker): quem fez uma ação crítica
 * não a confere.
 *
 * - **comprovante**: quem enviou a versão do comprovante não a aprova;
 * - **exceção financeira**: quem registrou o pagamento com divergência, quem
 *   reavaliou o enquadramento dele e quem aprovou a etapa Pagamento com a
 *   exceção não a aceitam na Finalização;
 * - **sem desembolso**: quem decidiu concluir a etapa Pagamento sem
 *   desembolso não dá a ciência dessa decisão na Finalização;
 * - **revisão**: quem criou ou enviou a revisão não a aprova na Compliance.
 *
 * A regra é de identidade, não de papel. O autor é o que ficou gravado --
 * colunas da ação e a trilha protegida `measurement_workflow` --, relido do
 * banco pela chave, nunca o responsável atribuído hoje na operação: trocar o
 * nome numa coluna não apaga quem fez. E vale para qualquer origem da
 * autoridade de quem confere ({@see ResponsibilityAuthorization}): direta,
 * delegada ou override administrativo -- o super-admin inclusive, ao contrário
 * do Quadro de Vendas. A delegação muda quem pode agir, não quem fez: quem
 * confere por delegação de um dos autores também é recusado, senão o autor
 * escolheria quem confere o próprio trabalho.
 *
 * Autor sem identidade (`NULL`) não tem com quem ser comparado: só acontece
 * com usuário excluído -- que não age mais -- ou com registro anterior ao
 * controle. Fica registrado na trilha como não identificado.
 *
 * As recusas são {@see MeasurementSegregationException}: texto de operador,
 * sem valores nem dados bancários.
 */
class MeasurementSegregationService
{
    public const RECEIPT_SELF_APPROVAL = 'O responsável não pode aprovar o comprovante que ele próprio enviou.';

    public const RECEIPT_UPLOADER_UNKNOWN = 'Não há registro de quem enviou esta versão do comprovante, e a conferência precisa ser de um responsável diferente de quem a enviou: envie uma nova versão do comprovante, com o motivo, para conferi-la.';

    public const FINANCIAL_EXCEPTION_SELF_ACCEPTANCE = 'A exceção financeira precisa ser aceita por um responsável diferente de quem registrou o pagamento.';

    public const NO_DISBURSEMENT_SELF_ACKNOWLEDGMENT = 'A ciência da ausência de desembolso deve ser realizada por outro responsável.';

    public const REVISION_SELF_APPROVAL = 'O responsável que criou ou enviou a revisão não pode aprová-la na Compliance.';

    public const NO_INDEPENDENT_COMPLIANCE_APPROVER = 'Não há responsável de Compliance independente para aprovar esta revisão: quem cria ou envia a revisão não pode aprová-la na Compliance, e a operação não tem outro responsável de Compliance ativo nem administrador que possa aprová-la. Defina outro responsável de Compliance na operação antes de enviar a revisão.';

    public const OVERRIDE_REASON_REQUIRED = 'Informe o motivo do override administrativo: você age nesta medição sem ser o responsável nem delegado desta etapa, e o motivo fica registrado na trilha protegida. O override não dispensa a segregação de funções.';

    public const OVERRIDE_REASON_MAX_LENGTH = 2000;

    public const CONTROL_RECEIPT = 'receipt_uploader_is_not_reviewer';

    public const CONTROL_FINANCIAL_EXCEPTION = 'financial_exception_maker_is_not_acceptor';

    public const CONTROL_NO_DISBURSEMENT = 'no_disbursement_decider_is_not_acknowledger';

    public const CONTROL_REVISION = 'revision_maker_is_not_compliance_approver';

    /**
     * Os campos da medição que são conteúdo da revisão: quem os altera faz a
     * revisão. Os do fluxo (situação, etapa, envio, análise) ficam de fora.
     */
    private const REVISION_CONTENT_FIELDS = ['reference_month', 'notes', 'filename', 'storage_path', 'storage_disk', 'sha256', 'file_size', 'mime_type'];

    public function __construct(private MeasurementAuthorizationService $authorization) {}

    /**
     * O motivo exigido quando a ação só é autorizada pelo override
     * administrativo -- nem responsável direto, nem delegado. Devolve o motivo
     * normalizado para a trilha, ou `null` quando a origem é outra (um motivo
     * enviado por quem não age por override é ignorado).
     *
     * @throws ValidationException
     */
    public function overrideReason(ResponsibilityAuthorization $authorization, ?string $reason, int $maxLength = self::OVERRIDE_REASON_MAX_LENGTH): ?string
    {
        if (! $authorization->isAdminOverride()) {
            return null;
        }

        $reason = trim((string) $reason);

        // Espaço invisível (sem quebra, de largura zero) não é motivo.
        if (preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $reason) === '') {
            throw ValidationException::withMessages(['override_reason' => self::OVERRIDE_REASON_REQUIRED]);
        }

        if (mb_strlen($reason) > $maxLength) {
            throw ValidationException::withMessages(['override_reason' => sprintf(
                'O motivo do override administrativo tem no máximo %d caracteres.',
                $maxLength,
            )]);
        }

        return $reason;
    }

    /**
     * Quem age agora nesta responsabilidade o faz só pelo override
     * administrativo? É o que mostra o campo de motivo nos modais.
     */
    public function actsByAdminOverride(User $user, Measurement $measurement, MeasurementResponsibility|int $responsibility): bool
    {
        return $this->authorization->resolveAuthorization($user, $measurement, $responsibility)->isAdminOverride();
    }

    // ── Comprovante ─────────────────────────────────────────────────────

    /**
     * @return list<array{user_id: int|null, act: string, label: string}>
     */
    public function receiptApprovalMakers(MeasurementPaymentReceiptEvidence $evidence): array
    {
        $uploadedBy = MeasurementPaymentReceiptEvidence::query()->whereKey($evidence->getKey())->value('uploaded_by');
        $label = sprintf('enviou a versão v%d do comprovante', (int) $evidence->version);
        $makers = [self::maker($uploadedBy, 'uploaded_evidence', $label)];

        // Quem enviou por delegação agiu com a autoridade do delegante: ele
        // também não confere o envio.
        foreach (Activity::query()
            ->where('log_name', 'measurement_evidence')
            ->whereIn('description', ['receipt_evidence_uploaded', 'receipt_evidence_corrected_after_finalization'])
            ->where('subject_type', $evidence->getMorphClass())
            ->where('subject_id', $evidence->getKey())
            ->get(['id', 'causer_id', 'properties']) as $activity) {
            $makers[] = self::maker($activity->causer_id, 'uploaded_evidence', $label);
            $makers = [...$makers, ...self::delegatorMaker($activity->properties['delegator_user_id'] ?? null, 'delegated_evidence_upload', sprintf('delegou o envio da versão v%d do comprovante', (int) $evidence->version))];
        }

        return $makers;
    }

    /**
     * Por que este usuário não pode aprovar esta versão do comprovante, ou
     * `null`. A rejeição continua livre: recusar o próprio envio não valida
     * nada.
     */
    public function receiptApprovalConflict(MeasurementPaymentReceiptEvidence $evidence, User $checker, ResponsibilityAuthorization $authorization): ?string
    {
        $makers = $this->receiptApprovalMakers($evidence);

        // Sem registro de quem enviou não há como comparar: a versão anterior
        // ao controle é substituída por uma nova, enviada por alguém.
        if (self::identifiedIds($makers) === []) {
            return self::RECEIPT_UPLOADER_UNKNOWN;
        }

        return $this->conflictMessage(self::RECEIPT_SELF_APPROVAL, $checker, $authorization, $makers);
    }

    /**
     * @return array<string, mixed> a conferência, para a trilha protegida
     *
     * @throws MeasurementSegregationException
     */
    public function assertReceiptApprovalIsIndependent(Measurement $measurement, MeasurementPaymentReceiptEvidence $evidence, User $checker, ResponsibilityAuthorization $authorization): array
    {
        $makers = $this->receiptApprovalMakers($evidence);

        if (self::identifiedIds($makers) === []) {
            throw new MeasurementSegregationException(self::RECEIPT_UPLOADER_UNKNOWN, [
                'control' => self::CONTROL_RECEIPT,
                'measurement_id' => $measurement->getKey(),
                'checker_user_id' => (int) $checker->getKey(),
                'maker_user_ids' => [],
            ]);
        }

        return $this->assertIndependent(self::CONTROL_RECEIPT, self::RECEIPT_SELF_APPROVAL, $measurement, $checker, $authorization, $makers);
    }

    // ── Exceção financeira na Finalização ──────────────────────────────

    /**
     * Os autores das exceções financeiras que a Finalização desta medição
     * aceita agora, quando há exceção de fato -- pagamento acima do saldo a
     * pagar (excedente), empreendimento sem pagamento justificado na etapa
     * Pagamento, valor pago a maior da revisão:
     *
     * - quem registrou cada pagamento excepcional, quem reavaliou o
     *   enquadramento dele (todas as reavaliações) e quem registrou a regra
     *   financeira aplicada (a cadeia de versões);
     * - no valor pago a maior da revisão, quem registrou os pagamentos da
     *   família que o formam;
     * - quem aprovou a etapa Pagamento com a exceção;
     * - e o delegante de cada ato feito por delegação: a autoridade usada foi
     *   a dele.
     *
     * O pagamento parcial, abaixo do saldo, é pagamento comum: o aceite dele
     * segue como sempre, sem esta conferência. Vazio sem exceção.
     *
     * @return list<array{user_id: int|null, act: string, label: string}>
     */
    public function financialExceptionMakers(Measurement $measurement): array
    {
        $paymentIds = $this->exceptionalPayments($measurement);
        $overpayments = $measurement->isRevision() ? app(MeasurementRevisionService::class)->unacknowledgedOverpayments($measurement) : [];
        $hasException = $paymentIds !== []
            || $overpayments !== []
            || app(MeasurementPaymentFinancialService::class)->unpaidRequiredPlanSets($measurement) !== [];

        if (! $hasException) {
            return [];
        }

        $makers = [];

        if ($paymentIds !== []) {
            $registrations = $this->paymentRegistrationDelegators($measurement);
            $reassessments = $this->reassessmentsByPayment($measurement);
            $rules = app(MeasurementFinancialRuleService::class);

            foreach (MeasurementPayment::query()
                ->where('measurement_id', $measurement->getKey())
                ->whereKey($paymentIds)
                ->orderBy('id')
                ->get(['id', 'created_by', 'financial_assessment']) as $payment) {
                $label = sprintf('registrou o pagamento #%d', $payment->id);
                $makers[] = self::maker($payment->created_by, 'registered_payment', $label);
                $makers = [...$makers, ...self::delegatorMaker($registrations[(int) $payment->id] ?? null, 'delegated_payment_registration', sprintf('delegou o registro do pagamento #%d', $payment->id))];
                // O enquadramento do registro é de quem registrou; só o de uma
                // reavaliação tem outro autor.
                $assessedBy = $payment->financial_assessment['assessed_by_user_id'] ?? null;

                if ($assessedBy !== null && (int) $assessedBy !== (int) $payment->created_by) {
                    $makers[] = self::maker($assessedBy, 'assessed_payment', sprintf('reavaliou o enquadramento do pagamento #%d', $payment->id));
                }

                foreach ($reassessments[(int) $payment->id] ?? [] as $reassessment) {
                    $makers[] = self::maker($reassessment['causer_id'], 'assessed_payment', sprintf('reavaliou o enquadramento do pagamento #%d', $payment->id));
                    $makers = [...$makers, ...self::delegatorMaker($reassessment['delegator_user_id'], 'delegated_payment_assessment', sprintf('delegou a reavaliação do enquadramento do pagamento #%d', $payment->id))];
                }

                $ruleId = $payment->financial_assessment['rule']['id'] ?? null;

                if (filter_var($ruleId, FILTER_VALIDATE_INT) !== false) {
                    foreach ($rules->authorsOf((int) $ruleId) as $authorId) {
                        $makers[] = self::maker($authorId, 'authored_financial_rule', sprintf('registrou a regra financeira aplicada ao pagamento #%d', $payment->id));
                    }
                }
            }
        }

        if ($overpayments !== []) {
            $makers = [...$makers, ...$this->familyPaymentMakers($measurement, array_map(fn ($position): int => (int) $position->planSetId, $overpayments))];
        }

        foreach ($this->paymentStageApprovals($measurement) as $approval) {
            $makers[] = self::maker($approval['user_id'], 'approved_payment_stage', 'aprovou a etapa Pagamento com a exceção');
            $makers = [...$makers, ...self::delegatorMaker($approval['delegator_user_id'], 'delegated_payment_stage_approval', 'delegou a aprovação da etapa Pagamento com a exceção')];
        }

        return $makers;
    }

    /**
     * Os pagamentos desta medição acima do saldo a pagar -- a exceção
     * financeira --, cujo aceite pede um Finalizador independente. O
     * enquadramento diz: a referência dele é a conta do registro.
     *
     * @return list<int>
     */
    public function exceptionalPayments(Measurement $measurement): array
    {
        return MeasurementPayment::query()
            ->where('measurement_id', $measurement->getKey())
            ->orderBy('id')
            ->get(['id', 'financial_assessment'])
            ->filter(fn (MeasurementPayment $payment): bool => (bool) ($payment->financial_assessment['requires_acceptance'] ?? false)
                && (($payment->financial_assessment['reference']['status'] ?? null) === 'over'
                    || bccomp((string) ($payment->financial_assessment['cumulative_excess_amount'] ?? '0'), '0', 2) > 0))
            ->map(fn (MeasurementPayment $payment): int => (int) $payment->id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MeasurementSegregationException
     */
    public function assertFinancialAcceptanceIsIndependent(Measurement $measurement, User $checker, ResponsibilityAuthorization $authorization): array
    {
        return $this->assertIndependent(
            self::CONTROL_FINANCIAL_EXCEPTION,
            self::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE,
            $measurement,
            $checker,
            $authorization,
            $this->financialExceptionMakers($measurement),
        );
    }

    // ── Sem desembolso ──────────────────────────────────────────────────

    /**
     * Quem decidiu concluir a etapa Pagamento sem desembolso: o revisor da
     * aprovação vigente da etapa e o autor do registro protegido da decisão.
     *
     * @param  array{activity_id: int, decided_by: int|null}  $decision  {@see MeasurementWorkflow::noDisbursementDecision()}
     * @return list<array{user_id: int|null, act: string, label: string}>
     */
    public function noDisbursementMakers(array $decision): array
    {
        $activity = Activity::query()->whereKey($decision['activity_id'])->first(['id', 'causer_id', 'properties']);

        return [
            self::maker($decision['decided_by'] ?? null, 'decided_no_disbursement', 'decidiu concluir a etapa Pagamento sem desembolso'),
            self::maker($activity?->causer_id, 'decided_no_disbursement', 'decidiu concluir a etapa Pagamento sem desembolso'),
            ...self::delegatorMaker($activity?->properties['delegator_user_id'] ?? null, 'delegated_no_disbursement_decision', 'delegou a decisão de concluir a etapa Pagamento sem desembolso'),
        ];
    }

    /**
     * @param  array{activity_id: int, decided_by: int|null}  $decision
     * @return array<string, mixed>
     *
     * @throws MeasurementSegregationException
     */
    public function assertNoDisbursementAcknowledgmentIsIndependent(Measurement $measurement, array $decision, User $checker, ResponsibilityAuthorization $authorization): array
    {
        return $this->assertIndependent(
            self::CONTROL_NO_DISBURSEMENT,
            self::NO_DISBURSEMENT_SELF_ACKNOWLEDGMENT,
            $measurement,
            $checker,
            $authorization,
            $this->noDisbursementMakers($decision),
        );
    }

    /**
     * Por que este usuário não pode finalizar esta medição agora -- as
     * exceções financeiras e a decisão sem desembolso que a Finalização teria
     * de aceitar --, ou `null`. É o que a tela usa para desabilitar a ação com
     * o motivo; a Finalização confere de novo, sob o lock.
     */
    public function finalizationConflict(Measurement $measurement, User $checker): ?string
    {
        $authorization = $this->authorization->resolveAuthorization($checker, $measurement, MeasurementResponsibility::Finalizer);
        $decision = $this->standingNoDisbursementDecision($measurement);

        if ($decision !== null) {
            $conflict = $this->conflictMessage(self::NO_DISBURSEMENT_SELF_ACKNOWLEDGMENT, $checker, $authorization, $this->noDisbursementMakers($decision));

            if ($conflict !== null) {
                return $conflict;
            }
        }

        return $this->conflictMessage(self::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE, $checker, $authorization, $this->financialExceptionMakers($measurement));
    }

    /**
     * Quem fez o que a Finalização desta medição confere -- a exceção
     * financeira e a decisão sem desembolso --, como a pessoa lê: "Fulano
     * registrou o pagamento #12". Vazio quando a Finalização não aceita nada
     * que peça conferência independente.
     *
     * @return list<string>
     */
    public function describeFinalizationMakers(Measurement $measurement): array
    {
        $decision = $this->standingNoDisbursementDecision($measurement);
        $identified = self::uniqueMakers([
            ...($decision === null ? [] : $this->noDisbursementMakers($decision)),
            ...$this->financialExceptionMakers($measurement),
        ]);

        if ($identified === []) {
            return [];
        }

        $names = User::query()->whereKey(self::identifiedIds($identified))->pluck('name', 'id');

        return collect($identified)
            ->groupBy('user_id')
            ->map(fn ($acts, $userId): string => sprintf('%s %s', $names[(int) $userId] ?? 'Usuário #'.$userId, self::sentence($acts->values()->all())))
            ->values()
            ->all();
    }

    /**
     * A decisão sem desembolso de que a Finalização desta medição daria
     * ciência, ou `null`.
     *
     * @return array{activity_id: int, decided_by: int|null}|null
     */
    private function standingNoDisbursementDecision(Measurement $measurement): ?array
    {
        $workflow = app(MeasurementWorkflow::class);

        return $workflow->requiresNoDisbursementAcknowledgment($measurement)
            ? $workflow->noDisbursementDecision($measurement)
            : null;
    }

    // ── Revisão na Compliance ───────────────────────────────────────────

    /**
     * Quem criou e quem enviou a revisão, das colunas da revisão e da trilha
     * protegida (a coluna `uploaded_by` é a de quem enviou, mas não é a única
     * fonte).
     *
     * @return list<array{user_id: int|null, act: string, label: string}>
     */
    public function revisionMakers(Measurement $revision): array
    {
        $row = Measurement::query()->whereKey($revision->getKey())->first(['id', 'revision_created_by', 'uploaded_by']);
        $makers = [
            self::maker($row?->revision_created_by, 'created_revision', 'criou a revisão'),
            self::maker($row?->uploaded_by, 'submitted_revision', 'enviou a revisão'),
        ];

        foreach (Activity::query()
            ->where('log_name', 'measurement_workflow')
            ->whereIn('description', ['measurement_revision_created', 'measurement_revision_submitted'])
            ->where('subject_type', $revision->getMorphClass())
            ->where('subject_id', $revision->getKey())
            ->orderBy('id')
            ->get(['id', 'description', 'causer_id']) as $activity) {
            $makers[] = $activity->description === 'measurement_revision_created'
                ? self::maker($activity->causer_id, 'created_revision', 'criou a revisão')
                : self::maker($activity->causer_id, 'submitted_revision', 'enviou a revisão');
        }

        // Quem alterou o conteúdo -- o arquivo de um empreendimento, os dados
        // da medição -- também fez a revisão que a Compliance confere.
        $identified = self::identifiedIds($makers);

        foreach ($this->revisionContentEditorIds($revision) as $editorId) {
            if (! in_array($editorId, $identified, true)) {
                $makers[] = self::maker($editorId, 'edited_revision', 'alterou o conteúdo da revisão');
            }
        }

        return $makers;
    }

    /**
     * Quem alterou o conteúdo da revisão: o arquivo de um empreendimento
     * (trilha `measurement_assets`) ou os dados da medição (trilha
     * `measurements`: observações, competência, arquivo). Situação, etapa,
     * envio e análise são do fluxo e não contam. Alteração sem usuário, de
     * rotina do sistema, não identifica ninguém.
     *
     * @return list<int>
     */
    public function revisionContentEditorIds(Measurement $revision): array
    {
        $assetEditors = Activity::query()
            ->where('log_name', 'measurement_assets')
            ->whereIn('description', ['measurement_asset_replaced', 'measurement_asset_removed'])
            ->where('properties->measurement_id', $revision->getKey())
            ->whereNotNull('causer_id')
            ->pluck('causer_id')
            ->all();
        $dataEditors = Activity::query()
            ->where('log_name', 'measurements')
            ->where('event', 'updated')
            ->where('subject_type', $revision->getMorphClass())
            ->where('subject_id', $revision->getKey())
            ->whereNotNull('causer_id')
            ->get(['id', 'causer_id', 'attribute_changes'])
            ->filter(function (Activity $activity): bool {
                $changes = $activity->attribute_changes;
                $attributes = $changes instanceof Collection ? $changes->get('attributes', []) : ($changes['attributes'] ?? []);

                return is_array($attributes) && array_intersect(array_keys($attributes), self::REVISION_CONTENT_FIELDS) !== [];
            })
            ->pluck('causer_id')
            ->all();

        return array_values(array_unique(array_map('intval', [...$assetEditors, ...$dataEditors])));
    }

    /**
     * Por que este usuário não pode aprovar a revisão na Compliance, ou
     * `null` -- só para a etapa Compliance de uma revisão. A recusa (que a
     * devolve à Gestão) continua livre.
     */
    public function revisionComplianceConflict(Measurement $measurement, User $checker, ?ResponsibilityAuthorization $authorization = null): ?string
    {
        if (! $measurement->isRevision()) {
            return null;
        }

        $authorization ??= $this->authorization->resolveAuthorization($checker, $measurement, MeasurementResponsibility::ComplianceReviewer);

        return $this->conflictMessage(self::REVISION_SELF_APPROVAL, $checker, $authorization, $this->revisionMakers($measurement));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MeasurementSegregationException
     */
    public function assertRevisionComplianceIsIndependent(Measurement $revision, User $checker, ResponsibilityAuthorization $authorization): array
    {
        return $this->assertIndependent(self::CONTROL_REVISION, self::REVISION_SELF_APPROVAL, $revision, $checker, $authorization, $this->revisionMakers($revision));
    }

    /**
     * Existe hoje alguém que possa aprovar a revisão na Compliance sem ser
     * quem a criou ou a envia? O responsável direto de Compliance da operação,
     * ou um administrador do fluxo pelo override -- sempre ativo, aprovado e
     * com a permissão. Delegado não entra na conta: só delega quem tem a
     * atribuição direta, e o delegado de um autor não é independente.
     *
     * @param  list<int>  $makerIds
     */
    public function independentComplianceApproverExists(Operation $operation, array $makerIds): bool
    {
        $permission = MeasurementResponsibility::ComplianceReviewer->permission();
        $directId = $operation->responsibleUserIdFor(MeasurementResponsibility::ComplianceReviewer);

        if ($directId !== null && ! in_array($directId, $makerIds, true)) {
            $direct = User::query()->operational()->find($directId);

            if ($direct instanceof User && $direct->can($permission)) {
                return true;
            }
        }

        // O administrador que tem delegação de Compliance de um dos autores age
        // como delegado dele (a delegação vem antes do override) e seria
        // recusado: não conta.
        $delegations = app(ResponsibilityDelegationService::class);

        return User::query()
            ->operational()
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['admin', 'super-admin']))
            ->whereKeyNot($makerIds)
            ->get()
            ->contains(function (User $administrator) use ($permission, $operation, $delegations, $makerIds): bool {
                if (! $administrator->can($permission)) {
                    return false;
                }

                $delegatorId = $delegations->activeDelegationFor($administrator, $operation, MeasurementResponsibility::ComplianceReviewer)?->delegator_user_id;

                return $delegatorId === null || ! in_array((int) $delegatorId, $makerIds, true);
            });
    }

    // ── Núcleo ──────────────────────────────────────────────────────────

    /**
     * @param  list<array{user_id: int|null, act: string, label: string}>  $makers
     * @return array<string, mixed>
     *
     * @throws MeasurementSegregationException
     */
    private function assertIndependent(string $control, string $refusal, Measurement $measurement, User $checker, ResponsibilityAuthorization $authorization, array $makers): array
    {
        $message = $this->conflictMessage($refusal, $checker, $authorization, $makers);

        if ($message !== null) {
            throw new MeasurementSegregationException($message, [
                'control' => $control,
                'measurement_id' => $measurement->getKey(),
                'checker_user_id' => (int) $checker->getKey(),
                'maker_user_ids' => self::identifiedIds($makers),
            ]);
        }

        return [
            'control' => $control,
            'makers' => array_values(array_map(
                fn (array $maker): array => ['user_id' => $maker['user_id'], 'act' => $maker['act']],
                self::uniqueMakers($makers),
            )),
            'unidentified_maker_acts' => self::unidentifiedActs($makers),
            'checker_user_id' => (int) $checker->getKey(),
            'checker_authorization_source' => $authorization->source->value,
            'checker_delegation_id' => $authorization->delegation?->getKey(),
            'checker_delegator_user_id' => $authorization->delegation?->delegator_user_id === null ? null : (int) $authorization->delegation->delegator_user_id,
            'independent' => true,
        ];
    }

    /**
     * A recusa, como a pessoa lê, ou `null` quando quem confere é
     * independente dos autores.
     *
     * @param  list<array{user_id: int|null, act: string, label: string}>  $makers
     */
    private function conflictMessage(string $refusal, User $checker, ResponsibilityAuthorization $authorization, array $makers): ?string
    {
        $checkerId = (int) $checker->getKey();
        $own = array_values(array_filter($makers, fn (array $maker): bool => $maker['user_id'] === $checkerId));

        if ($own !== []) {
            return sprintf('%s Você %s.', $refusal, self::sentence($own));
        }

        $delegatorId = $authorization->isDelegated() && $authorization->delegation?->delegator_user_id !== null
            ? (int) $authorization->delegation->delegator_user_id
            : null;
        $delegators = $delegatorId === null
            ? []
            : array_values(array_filter($makers, fn (array $maker): bool => $maker['user_id'] === $delegatorId));

        if ($delegators === []) {
            return null;
        }

        $name = User::query()->whereKey($delegatorId)->value('name') ?? 'o delegante';

        return sprintf(
            '%s Você age por delegação de %s, que %s: a delegação muda quem pode agir, não quem fez a ação, e a conferência precisa ser de outro responsável.',
            $refusal,
            $name,
            self::sentence($delegators),
        );
    }

    /**
     * A aprovação da etapa Pagamento que vale agora: o revisor gravado na
     * análise da etapa e o autor do registro protegido da aprovação, com o
     * delegante dele quando aprovou por delegação.
     *
     * @return list<array{user_id: int|null, delegator_user_id: int|null}>
     */
    private function paymentStageApprovals(Measurement $measurement): array
    {
        $reviewerId = MeasurementReview::query()
            ->where('measurement_id', $measurement->getKey())
            ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
            ->where('status', 'approved')
            ->value('reviewer_user_id');
        $activity = Activity::query()
            ->where('log_name', 'measurement_workflow')
            ->where('description', 'measurement_stage_approved')
            ->where('subject_type', $measurement->getMorphClass())
            ->where('subject_id', $measurement->getKey())
            ->latest('id')
            ->get(['id', 'causer_id', 'properties'])
            ->first(fn (Activity $activity): bool => (int) ($activity->properties['stage'] ?? 0) === MeasurementWorkflow::STAGE_PAYMENT);

        return [
            ['user_id' => $reviewerId === null ? null : (int) $reviewerId, 'delegator_user_id' => null],
            [
                'user_id' => $activity?->causer_id === null ? null : (int) $activity->causer_id,
                'delegator_user_id' => filled($activity?->properties['delegator_user_id'] ?? null) ? (int) $activity->properties['delegator_user_id'] : null,
            ],
        ];
    }

    /**
     * O delegante de quem registrou cada pagamento por delegação, lido do
     * registro protegido do pagamento.
     *
     * @return array<int, int>
     */
    private function paymentRegistrationDelegators(Measurement $measurement): array
    {
        $delegators = [];

        foreach (Activity::query()
            ->where('log_name', 'measurement_workflow')
            ->where('description', 'measurement_payment_registered')
            ->where('subject_type', $measurement->getMorphClass())
            ->where('subject_id', $measurement->getKey())
            ->orderBy('id')
            ->get(['id', 'properties']) as $activity) {
            $delegatorId = $activity->properties['delegator_user_id'] ?? null;

            if (! filled($delegatorId)) {
                continue;
            }

            foreach ((array) ($activity->properties['payment_ids'] ?? []) as $paymentId) {
                $delegators[(int) $paymentId] = (int) $delegatorId;
            }
        }

        return $delegators;
    }

    /**
     * Cada reavaliação de cada pagamento, lida da trilha protegida: quem
     * reavaliou e, por delegação, de quem.
     *
     * @return array<int, list<array{causer_id: int|null, delegator_user_id: int|null}>>
     */
    private function reassessmentsByPayment(Measurement $measurement): array
    {
        $reassessments = [];

        foreach (Activity::query()
            ->where('log_name', 'measurement_workflow')
            ->where('description', 'measurement_payment_reassessed')
            ->where('subject_type', $measurement->getMorphClass())
            ->where('subject_id', $measurement->getKey())
            ->orderBy('id')
            ->get(['id', 'causer_id', 'properties']) as $activity) {
            $paymentId = (int) ($activity->properties['payment_id'] ?? 0);

            if ($paymentId > 0) {
                $reassessments[$paymentId][] = [
                    'causer_id' => $activity->causer_id === null ? null : (int) $activity->causer_id,
                    'delegator_user_id' => filled($activity->properties['delegator_user_id'] ?? null) ? (int) $activity->properties['delegator_user_id'] : null,
                ];
            }
        }

        return $reassessments;
    }

    /**
     * Quem registrou, nas revisões anteriores da família, os pagamentos dos
     * empreendimentos em que a família pagou a maior -- e o delegante, quando
     * por delegação. Quem causou o valor pago a maior não o reconhece.
     *
     * @param  list<int>  $planSetIds
     * @return list<array{user_id: int|null, act: string, label: string}>
     */
    private function familyPaymentMakers(Measurement $revision, array $planSetIds): array
    {
        $earlier = Measurement::query()
            ->where('revision_family_id', $revision->familyRootId())
            ->where('revision_number', '<', $revision->revisionNumber())
            ->get(['id', 'revision_number', 'revision_family_id']);
        $makers = [];

        foreach ($earlier as $member) {
            $delegators = $this->paymentRegistrationDelegators($member);

            foreach (MeasurementPayment::query()
                ->where('measurement_id', $member->getKey())
                ->whereIn('plan_set_id', $planSetIds)
                ->orderBy('id')
                ->get(['id', 'created_by']) as $payment) {
                $label = sprintf('registrou o pagamento #%d da %s, que forma o valor pago a maior', $payment->id, $member->revisionLabel());
                $makers[] = self::maker($payment->created_by, 'registered_family_payment', $label);
                $makers = [...$makers, ...self::delegatorMaker($delegators[(int) $payment->id] ?? null, 'delegated_family_payment_registration', sprintf('delegou o registro do pagamento #%d da %s, que forma o valor pago a maior', $payment->id, $member->revisionLabel()))];
            }
        }

        return $makers;
    }

    /**
     * @return array{user_id: int|null, act: string, label: string}
     */
    private static function maker(mixed $userId, string $act, string $label): array
    {
        return [
            'user_id' => filled($userId) ? (int) $userId : null,
            'act' => $act,
            'label' => $label,
        ];
    }

    /**
     * O delegante só é autor quando o ato foi por delegação: sem delegação,
     * não há ninguém a identificar.
     *
     * @return list<array{user_id: int|null, act: string, label: string}>
     */
    private static function delegatorMaker(mixed $delegatorId, string $act, string $label): array
    {
        return filled($delegatorId) ? [self::maker($delegatorId, $act, $label)] : [];
    }

    /**
     * Os atos cujo autor nenhuma fonte identifica (usuário excluído ou
     * registro anterior ao controle). Um ato lido em duas fontes -- a coluna
     * e o registro protegido -- é identificado quando qualquer delas o
     * identifica.
     *
     * @param  list<array{user_id: int|null, act: string, label: string}>  $makers
     * @return list<string>
     */
    private static function unidentifiedActs(array $makers): array
    {
        $identified = [];
        $unidentified = [];

        foreach ($makers as $maker) {
            $key = $maker['act'].'|'.$maker['label'];

            if ($maker['user_id'] === null) {
                $unidentified[$key] = $maker['act'];
            } else {
                $identified[$key] = true;
            }
        }

        return array_values(array_unique(array_diff_key($unidentified, $identified)));
    }

    /**
     * @param  list<array{user_id: int|null, act: string, label: string}>  $makers
     * @return list<array{user_id: int|null, act: string, label: string}>
     */
    private static function uniqueMakers(array $makers): array
    {
        $unique = [];

        foreach ($makers as $maker) {
            if ($maker['user_id'] === null) {
                continue;
            }

            $unique[$maker['user_id'].'|'.$maker['act'].'|'.$maker['label']] = $maker;
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{user_id: int|null, act: string, label: string}>  $makers
     * @return list<int>
     */
    private static function identifiedIds(array $makers): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $maker): ?int => $maker['user_id'],
            $makers,
        ), fn (?int $id): bool => $id !== null)));
    }

    /**
     * "registrou o pagamento #12 e aprovou a etapa Pagamento com a exceção".
     *
     * @param  list<array{user_id: int|null, act: string, label: string}>  $makers
     */
    private static function sentence(array $makers): string
    {
        $labels = array_values(array_unique(array_map(fn (array $maker): string => $maker['label'], $makers)));

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' e '.$last;
    }
}
