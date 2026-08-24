<?php

namespace App\Policies;

use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;

class MeasurementPolicy
{
    public function __construct(private MeasurementAuthorizationService $authorization) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('measurements.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Measurement $measurement): bool
    {
        return $this->authorization->canViewMeasurement($user, $measurement);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('measurements.create');
    }

    public function createForOperation(User $user, Operation $operation): bool
    {
        return $this->authorization->canCreateMeasurement($user, $operation);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Measurement $measurement): bool
    {
        return $user->can('measurements.update')
            && $this->authorization->canViewMeasurement($user, $measurement)
            && $measurement->status !== 'finalized';
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Measurement $measurement): bool
    {
        return $user->can('measurements.delete')
            && $this->authorization->canViewMeasurement($user, $measurement)
            && $measurement->status !== 'finalized';
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Measurement $measurement): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Measurement $measurement): bool
    {
        return false;
    }
}
