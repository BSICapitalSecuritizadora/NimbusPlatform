<?php

namespace App\Services\Nimbus\Migration;

/**
 * Sanitizes legacy notification/access-token/audit payloads via allowlist reconstruction.
 * Never copies raw payloads with secrets.
 */
class LegacyArchiveSanitizer
{
    /**
     * For notification_outbox: allowlist rebuild.
     * Input is LegacyNotificationDto (already decoded payload).
     * Output is sanitized summary for archival JSONL.
     */
    public static function sanitizeNotification(LegacyNotificationDto $dto): array
    {
        // Pseudonymize recipient — do not keep raw email in archive summary unless justified
        $email = $dto->recipientEmail ?? '';
        $pseudonym = $email !== '' ? 'pii_'.substr(hash('sha256', strtolower(trim($email))), 0, 16) : null;

        // Extract only allowlisted fields from payload
        $payloadSummary = [];
        // Legacy payload may contain user/token/submission keys — pick minimal
        if (isset($dto->payload['submission']['reference_code'])) {
            $payloadSummary['submission_reference_code'] = $dto->payload['submission']['reference_code'];
        } elseif (isset($dto->payload['submission_reference_code'])) {
            $payloadSummary['submission_reference_code'] = $dto->payload['submission_reference_code'];
        }
        if (isset($dto->payload['submission']['id'])) {
            $payloadSummary['submission_id'] = $dto->payload['submission']['id'];
        }
        // portal_user_id if present
        $puId = $dto->payload['user']['id'] ?? $dto->payload['portal_user_id'] ?? $dto->payload['token']['portal_user_id'] ?? null;
        if ($puId !== null) {
            $payloadSummary['portal_user_id'] = $puId;
        }

        // Never include: raw email, CPF, phone, password_hash, code, token hash
        return [
            'legacy_id' => (string) $dto->id,
            'type' => $dto->type,
            'recipient_hash' => $pseudonym,
            'status' => $dto->status,
            'attempts' => $dto->attempts,
            'max_attempts' => $dto->maxAttempts,
            'created_at' => $dto->createdAt,
            'sent_at' => $dto->sentAt,
            'correlation_id' => $dto->correlationId,
            'payload_summary' => $payloadSummary,
            // failure classification without raw error containing secrets
            'error_class' => $dto->lastError !== null ? substr($dto->lastError, 0, 100) : null,
        ];
    }

    public static function sanitizeAccessToken(LegacyAccessTokenDto $dto): array
    {
        // Archive metadata only — never plaintext code
        return [
            'legacy_id' => (string) $dto->id,
            'legacy_portal_user_id' => (string) $dto->portalUserId,
            'status' => $dto->status,
            'created_at' => $dto->createdAt,
            'expires_at' => $dto->expiresAt,
            'used_at' => $dto->usedAt,
            'used_ip' => $dto->usedIp,
            'used_user_agent' => $dto->usedUserAgent !== null ? mb_substr($dto->usedUserAgent, 0, 200) : null,
            // No code, no code_hash
        ];
    }

    public static function sanitizeAudit(LegacyAuditDto $dto): array
    {
        // Preserve original timestamps, actor, action, target, sanitized details
        $sanitizedDetails = null;
        if (is_array($dto->details)) {
            // Allow only non-secret keys — strip code/password/cpf/phone
            $allow = ['reference_code', 'title', 'has_files', 'submission_id', 'portal_user_id', 'file_count', 'oldStatus', 'newStatus'];
            $sanitizedDetails = array_intersect_key($dto->details, array_flip($allow));
        }

        return [
            'legacy_id' => (string) $dto->id,
            'occurred_at' => $dto->occurredAt,
            'actor_type' => $dto->actorType,
            'actor_id' => $dto->actorId !== null ? (string) $dto->actorId : null,
            'action' => $dto->action,
            'target_type' => $dto->targetType,
            'target_id' => $dto->targetId !== null ? (string) $dto->targetId : null,
            'ip_address' => $dto->ipAddress,
            'details' => $sanitizedDetails,
            'provenance' => 'nimbusdocs-legacy',
        ];
    }
}
