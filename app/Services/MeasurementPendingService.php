<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Support\Delegations\ResponsibilityAuthorization;
use App\Support\Delegations\ResponsibilityAuthorizationCapture;

class MeasurementPendingService
{
    public function __construct(
        private MeasurementWorkflow $workflow,
        private MeasurementSlaService $sla,
        private MeasurementAuthorizationService $authorization,
    ) {}

    /**
     * As pendências de medição do ator. A decisão que a segregação de funções
     * recusaria a ele -- conferir o comprovante que enviou, aceitar a exceção
     * que fez, aprovar na Compliance a revisão que criou -- não conta como
     * pendência dele: aparece bloqueada (`blocked`), com o motivo e, sem
     * ninguém independente para decidir, o pedido de outro responsável
     * (`needs_independent_reviewer`). A pendência continua a mesma; só não é
     * apresentada como ação de quem não pode fazê-la.
     *
     * @return array{items: list<array<string, mixed>>, count: int, overdue_count: int, delegated_count: int, blocked_count: int}
     */
    public function summaryFor(User $user, int $previewLimit = 3): array
    {
        if (! $user->can('measurements.view')) {
            return ['items' => [], 'count' => 0, 'overdue_count' => 0, 'delegated_count' => 0, 'blocked_count' => 0];
        }

        $items = [];
        $count = 0;
        $overdueCount = 0;
        $delegatedCount = 0;
        $blockedCount = 0;

        // O papel do ator não muda no meio da varredura, e `hasAnyRole()` só
        // consultaria o banco na primeira chamada de qualquer forma; resolver
        // aqui deixa explícito que a pergunta é sobre o ator, não sobre a linha.
        $isAdministrator = $this->authorization->isWorkflowAdministrator($user);

        Measurement::query()
            ->select([
                'id',
                'operation_id',
                'reference_month',
                'status',
                'current_stage',
                'workflow_revision',
                'created_at',
                'revision_family_id',
                'revision_number',
                'revision_status',
            ])
            ->visibleTo($user)
            ->withPendingWork()
            ->with([
                'reviewingSuccessor:id,previous_revision_id,revision_number,revision_status',
                'operation:id,code,title,assigned_user_id,responsible_user_id,stage2_reviewer_user_id,stage3_reviewer_user_id,payment_manager_user_id,payment_receipt_uploader_user_id,payment_finalizer_user_id',
                'reviews:id,measurement_id,stage,status,paused_at,created_at',
                'pauses:id,measurement_id,stage,paused_at,resumed_at',
                'payments.currentReceiptEvidence',
            ])
            ->orderBy('measurements.id')
            // O alias precisa ser explícito: sem ele o Laravel usa a própria
            // coluna qualificada como nome do atributo e procura `measurements.id`
            // no model, que só tem `id`. A falha só aparece quando um chunk vem
            // cheio -- ou seja, a partir de 100 medições visíveis.
            ->lazyById(100, column: 'measurements.id', alias: 'id')
            ->each(function (Measurement $measurement) use (
                $user,
                $isAdministrator,
                $previewLimit,
                &$items,
                &$count,
                &$overdueCount,
                &$delegatedCount,
                &$blockedCount,
            ): void {
                foreach ($this->actionsFor($measurement, $user) as $action) {
                    $evaluation = $this->sla->evaluate($measurement, responsibility: $action['responsibility']);
                    $delegation = $this->delegationContextFor($action['authorization'], $isAdministrator);

                    if ($action['blocked_reason'] !== null) {
                        $blockedCount++;

                        if (count($items) < $previewLimit) {
                            $items[] = $this->item($measurement, $action, $evaluation, $delegation);
                        }

                        continue;
                    }

                    $count++;
                    $overdueCount += $evaluation['status'] === MeasurementSlaService::STATUS_OVERDUE ? 1 : 0;
                    $delegatedCount += $delegation instanceof ResponsibilityDelegation ? 1 : 0;

                    if (count($items) < $previewLimit) {
                        $items[] = $this->item($measurement, $action, $evaluation, $delegation);
                    }
                }
            });

        return [
            'items' => $items,
            'count' => $count,
            'overdue_count' => $overdueCount,
            'delegated_count' => $delegatedCount,
            'blocked_count' => $blockedCount,
        ];
    }

