<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;

class OperationPolicy
{
    public function __construct(private MeasurementAuthorizationService $authorization) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('operations.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Operation $operation): bool
    {
        return $this->authorization->canViewOperation($user, $operation);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('operations.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Operation $operation): bool
    {
        return $user->can('operations.update')
            && $this->authorization->hasDirectOperationalParticipation($user, $operation);
    }

    public function manageResponsibilities(User $user, ?Operation $operation = null): bool
    {
        if ($this->authorization->isWorkflowAdministrator($user)) {
            return true;
        }

        return $user->can('operations.manage-responsibilities')
            && ($operation === null || $this->authorization->hasDirectOperationalParticipation($user, $operation));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Operation $operation): bool
    {
        return $user->can('operations.delete')
            && $this->authorization->hasDirectOperationalParticipation($user, $operation);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Operation $operation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Operation $operation): bool
    {
        return false;
    }
}
