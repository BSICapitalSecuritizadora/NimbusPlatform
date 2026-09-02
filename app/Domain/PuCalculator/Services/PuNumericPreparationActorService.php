<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Enums\AccessPermission;
use App\Models\User;

final class PuNumericPreparationActorService
{
    public const ACTION_ACTOR_REQUIRED = 'actor_required';

    public const ACTION_ACTOR_NOT_FOUND = 'actor_not_found';

    public const ACTION_ACTOR_INACTIVE = 'actor_inactive';

    public const ACTION_ACTOR_UNAPPROVED = 'actor_unapproved';

    public const ACTION_ACTOR_UNAUTHORIZED = 'actor_unauthorized';

    /**
     * @return array{actor:?User,action:string,reason:string}
     */
    public function resolve(
        ?string $identifier,
        AccessPermission $permission,
        bool $lockForUpdate = false,
    ): array {
        if (! filled($identifier)) {
            return [
                'actor' => null,
                'action' => self::ACTION_ACTOR_REQUIRED,
                'reason' => 'Um --actor=<id|email> explícito é obrigatório para write.',
            ];
        }

        $normalizedIdentifier = trim((string) $identifier);
        $actorQuery = User::query();

        if ($lockForUpdate) {
            $actorQuery->lockForUpdate();
        }

        $actor = ctype_digit($normalizedIdentifier)
            ? $actorQuery->whereKey((int) $normalizedIdentifier)->first()
            : $actorQuery->where('email', $normalizedIdentifier)->first();

        if (! $actor instanceof User) {
            return [
                'actor' => null,
                'action' => self::ACTION_ACTOR_NOT_FOUND,
                'reason' => 'O actor explícito não existe.',
            ];
        }

        if (! $actor->isActive()) {
            return [
                'actor' => null,
                'action' => self::ACTION_ACTOR_INACTIVE,
                'reason' => 'O actor explícito está inativo.',
            ];
        }

        if (! $actor->isApproved()) {
            return [
                'actor' => null,
                'action' => self::ACTION_ACTOR_UNAPPROVED,
                'reason' => 'O actor explícito ainda não foi aprovado.',
            ];
        }

        if (! $actor->can($permission->value)) {
            return [
                'actor' => null,
                'action' => self::ACTION_ACTOR_UNAUTHORIZED,
                'reason' => sprintf('O actor explícito não possui a permission %s.', $permission->value),
            ];
        }

        return ['actor' => $actor, 'action' => '', 'reason' => ''];
    }
}