    /** @return list<array{label: string, responsibility: MeasurementResponsibility, authorization: ResponsibilityAuthorization, blocked_reason: string|null, needs_independent_reviewer: bool}> */
    private function actionsFor(Measurement $measurement, User $user): array
    {
        // Comprovantes da Finalização aberta e as correções depois do
        // encerramento -- finalizada, ou substituída por revisão depois de paga.
        if (! in_array($measurement->status, ['awaiting_receipt', 'approved', 'finalized', 'superseded'], true)) {
            $action = $this->actionFor($measurement, $user);

            if ($action === null) {
                return [];
            }

            // A revisão na Compliance: quem a criou, enviou ou alterou não a
            // aprova.
            if ($action['responsibility'] === MeasurementResponsibility::ComplianceReviewer
                && $measurement->status !== 'paused' && $measurement->isRevision()) {
                $reason = app(MeasurementSegregationService::class)->revisionComplianceConflict($measurement, $user, $action['authorization']);

                if ($reason !== null) {
                    return [$this->blocked($action, $reason, false)];
                }
            }

            return [$action];
        }

        $actions = [];
        $receipts = app(MeasurementReceiptEvidenceService::class);

        foreach ($receipts->pendingResponsibilities($measurement) as $responsibility) {
            $authorization = $this->authorization->resolveAuthorization($user, $measurement, $responsibility);

            if ($authorization->authorizes()) {
                $action = [
                    'label' => $receipts->pendingActionLabel($measurement, $responsibility),
                    'responsibility' => $responsibility,
                    'authorization' => $authorization,
                    'blocked_reason' => null,
                    'needs_independent_reviewer' => false,
                ];

                // A Finalização aberta: quem só pode conferir o próprio
                // comprovante, ou aceitar a própria exceção, não é quem decide.
                if ($responsibility === MeasurementResponsibility::Finalizer
                    && in_array($measurement->status, ['awaiting_receipt', 'approved'], true)) {
                    $segregation = app(MeasurementSegregationService::class);
                    $reason = $segregation->finalizerDecisionBlockReason($measurement, $user);

                    if ($reason !== null) {
                        $action = $this->blocked($action, $reason, ! $segregation->independentFinalizerExists($measurement));
                    }
                }

                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * @param  array{label: string, responsibility: MeasurementResponsibility, authorization: ResponsibilityAuthorization}  $action
     * @return array{label: string, responsibility: MeasurementResponsibility, authorization: ResponsibilityAuthorization, blocked_reason: string, needs_independent_reviewer: bool}
     */
    private function blocked(array $action, string $reason, bool $needsIndependentReviewer): array
    {
        return [
            ...$action,
            'label' => $needsIndependentReviewer ? 'Sem conferente independente — designe outro responsável' : 'Aguardando conferente independente',
            'blocked_reason' => $needsIndependentReviewer ? $reason.' '.MeasurementSegregationService::NO_INDEPENDENT_FINALIZER : $reason,
            'needs_independent_reviewer' => $needsIndependentReviewer,
        ];
    }

    /**
     * A ação pendente do ator sobre esta medição -- e, junto, a autorização que
     * a permitiu.
     *
     * A autorização sai do mesmo `capture` que a decisão preencheu, e não de uma
     * segunda resolução: era ela a origem das duas consultas de delegação por
     * pendência delegada, e de uma divergência possível entre o que autorizou a
     * ação e o que o payload descreve.
     *
     * @return array{label: string, responsibility: MeasurementResponsibility, authorization: ResponsibilityAuthorization, blocked_reason: string|null, needs_independent_reviewer: bool}|null
     */
    private function actionFor(Measurement $measurement, User $user): ?array
    {
        $capture = new ResponsibilityAuthorizationCapture;

        if ($measurement->status === 'paused') {
            $stage = $this->workflow->unifiedStage($measurement);
            $responsibility = MeasurementResponsibility::primaryForStage($stage);

            return $responsibility instanceof MeasurementResponsibility
                && $this->workflow->canResume($measurement, $user, $capture)
                    ? $this->action('Retomar etapa', $responsibility, $capture)
                    : null;
        }

        if (in_array($measurement->status, ['pending', 'in_review'], true)) {
            $stage = $this->workflow->unifiedStage($measurement);
            $responsibility = MeasurementResponsibility::primaryForStage($stage);

            return $responsibility instanceof MeasurementResponsibility
                && $this->workflow->canApprove($measurement, $user, $capture)
                    ? $this->action($responsibility->label(), $responsibility, $capture)
                    : null;
        }

        if ($measurement->status === 'awaiting_payment'
            && $this->workflow->canRegisterPayment($measurement, $user, $capture)) {
            // A original sem pagamento em que nada é devido não espera registro:
            // espera a decisão de concluir a etapa sem desembolso.
            return $this->action(
                ! $measurement->isRevision() && $measurement->payments->isEmpty()
                    && app(MeasurementPaymentFinancialService::class)->amountDue($measurement)->isNothingDue()
                    ? 'Concluir etapa sem desembolso'
                    : 'Registrar e aprovar pagamento',
                MeasurementResponsibility::PaymentManager,
                $capture,
            );
        }

        return null;
    }

    /**
     * @return array{label: string, responsibility: MeasurementResponsibility, authorization: ResponsibilityAuthorization, blocked_reason: null, needs_independent_reviewer: false}
     */
    private function action(
        string $label,
        MeasurementResponsibility $responsibility,
        ResponsibilityAuthorizationCapture $capture,
    ): array {
        // A decisão só é positiva depois de passar pela autorização, então o
        // slot está preenchido aqui; o fallback existe para o tipo, não para um
        // caminho alcançável.
        return [
            'label' => $label,
            'responsibility' => $responsibility,
            'authorization' => $capture->captured() ?? ResponsibilityAuthorization::none(),
            'blocked_reason' => null,
            'needs_independent_reviewer' => false,
        ];
    }

    /**
     * O contexto de delegação exibido na pendência.
     *
     * A resolução da P2.3 classifica como DELEGATED o administrador que também
     * é delegado efetivo, mas My Pendings nunca atribuiu contexto de delegação a
     * administrador -- para ele o bypass é o que revela a pendência. Esta rodada
     * é de desempenho: a distinção fica preservada como está.
     */
    private function delegationContextFor(
        ResponsibilityAuthorization $authorization,
        bool $isAdministrator,
    ): ?ResponsibilityDelegation {
        return $isAdministrator ? null : $authorization->delegation;
    }

    /** @return array<string, mixed> */
    private function item(
        Measurement $measurement,
        array $action,
        array $evaluation,
        ?ResponsibilityDelegation $delegation,
    ): array {
        // Só as linhas que entram na prévia precisam do nome do delegante, e são
        // no máximo `$previewLimit`. Carregá-lo na resolução da delegação
        // custaria uma consulta por medição pendente.
        $delegation?->loadMissing('delegator:id,name');

        $priority = match ($evaluation['status']) {
            MeasurementSlaService::STATUS_OVERDUE => 'high',
            MeasurementSlaService::STATUS_APPROACHING => 'medium',
            default => 'normal',
        };

        return [
            'measurement_id' => $measurement->getKey(),
            'operation_code' => $measurement->operation?->code,
            'operation_title' => $measurement->operation?->title,
            'reference_month' => $measurement->reference_month?->format('m/Y'),
            'revision_label' => $measurement->isRevision() ? 'Revisão '.$measurement->revisionLabel() : null,
            'stage' => $evaluation['stage'],
            'stage_label' => MeasurementWorkflow::STAGE_LABELS[$evaluation['stage']] ?? 'Etapa operacional',
            'action_label' => $action['label'],
            'responsibility' => $action['responsibility']->value,
            'responsibility_label' => $action['responsibility']->label(),
            'url' => MeasurementResource::getUrl('view', ['record' => $measurement]),
            'sla_status' => $evaluation['status'],
            'deadline_at' => $evaluation['deadline_at'],
            'elapsed_business_days' => $evaluation['elapsed_business_days'],
            'remaining_business_days' => $evaluation['remaining_business_days'],
            'priority' => $priority,
            'delegated' => $delegation instanceof ResponsibilityDelegation,
            'delegation_id' => $delegation?->getKey(),
            'delegator_name' => $delegation?->delegator?->name,
            'delegation_ends_at' => $delegation?->ends_at,
            'blocked' => $action['blocked_reason'] !== null,
            'blocked_reason' => $action['blocked_reason'],
            'needs_independent_reviewer' => $action['needs_independent_reviewer'],
        ];
    }
}
