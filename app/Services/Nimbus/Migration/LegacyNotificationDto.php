<?php

namespace App\Services\Nimbus\Migration;

readonly class LegacyNotificationDto
{
    public function __construct(
        public int|string $id,
        public string $type,
        public string $recipientEmail,
        public ?string $recipientName,
        public string $subject,
        public string $template,
        public array $payload,
        public ?string $correlationId,
        public string $status,
        public int $attempts,
        public int $maxAttempts,
        public ?string $nextAttemptAt,
        public ?string $lastError,
        public string $createdAt,
        public ?string $sentAt,
    ) {}

    public static function fromArray(array $row): self
    {
        $payload = $row['payload_json'] ?? $row['payload'] ?? '{}';
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id: $row['id'],
            type: $row['type'],
            recipientEmail: $row['recipient_email'],
            recipientName: $row['recipient_name'] ?? null,
            subject: $row['subject'] ?? '',
            template: $row['template'] ?? '',
            payload: $payload,
            correlationId: $row['correlation_id'] ?? null,
            status: $row['status'] ?? 'PENDING',
            attempts: (int) ($row['attempts'] ?? 0),
            maxAttempts: (int) ($row['max_attempts'] ?? 5),
            nextAttemptAt: $row['next_attempt_at'] ?? null,
            lastError: $row['last_error'] ?? null,
            createdAt: $row['created_at'] ?? now()->toDateTimeString(),
            sentAt: $row['sent_at'] ?? null,
        );
    }
}
