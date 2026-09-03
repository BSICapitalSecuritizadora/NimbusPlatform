<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Models\User;

final readonly class PuGovernanceActorResolution
{
    public function __construct(
        public ?User $actor,
        public ?string $failure,
        public ?string $reason,
    ) {}

    public function resolved(): bool
    {
        return $this->actor instanceof User;
    }
}
