<?php

namespace App\Policies;

use App\Models\ResponsibilityDelegation;
use App\Models\User;

class ResponsibilityDelegationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('delegations.view') || $user->can('delegations.manage');
    }

    public function view(User $user, ResponsibilityDelegation $delegation): bool
    {
        if ($user->hasAnyRole(['super-admin', 'admin']) || $user->can('delegations.manage')) {
            return true;
        }

        if (! $user->can('delegations.view')) {
            return false;
        }

        return (int) $user->getKey() === (int) $delegation->delegator_user_id
            || (int) $user->getKey() === (int) $delegation->delegate_user_id;
    }

    public function create(User $user): bool
    {
        return $user->can('delegations.create') || $user->can('delegations.manage') || $user->hasAnyRole(['super-admin', 'admin']);
    }

    public function revoke(User $user, ResponsibilityDelegation $delegation): bool
    {
        if ($user->hasAnyRole(['super-admin', 'admin']) || $user->can('delegations.manage')) {
            return true;
        }

        if (! $user->can('delegations.revoke')) {
            return false;
        }

        return (int) $user->getKey() === (int) $delegation->delegator_user_id;
    }
}
