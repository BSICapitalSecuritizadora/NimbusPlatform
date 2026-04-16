<?php

declare(strict_types=1);

namespace App\DTOs\Proposals;

use App\DTOs\BaseDTO;
use App\Enums\ProposalStatus;
use App\Models\User;
use InvalidArgumentException;

readonly class UpdateProposalStatusDTO extends BaseDTO
{
    public function __construct(
        public ProposalStatus $newStatus,
        public ?User $user,
        public ?string $note,
        public bool $authorize = true,
    ) {}

    public static function fromArray(array $data): self
    {
        $newStatus = ProposalStatus::fromValue($data['newStatus'] ?? $data['status'] ?? null);

        if (! $newStatus) {
            throw new InvalidArgumentException('Invalid proposal status provided.');
        }

        $user = $data['user'] ?? null;

        return new self(
            newStatus: $newStatus,
            user: $user instanceof User ? $user : null,
            note: self::nullableString($data['note'] ?? null),
            authorize: (bool) ($data['authorize'] ?? true),
        );
    }
}
