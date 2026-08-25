<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyAccessTokenDto
{
    public function __construct(
        public int|string $id,
        public int|string $portalUserId,
        public string $code,
        public string $status,
        public string $expiresAt,
        public ?string $usedAt,
        public ?string $usedIp,
        public ?string $usedUserAgent,
        public string $createdAt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            portalUserId: $row['portal_user_id'],
            code: $row['code'],
            status: $row['status'] ?? 'PENDING',
            expiresAt: $row['expires_at'],
            usedAt: $row['used_at'] ?? null,
            usedIp: $row['used_ip'] ?? null,
            usedUserAgent: $row['used_user_agent'] ?? null,
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
        );
    }
}
