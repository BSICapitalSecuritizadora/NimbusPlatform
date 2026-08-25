<?php

namespace App\Services\Nimbus;

use App\Models\Nimbus\NotificationOutbox;
use App\Models\Nimbus\NotificationSetting;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class NimbusNotificationService
{
    public const TYPE_SUBMISSION_RECEIVED = 'submission_received';

    public const TYPE_CORRECTION_REQUESTED = 'correction_requested';

    public const TYPE_CORRECTION_RESPONSE = 'correction_response_received';

    public const TYPE_COMPLETED = 'submission_completed';

    public const TYPE_REJECTED = 'submission_rejected';

    public const TYPE_ACCESS_CODE = 'portal_access_code';

    /**
     * Enqueue notification idempotently using correlation_id.
     * Returns outbox record or null if disabled by settings.
     */
    public function enqueue(string $type, array $payload, string $recipientEmail, ?string $recipientName, string $subject, string $template, string $correlationId): ?NotificationOutbox
    {
        // Mandatory types bypass settings.
        if (! $this->isMandatory($type) && ! $this->isEnabledBySettings($type)) {
            return null;
        }

        // Normalize correlation.
        $correlationId = Str::limit($correlationId, 100, '');

        return DB::transaction(function () use ($type, $payload, $recipientEmail, $recipientName, $subject, $template, $correlationId): ?NotificationOutbox {
            $existing = NotificationOutbox::where('correlation_id', $correlationId)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            return NotificationOutbox::create([
                'type' => $type,
                'recipient_email' => Str::lower($recipientEmail),
                'recipient_name' => $recipientName,
                'subject' => $subject,
                'template' => $template,
                'payload_json' => $payload,
                'correlation_id' => $correlationId,
                'status' => 'PENDING',
                'attempts' => 0,
                'max_attempts' => 5,
                'next_attempt_at' => null,
                'sent_at' => null,
                'last_error' => null,
            ]);
        });
    }

    public function enqueueSubmissionReceived(Submission $submission): ?NotificationOutbox
    {
        $submission->loadMissing('portalUser');
        $portalUser = $submission->portalUser;
        if (! $portalUser || blank($portalUser->email)) {
            return null;
        }

        $recipients = $this->resolveAdminRecipients();
        if ($recipients->isEmpty()) {
            if ($this->isEnabledBySettings(self::TYPE_SUBMISSION_RECEIVED)) {
                $this->logMissingRecipient(self::TYPE_SUBMISSION_RECEIVED, [
                    'submission_id' => $submission->id,
                    'reference_code' => $submission->reference_code,
                ]);
            }

            return null;
        }

        // Create one outbox per admin recipient, correlated individually.
        // For simplicity, create first admin as primary; additional recipients could be added later.
        $admin = $recipients->first();
        $correlation = $this->correlationFor(self::TYPE_SUBMISSION_RECEIVED, ['submission_id' => $submission->id, 'admin_id' => $admin->id]);

        return $this->enqueue(
            type: self::TYPE_SUBMISSION_RECEIVED,
            payload: [
                'submission_id' => $submission->id,
                'reference_code' => $submission->reference_code,
                'portal_user_id' => $portalUser->id,
            ],
            recipientEmail: $admin->email,
            recipientName: $admin->name,
            subject: 'Nova submissão recebida — '.$submission->reference_code,
            template: 'nimbus_submission_received',
            correlationId: $correlation,
        );
    }

    public function enqueueCorrectionRequested(Submission $submission, string $reason, ?int $historyId = null): ?NotificationOutbox
    {
        $submission->loadMissing('portalUser');
        $portalUser = $submission->portalUser;
        if (! $portalUser || blank($portalUser->email)) {
            return null;
        }

        // Use immutable workflow history id as event identity for repeatable correction cycles.
        $historyId ??= $submission->statusHistories()->where('old_status', Submission::STATUS_UNDER_REVIEW)->where('new_status', Submission::STATUS_NEEDS_CORRECTION)->reorder()->orderByDesc('id')->first()?->id;
        $correlation = $historyId
            ? $this->correlationFor(self::TYPE_CORRECTION_REQUESTED, ['history_id' => $historyId])
            : $this->correlationFor(self::TYPE_CORRECTION_REQUESTED, ['submission_id' => $submission->id]);

        return $this->enqueue(
            type: self::TYPE_CORRECTION_REQUESTED,
            payload: [
                'submission_id' => $submission->id,
                'reference_code' => $submission->reference_code,
                'portal_user_id' => $portalUser->id,
                'history_id' => $historyId,
                'has_reason' => filled($reason),
            ],
            recipientEmail: $portalUser->email,
            recipientName: $portalUser->full_name,
            subject: 'Sua submissão requer correção — '.$submission->reference_code,
            template: 'nimbus_correction_requested',
            correlationId: $correlation,
        );
    }

    public function enqueueCorrectionResponseReceived(Submission $submission, ?int $historyId = null): ?NotificationOutbox
    {
        $recipients = $this->resolveAdminRecipients();
        if ($recipients->isEmpty()) {
            if ($this->isEnabledBySettings(self::TYPE_CORRECTION_RESPONSE)) {
                $historyId ??= $submission->statusHistories()->where('old_status', Submission::STATUS_NEEDS_CORRECTION)->where('new_status', Submission::STATUS_UNDER_REVIEW)->reorder()->orderByDesc('id')->first()?->id;
                $this->logMissingRecipient(self::TYPE_CORRECTION_RESPONSE, [
                    'submission_id' => $submission->id,
                    'history_id' => $historyId,
                ]);
            }

            return null;
        }
        $admin = $recipients->first();
        // Use history id for distinct response cycles (NEEDS_CORRECTION -> UNDER_REVIEW).
        $historyId ??= $submission->statusHistories()->where('old_status', Submission::STATUS_NEEDS_CORRECTION)->where('new_status', Submission::STATUS_UNDER_REVIEW)->reorder()->orderByDesc('id')->first()?->id;
        $correlation = $historyId
            ? $this->correlationFor(self::TYPE_CORRECTION_RESPONSE, ['history_id' => $historyId, 'admin_id' => $admin->id])
            : $this->correlationFor(self::TYPE_CORRECTION_RESPONSE, ['submission_id' => $submission->id, 'admin_id' => $admin->id]);

        return $this->enqueue(
            type: self::TYPE_CORRECTION_RESPONSE,
            payload: [
                'submission_id' => $submission->id,
                'reference_code' => $submission->reference_code,
                'portal_user_id' => $submission->nimbus_portal_user_id,
                'history_id' => $historyId,
            ],
            recipientEmail: $admin->email,
            recipientName: $admin->name,
            subject: 'Resposta de correção recebida — '.$submission->reference_code,
            template: 'nimbus_correction_response',
            correlationId: $correlation,
        );
    }

    public function enqueueCompleted(Submission $submission): ?NotificationOutbox
    {
        $submission->loadMissing('portalUser');
        $portalUser = $submission->portalUser;
        if (! $portalUser || blank($portalUser->email)) {
            return null;
        }
        $correlation = $this->correlationFor(self::TYPE_COMPLETED, ['submission_id' => $submission->id]);

        return $this->enqueue(
            type: self::TYPE_COMPLETED,
            payload: [
                'submission_id' => $submission->id,
                'reference_code' => $submission->reference_code,
                'portal_user_id' => $portalUser->id,
            ],
            recipientEmail: $portalUser->email,
            recipientName: $portalUser->full_name,
            subject: 'Sua submissão foi concluída — '.$submission->reference_code,
            template: 'nimbus_submission_completed',
            correlationId: $correlation,
        );
    }

    public function enqueueRejected(Submission $submission): ?NotificationOutbox
    {
        $submission->loadMissing('portalUser');
        $portalUser = $submission->portalUser;
        if (! $portalUser || blank($portalUser->email)) {
            return null;
        }
        $correlation = $this->correlationFor(self::TYPE_REJECTED, ['submission_id' => $submission->id]);

        return $this->enqueue(
            type: self::TYPE_REJECTED,
            payload: [
                'submission_id' => $submission->id,
                'reference_code' => $submission->reference_code,
                'portal_user_id' => $portalUser->id,
            ],
            recipientEmail: $portalUser->email,
            recipientName: $portalUser->full_name,
            subject: 'Atualização sobre sua submissão — '.$submission->reference_code,
            template: 'nimbus_submission_rejected',
            correlationId: $correlation,
        );
    }

    public function enqueueAccessCode(PortalUser $portalUser): ?NotificationOutbox
    {
        if (blank($portalUser->email)) {
            return null;
        }

        // Short-window dedup must cover both PENDING and SENDING to avoid race after claim.
        // Use transaction with row lock on portal_user to serialize concurrent requests per user.
        return DB::transaction(function () use ($portalUser): ?NotificationOutbox {
            // Serialize per portal user
            PortalUser::where('id', $portalUser->id)->lockForUpdate()->first();

            $existing = NotificationOutbox::where('type', self::TYPE_ACCESS_CODE)
                ->whereIn('status', ['PENDING', 'SENDING'])
                ->where('recipient_email', Str::lower($portalUser->email))
                ->where('created_at', '>=', now()->subSeconds(30))
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $correlation = $this->correlationFor(self::TYPE_ACCESS_CODE, ['portal_user_id' => $portalUser->id, 'ts' => (string) Str::uuid()]);

            // Direct create inside same transaction to keep dedup atomic; reuse enqueue logic without nested transaction overhead
            // Check correlation uniqueness as well
            $existingCorr = NotificationOutbox::where('correlation_id', Str::limit($correlation, 100, ''))->lockForUpdate()->first();
            if ($existingCorr) {
                return $existingCorr;
            }

            return NotificationOutbox::create([
                'type' => self::TYPE_ACCESS_CODE,
                'recipient_email' => Str::lower($portalUser->email),
                'recipient_name' => $portalUser->full_name,
                'subject' => 'Seu Código de Acesso ao Portal - BSI Capital',
                'template' => 'nimbus_access_code',
                'payload_json' => ['portal_user_id' => $portalUser->id],
                'correlation_id' => Str::limit($correlation, 100, ''),
                'status' => 'PENDING',
                'attempts' => 0,
                'max_attempts' => 5,
                'next_attempt_at' => null,
                'sent_at' => null,
                'last_error' => null,
            ]);
        });
    }

    public function correlationFor(string $type, array $identifiers): string
    {
        ksort($identifiers);
        $raw = $type.':'.json_encode($identifiers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return substr(hash('sha256', $raw), 0, 64);
    }

    public function isMandatory(string $type): bool
    {
        return $type === self::TYPE_ACCESS_CODE;
    }

    public function isEnabledBySettings(string $type): bool
    {
        $map = [
            self::TYPE_SUBMISSION_RECEIVED => 'portal.notify.new_submission',
            self::TYPE_CORRECTION_REQUESTED => 'portal.notify.status_change',
            self::TYPE_COMPLETED => 'portal.notify.status_change',
            self::TYPE_REJECTED => 'portal.notify.status_change',
            self::TYPE_CORRECTION_RESPONSE => 'portal.notify.response_upload',
        ];

        $key = $map[$type] ?? null;
        if ($key === null) {
            // Unknown types default to enabled if not mapped; but access_code is mandatory.
            return true;
        }

        $values = NotificationSetting::getValues([$key]);
        $raw = $values[$key] ?? '1';

        return $raw === '1';
    }

    /**
     * Resolve admin recipients via narrowest explicit source — only users with Nimbus permission.
     * No broad fallback to every global admin.
     *
     * @return Collection<int, User>
     */
    public function resolveAdminRecipients()
    {
        try {
            return User::permission('nimbus.submissions.view')->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    private function logMissingRecipient(string $type, array $context): void
    {
        // Do not log duplicate for same business event within last hour
        $correlation = $context['history_id'] ?? $context['submission_id'] ?? null;
        $properties = [
            'notification_type' => $type,
            'required_permission' => 'nimbus.submissions.view',
            'submission_id' => $context['submission_id'] ?? null,
            'history_id' => $context['history_id'] ?? null,
            'reference_code' => $context['reference_code'] ?? null,
        ];

        // Check for recent same audit to avoid noise on retry
        try {
            $recent = Activity::where('log_name', 'nimbus')
                ->where('description', 'nimbus.notification.no_eligible_recipient')
                ->where('created_at', '>=', now()->subHour())
                ->get()
                ->filter(function ($activity) use ($properties) {
                    $props = $activity->properties instanceof Collection ? $activity->properties->toArray() : (array) $activity->properties;

                    return ($props['notification_type'] ?? null) === $properties['notification_type']
                        && ($props['submission_id'] ?? null) === $properties['submission_id']
                        && ($props['history_id'] ?? null) === $properties['history_id'];
                });
            if ($recent->isNotEmpty()) {
                return;
            }
        } catch (\Throwable $e) {
            // ignore check failure, proceed to log
        }

        try {
            activity('nimbus')
                ->withProperties($properties)
                ->log('nimbus.notification.no_eligible_recipient');
        } catch (\Throwable $e) {
            // Never fail business operation due to audit failure
        }
    }
}
