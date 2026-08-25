<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyAuditDto
{
    public function __construct(
        public int|string $id,
        public string $occurredAt,
        public string $actorType,
        public ?string $actorId,
        public ?string $actorName,
        public string $action,
        public ?string $targetType,
        public ?string $targetId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $summary,
        public ?array $details,
    ) {}

    public static function fromArray(array $row): self
    {
        $details = $row['details'] ?? null;
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            $details = is_array($decoded) ? $decoded : null;
        }

        return new self(
            id: $row['id'],
            occurredAt: $row['occurred_at'] ?? $row['created_at'] ?? now()->toDateTimeString(),
            actorType: $row['actor_type'] ?? 'SYSTEM',
            actorId: isset($row['actor_id']) ? (string) $row['actor_id'] : null,
            actorName: $row['actor_name'] ?? null,
            action: $row['action'],
            targetType: $row['target_type'] ?? null,
            targetId: isset($row['target_id']) ? (string) $row['target_id'] : null,
            ipAddress: $row['ip_address'] ?? null,
            userAgent: $row['user_agent'] ?? null,
            summary: $row['summary'] ?? null,
            details: $details,
        );
    }
}
