<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;

class MeasurementAuthorizationService
{
    public function __construct(private ResponsibilityDelegationService $delegations) {}

    public function canViewOperation(User $user, Operation $operation): bool
    {
        return $user->can('operations.view')
            && ($this->isWorkflowAdministrator($user)
                || $operation->hasParticipant($user)
                || $this->delegations->hasAnyActiveDelegatedResponsibility($user, $operation));
    }

    public function canViewMeasurement(User $user, Measurement $measurement): bool
    {
        return $user->can('measurements.view')
            && $measurement->operation instanceof Operation
            && ($this->isWorkflowAdministrator($user)
                || $measurement->operation->hasParticipant($user)
                || $this->delegations->hasAnyActiveDelegatedResponsibility($user, $measurement->operation));
    }

    public function canCreateMeasurement(User $user, Operation $operation): bool
    {
        return $user->can('measurements.create')
            && ! in_array($operation->status, ['canceled', 'completed'], true)
            && $this->hasDirectOperationalParticipation($user, $operation);
    }

    public function hasDirectOperationalParticipation(User $user, Operation $operation): bool
    {
        return $this->isWorkflowAdministrator($user) || $operation->hasParticipant($user);
    }

    public function canDecideStage(User $user, Measurement $measurement, int $stage): bool
    {
        $responsibility = MeasurementResponsibility::primaryForStage($stage);

        return $responsibility instanceof MeasurementResponsibility
            && $this->hasResponsibility($user, $measurement, $responsibility);
    }

    public function canPauseStage(User $user, Measurement $measurement, int $stage): bool
    {
        return $this->canDecideStage($user, $measurement, $stage);
    }

    public function canRegisterPayment(User $user, Measurement $measurement): bool
    {
        return $this->hasResponsibility($user, $measurement, MeasurementResponsibility::PaymentManager);
    }

    public function canManageReceipts(User $user, Measurement $measurement): bool
    {
        return $this->hasResponsibility($user, $measurement, MeasurementResponsibility::ReceiptUploader);
    }

    public function canFinalize(User $user, Measurement $measurement): bool
    {
        return $this->hasResponsibility($user, $measurement, MeasurementResponsibility::Finalizer);
    }

    public function isWorkflowAdministrator(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']);
    }

    public function responsibilityForStage(int $stage): string
    {
        return MeasurementResponsibility::primaryForStage($stage)?->operationColumn() ?? 'unknown';
    }

    public function activeDelegationFor(
        User $user,
        Measurement $measurement,
        MeasurementResponsibility|int|string $responsibility,
    ): ?ResponsibilityDelegation {
        if ($this->isWorkflowAdministrator($user) || ! $measurement->operation instanceof Operation) {
            return null;
        }

        $resolved = $this->resolveResponsibility($responsibility);

        if (! $resolved instanceof MeasurementResponsibility
            || $measurement->operation->hasDirectResponsibility($user, $resolved)) {
            return null;
        }

        return $this->delegations->activeDelegationFor($user, $measurement->operation, $resolved);
    }

    private function hasResponsibility(
        User $user,
        Measurement $measurement,
        MeasurementResponsibility $responsibility,
    ): bool {
        if (! $user->can($responsibility->permission())) {
            return false;
        }

        if ($this->isWorkflowAdministrator($user)) {
            return true;
        }

        $operation = $measurement->operation;

        if (! $operation instanceof Operation) {
            return false;
        }

        return $operation->hasDirectResponsibility($user, $responsibility)
            || $this->delegations->activeDelegationFor($user, $operation, $responsibility) instanceof ResponsibilityDelegation;
    }

    private function resolveResponsibility(
        MeasurementResponsibility|int|string $responsibility,
    ): ?MeasurementResponsibility {
        if ($responsibility instanceof MeasurementResponsibility) {
            return $responsibility;
        }

        if (is_int($responsibility)) {
            return MeasurementResponsibility::primaryForStage($responsibility);
        }

        return MeasurementResponsibility::tryFrom($responsibility)
            ?? MeasurementResponsibility::fromOperationColumn($responsibility);
    }
}
