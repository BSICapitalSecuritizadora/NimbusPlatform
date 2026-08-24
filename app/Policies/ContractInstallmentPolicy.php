<?php

namespace App\Policies;

use App\Models\ContractInstallment;
use App\Models\User;

/**
 * The permission checks of the installments module, expressed as a policy so
 * that the Gate agrees with the resource.
 *
 * The relation manager embedded in the contract authorizes its actions through
 * the Gate rather than through the resource, so without this the schedule would
 * be read-only for everyone but a super-admin. The resource keeps its own
 * `can*` methods -- they are what the table and the pages call -- and both read
 * the same permissions.
 */
class ContractInstallmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contract-installments.view');
    }

    public function view(User $user, ContractInstallment $installment): bool
    {
        return $user->can('contract-installments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contract-installments.create');
    }

    public function update(User $user, ContractInstallment $installment): bool
    {
        return $user->can('contract-installments.update') && ! $installment->trashed();
    }

    public function delete(User $user, ContractInstallment $installment): bool
    {
        return $user->can('contract-installments.delete') && ! $installment->trashed();
    }

    public function restore(User $user, ContractInstallment $installment): bool
    {
        return $user->can('contract-installments.restore') && $installment->trashed();
    }

    /**
     * An installment is financial history: erasing it for good would take the
     * schedule it belonged to with it.
     */
    public function forceDelete(User $user, ContractInstallment $installment): bool
    {
        return false;
    }
}
