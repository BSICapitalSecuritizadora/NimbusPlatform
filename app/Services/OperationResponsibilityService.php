<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class OperationResponsibilityService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertCanChange(User $actor, Operation $operation, array $attributes): void
    {
        $changesResponsibilities = collect(Operation::RESPONSIBILITY_FIELDS)
            ->contains(fn (string $field): bool => array_key_exists($field, $attributes)
                && (int) ($operation->exists ? $operation->getOriginal($field) : null) !== (int) ($attributes[$field] ?? 0));

        if ($changesResponsibilities && ! $actor->can('manageResponsibilities', $operation)) {
            throw new AuthorizationException('Você não possui permissão para alterar os responsáveis desta operação.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertCanAssignOnCreation(User $actor, array $attributes): void
    {
        $assignsResponsibility = collect(Operation::RESPONSIBILITY_FIELDS)
            ->contains(fn (string $field): bool => filled($attributes[$field] ?? null));

        if ($assignsResponsibility && ! $actor->can('manageResponsibilities', Operation::class)) {
            throw new AuthorizationException('Você não possui permissão para definir os responsáveis desta operação.');
        }
    }
}
