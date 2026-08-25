<?php

namespace App\Jobs;

use App\Mail\Nimbus\NimbusCorrectionRequestedMail;
use App\Mail\Nimbus\NimbusCorrectionResponseMail;
use App\Mail\Nimbus\NimbusSubmissionCompletedMail;
use App\Mail\Nimbus\NimbusSubmissionReceivedMail;
use App\Mail\Nimbus\NimbusSubmissionRejectedMail;
use App\Mail\Nimbus\SendPortalAccessCode;
use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\NotificationOutbox;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Services\Security\PiiPseudonymizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProcessNimbusNotificationOutbox implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $outboxId,
    ) {}

    public function uniqueId(): string
    {
        return 'nimbus-outbox-'.$this->outboxId;
    }

    public function handle(): void
    {
        $outbox = DB::transaction(function (): ?NotificationOutbox {
            $record = NotificationOutbox::where('id', $this->outboxId)->lockForUpdate()->first();
            if (! $record) {
                return null;
            }

            // Terminal states.
            if (in_array(strtoupper($record->status), ['SENT', 'CANCELLED'], true)) {
                return null;
            }

            // Not due yet.
            if ($record->next_attempt_at && $record->next_attempt_at->isFuture()) {
                return null;
            }

            // Stale SENDING recovery: if SENDING but not stale, skip.
            if (strtoupper($record->status) === 'SENDING') {
                $staleThreshold = now()->subMinutes(15);
                if ($record->updated_at && $record->updated_at->gt($staleThreshold)) {
                    return null;
                }
                // Stale → allow re-claim.
            }

            // Max attempts check for FAILED.
            if (strtoupper($record->status) === 'FAILED' && $record->attempts >= $record->max_attempts) {
                return null;
            }

            // Only PENDING, FAILED, or stale SENDING are claimable.
            if (! in_array(strtoupper($record->status), ['PENDING', 'FAILED', 'SENDING'], true)) {
                return null;
            }

            // Atomic claim: move to SENDING and increment attempts.
            $record->update([
                'status' => 'SENDING',
                'attempts' => $record->attempts + 1,
                'last_error' => null,
            ]);

            return $record->fresh();
        });

        if (! $outbox) {
            return;
        }

        try {
            $this->deliver($outbox);

            // Success → SENT
            $outbox->update([
                'status' => 'SENT',
                'sent_at' => now(),
                'last_error' => null,
                'next_attempt_at' => null,
            ]);

            // Audit
            activity('nimbus')
                ->performedOn($outbox)
                ->withProperties([
                    'type' => $outbox->type,
                    'correlation_id' => $outbox->correlation_id,
                    'recipient_hash' => PiiPseudonymizer::email($outbox->recipient_email),
                    'attempt' => $outbox->attempts,
                ])
                ->log('nimbus.notification.sent');

        } catch (\Throwable $e) {
            $sanitized = $this->sanitizeError($e->getMessage());

            $nextAttempt = null;
            if ($outbox->attempts < $outbox->max_attempts) {
                $delay = $this->backoffForAttempt($outbox->attempts);
                $nextAttempt = now()->addSeconds($delay);
            }

            $outbox->update([
                'status' => 'FAILED',
                'last_error' => $sanitized,
                'next_attempt_at' => $nextAttempt,
            ]);

            // Audit failure without PII
            activity('nimbus')
                ->performedOn($outbox)
                ->withProperties([
                    'type' => $outbox->type,
                    'correlation_id' => $outbox->correlation_id,
                    'error' => $sanitized,
                    'attempt' => $outbox->attempts,
                    'next_attempt_at' => $nextAttempt?->toIso8601String(),
                ])
                ->log('nimbus.notification.failed');

            Log::warning('Nimbus outbox delivery failed', [
                'outbox_id' => $outbox->id,
                'type' => $outbox->type,
                'correlation_id' => $outbox->correlation_id,
                'attempt' => $outbox->attempts,
                'error' => $sanitized,
            ]);

            // Do not rethrow — outbox handles retry via next_attempt_at.
            // If this was a queue-level failure (e.g., timeout), Laravel may still retry job;
            // idempotency via SENDING stale recovery protects double-send.
        }
    }

    protected function deliver(NotificationOutbox $outbox): void
    {
        $payload = $outbox->payload_json ?? [];
        $type = strtolower($outbox->type);
        $mailer = (string) config('nimbus.mail.mailer', config('mail.default'));

        switch ($type) {
            case 'submission_received':
            case 'nimbus_submission_received':
                $submission = Submission::find($payload['submission_id'] ?? null);
                if (! $submission) {
                    throw new \RuntimeException('Submission not found for notification');
                }
                Mail::mailer($mailer)->to($outbox->recipient_email)->send(new NimbusSubmissionReceivedMail($submission));
                break;

            case 'correction_requested':
                $submission = Submission::find($payload['submission_id'] ?? null);
                if (! $submission) {
                    throw new \RuntimeException('Submission not found');
                }
                Mail::mailer($mailer)->to($outbox->recipient_email)->send(new NimbusCorrectionRequestedMail($submission));
                break;

            case 'correction_response_received':
                $submission = Submission::find($payload['submission_id'] ?? null);
                if (! $submission) {
                    throw new \RuntimeException('Submission not found');
                }
                Mail::mailer($mailer)->to($outbox->recipient_email)->send(new NimbusCorrectionResponseMail($submission));
                break;

            case 'submission_completed':
                $submission = Submission::find($payload['submission_id'] ?? null);
                if (! $submission) {
                    throw new \RuntimeException('Submission not found');
                }
                Mail::mailer($mailer)->to($outbox->recipient_email)->send(new NimbusSubmissionCompletedMail($submission));
                break;

            case 'submission_rejected':
                $submission = Submission::find($payload['submission_id'] ?? null);
                if (! $submission) {
                    throw new \RuntimeException('Submission not found');
                }
                Mail::mailer($mailer)->to($outbox->recipient_email)->send(new NimbusSubmissionRejectedMail($submission));
                break;

            case 'portal_access_code':
            case 'token_created':
                $this->deliverAccessCode($outbox);
                break;

            default:
                throw new \RuntimeException('Unknown notification type: '.$outbox->type);
        }
    }

    protected function deliverAccessCode(NotificationOutbox $outbox): void
    {
        $payload = $outbox->payload_json ?? [];
        $portalUserId = $payload['portal_user_id'] ?? null;
        if (! $portalUserId) {
            throw new \RuntimeException('portal_user_id missing for access code');
        }

        $portalUser = PortalUser::find($portalUserId);
        if (! $portalUser) {
            throw new \RuntimeException('PortalUser not found');
        }

        // Generate plaintext ONLY in memory, persist hash only.
        $code = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)).'-'.
            strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)).'-'.
            strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $expiresAt = now()->addDays((int) config('nimbus.access_tokens.expires_in_days', 7));
        $hash = AccessToken::computeHash($code);

        $token = null;
        $mailer = (string) config('nimbus.mail.mailer', config('mail.default'));

        try {
            // Create/replace pending token inside transaction, revoke olds.
            DB::transaction(function () use ($portalUser, $hash, $expiresAt, &$token): void {
                $portalUser->accessTokens()->where('status', 'PENDING')->update(['status' => 'REVOKED']);
                $token = $portalUser->accessTokens()->create([
                    'code_hash' => $hash,
                    'status' => 'PENDING',
                    'expires_at' => $expiresAt,
                ]);
            });

            // Synchronous mail while plaintext exists only in memory.
            Mail::mailer($mailer)->to($portalUser->email)->send(new SendPortalAccessCode(
                user: $portalUser,
                code: $code,
                accessUrl: route('nimbus.auth.request'),
                expiresAt: $expiresAt,
            ));

            // Audit without plaintext.
            activity('nimbus')
                ->performedOn($token)
                ->causedBy(null)
                ->withProperties([
                    'portal_user_id' => $portalUser->id,
                    'email_hash' => PiiPseudonymizer::email($portalUser->email),
                    'expires_at' => $expiresAt->toIso8601String(),
                    'outbox_id' => $outbox->id,
                    'correlation_id' => $outbox->correlation_id,
                ])
                ->log('nimbus.access_token.delivered_via_outbox');

            // Ensure plaintext discarded.
            unset($code);
        } catch (\Throwable $e) {
            // On mail failure, revoke the token we just created to avoid valid unknown code.
            if ($token && $token->exists) {
                try {
                    $token->update(['status' => 'REVOKED']);
                    activity('nimbus')
                        ->performedOn($token)
                        ->withProperties([
                            'portal_user_id' => $portalUser->id,
                            'outbox_id' => $outbox->id,
                            'reason' => 'mail_failed_revoked',
                        ])
                        ->log('nimbus.access_token.revoked_after_failed_delivery');
                } catch (\Throwable $revokeEx) {
                    Log::error('Failed to revoke token after mail failure', [
                        'outbox_id' => $outbox->id,
                        'token_id' => $token->id ?? null,
                    ]);
                }
            }
            // Discard plaintext.
            if (isset($code)) {
                unset($code);
            }
            throw $e;
        }
    }

    protected function backoffForAttempt(int $attempt): int
    {
        return match ($attempt) {
            1 => 60,
            2 => 300,
            3 => 900,
            4 => 3600,
            default => 7200,
        };
    }

    protected function sanitizeError(string $message): string
    {
        // Truncate and strip potential PII / SMTP credentials.
        $sanitized = Str::limit($message, 1000, '');
        // Remove any plaintext code pattern XXXX-XXXX-XXXX if accidentally included.
        $sanitized = preg_replace('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', '[REDACTED]', $sanitized) ?? $sanitized;

        return $sanitized;
    }
}
