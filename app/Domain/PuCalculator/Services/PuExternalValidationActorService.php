<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuGovernanceActorResolution;
use App\Enums\AccessPermission;
use App\Models\User;

final class PuExternalValidationActorService
{
    public const ACTOR_REQUIRED = 'external_validation_actor_required';

    public const ACTOR_NOT_FOUND = 'external_validation_actor_not_found';

    public const ACTOR_INACTIVE = 'external_validation_actor_inactive';

    public const ACTOR_UNAPPROVED = 'external_validation_actor_unapproved';

    public const ACTOR_UNAUTHORIZED = 'external_validation_actor_unauthorized';

    public function resolve(?string $identifier, bool $lockForUpdate = false): PuGovernanceActorResolution
    {
        $normalized = trim((string) $identifier);

        if ($normalized === '') {
            return new PuGovernanceActorResolution(null, self::ACTOR_REQUIRED, 'An explicit actor is required for writes.');
        }

        $query = User::query();

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $actor = ctype_digit($normalized)
            ? $query->whereKey((int) $normalized)->first()
            : $query->where('email', $normalized)->first();

        if (! $actor instanceof User) {
            return new PuGovernanceActorResolution(null, self::ACTOR_NOT_FOUND, 'The explicit actor does not exist.');
        }

        if (! $actor->isActive()) {
            return new PuGovernanceActorResolution(null, self::ACTOR_INACTIVE, 'The explicit actor is inactive.');
        }

        if (! $actor->isApproved()) {
            return new PuGovernanceActorResolution(null, self::ACTOR_UNAPPROVED, 'The explicit actor is not approved.');
        }

        if (! $actor->can(AccessPermission::PuCurveHomologate->value)) {
            return new PuGovernanceActorResolution(null, self::ACTOR_UNAUTHORIZED, sprintf(
                'The explicit actor does not have permission %s.',
                AccessPermission::PuCurveHomologate->value,
            ));
        }

        return new PuGovernanceActorResolution($actor, null, null);
    }
}
