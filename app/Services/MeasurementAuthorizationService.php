<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;

class MeasurementAuthorizationService
{
    public function canViewOperation(User $user, Operation $operation): bool
    {
        return $user->can('operations.view')
            && ($this->isWorkflowAdministrator($user) || $operation->hasParticipant($user));
    }

    public function canViewMeasurement(User $user, Measurement $measurement): bool
    {
        return $user->can('measurements.view')
            && $measurement->operation instanceof Operation
            && ($this->isWorkflowAdministrator($user) || $measurement->operation->hasParticipant($user));
    }

    public function canCreateMeasurement(User $user, Operation $operation): bool
    {
        return $user->can('measurements.create')
            && ! in_array($operation->status, ['canceled', 'completed'], true)
            && ($this->isWorkflowAdministrator($user) || $operation->hasParticipant($user));
    }

    public function canDecideStage(User $user, Measurement $measurement, int $stage): bool
    {
        $permission = $stage === MeasurementWorkflow::STAGE_PAYMENT
            ? 'measurements.pay'
            : 'measurements.review';

        return $user->can($permission)
            && $this->hasStageResponsibility($user, $measurement, $stage);
    }

    public function canPauseStage(User $user, Measurement $measurement, int $stage): bool
    {
        return $this->canDecideStage($user, $measurement, $stage);
    }

    public function canRegisterPayment(User $user, Measurement $measurement): bool
    {
        return $user->can('measurements.pay')
            && $this->hasOperationResponsibility($user, $measurement, 'payment_manager_user_id');
    }

    public function canManageReceipts(User $user, Measurement $measurement): bool
    {
        return $user->can('measurements.receipts')
            && $this->hasOperationResponsibility($user, $measurement, 'payment_receipt_uploader_user_id');
    }

    public function canFinalize(User $user, Measurement $measurement): bool
    {
        return $user->can('measurements.finalize')
            && $this->hasOperationResponsibility($user, $measurement, 'payment_finalizer_user_id');
    }

    public function isWorkflowAdministrator(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']);
    }

    public function responsibilityForStage(int $stage): string
    {
        return match ($stage) {
            1 => 'responsible_user_id',
            2 => 'stage2_reviewer_user_id',
            3 => 'stage3_reviewer_user_id',
            4 => 'payment_manager_user_id',
            5 => 'payment_finalizer_user_id',
            default => 'unknown',
        };
    }

    private function hasStageResponsibility(User $user, Measurement $measurement, int $stage): bool
    {
        if ($this->isWorkflowAdministrator($user)) {
            return true;
        }

        return (int) $measurement->operation?->stageResponsibleId($stage) === (int) $user->getKey();
    }

    private function hasOperationResponsibility(User $user, Measurement $measurement, string $column): bool
    {
        if ($this->isWorkflowAdministrator($user)) {
            return true;
        }

        return (int) $measurement->operation?->getAttribute($column) === (int) $user->getKey();
    }
}
