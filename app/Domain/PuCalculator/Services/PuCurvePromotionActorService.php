<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuGovernanceActorResolution;
use App\Enums\AccessPermission;
use App\Models\User;

/**
 * Resolve os atores da promoção operacional.
 *
 * A autoridade exigida é `pu.curve.promote`, e não `pu.curve.homologate`:
 * homologar/revisar decide sobre o conteúdo da candidate, promover troca a curva
 * vigente. São efeitos diferentes e por isso permissões diferentes — quem revisa
 * candidate não herda a autoridade de mudar o que a operação lê.
 */
final class PuCurvePromotionActorService
{
    public const ACTOR_REQUIRED = 'promotion_actor_required';

    public const ACTOR_NOT_FOUND = 'promotion_actor_not_found';

    public const ACTOR_INACTIVE = 'promotion_actor_inactive';

    public const ACTOR_UNAPPROVED = 'promotion_actor_unapproved';

    public const ACTOR_UNAUTHORIZED = 'promotion_actor_unauthorized';

    public function resolve(?string $identifier, bool $lockForUpdate = false): PuGovernanceActorResolution
    {
        $normalized = trim((string) $identifier);

        if ($normalized === '') {
            return new PuGovernanceActorResolution(
                null,
                self::ACTOR_REQUIRED,
                'An explicit actor is required for promotion writes.',
            );
        }

        $query = User::query();

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $actor = ctype_digit($normalized)
            ? $query->whereKey((int) $normalized)->first()
            : $query->where('email', $normalized)->first();

        if (! $actor instanceof User) {
            return new PuGovernanceActorResolution(
                null,
                self::ACTOR_NOT_FOUND,
                'The explicit promotion actor does not exist.',
            );
        }

        if (! $actor->isActive()) {
            return new PuGovernanceActorResolution(
                null,
                self::ACTOR_INACTIVE,
                'The explicit promotion actor is inactive.',
            );
        }

        if (! $actor->isApproved()) {
            return new PuGovernanceActorResolution(
                null,
                self::ACTOR_UNAPPROVED,
                'The explicit promotion actor is not approved.',
            );
        }

        if (! $actor->can(AccessPermission::PuCurvePromote->value)) {
            return new PuGovernanceActorResolution(null, self::ACTOR_UNAUTHORIZED, sprintf(
                'The explicit promotion actor does not have permission %s.',
                AccessPermission::PuCurvePromote->value,
            ));
        }

        return new PuGovernanceActorResolution($actor, null, null);
    }
}
