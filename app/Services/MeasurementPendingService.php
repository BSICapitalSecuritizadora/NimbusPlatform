<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\ResponsibilityDelegation;
use App\Models\User;

class MeasurementPendingService
{
    public function __construct(
        private MeasurementWorkflow $workflow,
        private MeasurementSlaService $sla,
        private MeasurementAuthorizationService $authorization,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, count: int, overdue_count: int, delegated_count: int}
     */
    public function summaryFor(User $user, int $previewLimit = 3): array
    {
        if (! $user->can('measurements.view')) {
            return ['items' => [], 'count' => 0, 'overdue_count' => 0, 'delegated_count' => 0];
        }

        $items = [];
        $count = 0;
        $overdueCount = 0;
        $delegatedCount = 0;

        Measurement::query()
            ->select([
                'id',
                'operation_id',
                'reference_month',
                'status',
                'current_stage',
                'workflow_revision',
                'created_at',
            ])
            ->visibleTo($user)
            ->whereIn('status', [
                'pending',
                'in_review',
                'awaiting_payment',
                'awaiting_receipt',
                'approved',
                'paused',
            ])
            ->with([
                'operation:id,code,title,assigned_user_id,responsible_user_id,stage2_reviewer_user_id,stage3_reviewer_user_id,payment_manager_user_id,payment_receipt_uploader_user_id,payment_finalizer_user_id',
                'reviews:id,measurement_id,stage,status,paused_at,created_at',
                'pauses:id,measurement_id,stage,paused_at,resumed_at',
            ])
            ->orderBy('measurements.id')
            // O alias precisa ser explícito: sem ele o Laravel usa a própria
            // coluna qualificada como nome do atributo e procura `measurements.id`
            // no model, que só tem `id`. A falha só aparece quando um chunk vem
            // cheio -- ou seja, a partir de 100 medições visíveis.
            ->lazyById(100, column: 'measurements.id', alias: 'id')
            ->each(function (Measurement $measurement) use (
                $user,
                $previewLimit,
                &$items,
                &$count,
                &$overdueCount,
                &$delegatedCount,
            ): void {
                $action = $this->actionFor($measurement, $user);

                if ($action === null) {
                    return;
                }

                $evaluation = $this->sla->evaluate($measurement);
                $delegation = $this->authorization->activeDelegationFor(
                    $user,
                    $measurement,
                    $action['responsibility'],
                );

                $count++;
                $overdueCount += $evaluation['status'] === MeasurementSlaService::STATUS_OVERDUE ? 1 : 0;
                $delegatedCount += $delegation instanceof ResponsibilityDelegation ? 1 : 0;

                if (count($items) >= $previewLimit) {
                    return;
                }

                $items[] = $this->item($measurement, $action, $evaluation, $delegation);
            });

        return [
            'items' => $items,
            'count' => $count,
            'overdue_count' => $overdueCount,
            'delegated_count' => $delegatedCount,
        ];
    }

    /** @return array{label: string, responsibility: MeasurementResponsibility}|null */
    private function actionFor(Measurement $measurement, User $user): ?array
    {
        if ($measurement->status === 'paused') {
            $stage = $this->workflow->unifiedStage($measurement);
            $responsibility = MeasurementResponsibility::primaryForStage($stage);

            return $responsibility instanceof MeasurementResponsibility
                && $this->workflow->canResume($measurement, $user)
                    ? ['label' => 'Retomar etapa', 'responsibility' => $responsibility]
                    : null;
        }

        if (in_array($measurement->status, ['pending', 'in_review'], true)) {
            $stage = $this->workflow->unifiedStage($measurement);
            $responsibility = MeasurementResponsibility::primaryForStage($stage);

            return $responsibility instanceof MeasurementResponsibility
                && $this->workflow->canApprove($measurement, $user)
                    ? ['label' => $responsibility->label(), 'responsibility' => $responsibility]
                    : null;
        }

        if ($measurement->status === 'awaiting_payment' && $this->workflow->canRegisterPayment($measurement, $user)) {
            return ['label' => 'Registrar e aprovar pagamento', 'responsibility' => MeasurementResponsibility::PaymentManager];
        }

        if ($measurement->status === 'awaiting_receipt' && $this->workflow->canManageReceipts($measurement, $user)) {
            return ['label' => 'Enviar comprovante', 'responsibility' => MeasurementResponsibility::ReceiptUploader];
        }

        if ($measurement->status === 'approved' && $this->workflow->canFinalize($measurement, $user)) {
            return ['label' => 'Finalizar medição', 'responsibility' => MeasurementResponsibility::Finalizer];
        }

        return null;
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
            'stage' => $evaluation['stage'],
            'stage_label' => MeasurementWorkflow::STAGE_LABELS[$evaluation['stage']] ?? 'Etapa operacional',
            'action_label' => $action['label'],
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
        ];
    }
}
