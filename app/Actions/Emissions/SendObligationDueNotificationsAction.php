<?php

namespace App\Actions\Emissions;

use App\Filament\Resources\Emissions\EmissionResource;
use App\Mail\ObligationDueNotificationMail;
use App\Models\Obligation;
use App\Models\ObligationNotification;
use App\Services\Obligations\ObligationHistoryRecorder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendObligationDueNotificationsAction
{
    /**
     * Statuses that finalize an obligation or reflect a manual decision and
     * therefore must never trigger a due notification.
     *
     * @var list<string>
     */
    public const PROTECTED_STATUSES = ['concluida', 'em_analise', 'nao_aplicavel'];

    public function __construct(
        protected ObligationHistoryRecorder $historyRecorder,
    ) {}

    /**
     * Send due/overdue notifications for every eligible obligation.
     *
     * @return array{analyzed: int, eligible: int, sent: int, ignored: int, failed: int}
     */
    public function handle(?CarbonInterface $referenceDate = null): array
    {
        $today = ($referenceDate ?? now())->copy()->startOfDay();

        $dueSoonDays = (array) config('obligations.notifications.due_soon_days', [7, 3]);
        $notifyDueToday = (bool) config('obligations.notifications.notify_due_today', true);
        $notifyOverdue = (bool) config('obligations.notifications.notify_overdue', true);
        $maxPerRun = (int) config('obligations.notifications.max_per_run', 200);
        $fallbackEmail = config('obligations.notifications.fallback_email');

        $analyzed = 0;
        $eligible = 0;
        $sent = 0;
        $ignored = 0;
        $failed = 0;

        Log::info('SendObligationDueNotifications: início', [
            'reference_date' => $today->toDateString(),
            'due_soon_days' => $dueSoonDays,
            'max_per_run' => $maxPerRun,
        ]);

        Obligation::query()
            ->whereNotNull('due_date')
            ->whereNotIn('status', self::PROTECTED_STATUSES)
            ->with(['emission', 'responsibleUser'])
            ->chunkById(200, function (Collection $obligations) use (
                $today, $dueSoonDays, $notifyDueToday, $notifyOverdue, $maxPerRun, $fallbackEmail,
                &$analyzed, &$eligible, &$sent, &$ignored, &$failed,
            ): void {
                foreach ($obligations as $obligation) {
                    $analyzed++;

                    [$milestone, $type] = $this->resolveMilestone(
                        $obligation, $today, $dueSoonDays, $notifyDueToday, $notifyOverdue,
                    );

                    if ($milestone === null) {
                        $ignored++;

                        continue;
                    }

                    $eligible++;

                    $ownEmail = $obligation->responsibleUser?->email;
                    $recipient = $this->resolveRecipient($obligation, $fallbackEmail);

                    if ($recipient === null) {
                        Log::warning('SendObligationDueNotifications: obrigação elegível sem destinatário e sem fallback_email configurado — ignorada.', [
                            'obligation_id' => $obligation->id,
                            'emission_id' => $obligation->emission_id,
                            'milestone' => $milestone,
                        ]);

                        $ignored++;

                        continue;
                    }

                    if (blank($ownEmail)) {
                        Log::info('SendObligationDueNotifications: usando fallback_email (obrigação sem responsável).', [
                            'obligation_id' => $obligation->id,
                            'emission_id' => $obligation->emission_id,
                            'milestone' => $milestone,
                            'recipient' => $recipient,
                        ]);
                    }

                    if ($this->alreadyNotified($obligation, $milestone)) {
                        $ignored++;

                        continue;
                    }

                    if ($sent >= $maxPerRun) {
                        $ignored++;

                        continue;
                    }

                    $dispatchResult = $this->dispatchNotification($obligation, $milestone, $type, $recipient);

                    if ($dispatchResult === true) {
                        $sent++;
                    } elseif ($dispatchResult === false) {
                        $failed++;
                    } else {
                        $ignored++;
                    }
                }
            });

        $result = [
            'analyzed' => $analyzed,
            'eligible' => $eligible,
            'sent' => $sent,
            'ignored' => $ignored,
            'failed' => $failed,
        ];

        Log::info('SendObligationDueNotifications: concluído', $result);

        return $result;
    }

    /**
     * @param  list<int>  $dueSoonDays
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveMilestone(
        Obligation $obligation,
        CarbonInterface $today,
        array $dueSoonDays,
        bool $notifyDueToday,
        bool $notifyOverdue,
    ): array {
        $daysUntilDue = (int) $today->diffInDays($obligation->due_date->copy()->startOfDay(), false);

        if ($daysUntilDue < 0) {
            return $notifyOverdue
                ? ['overdue', ObligationNotification::TYPE_OVERDUE]
                : [null, null];
        }

        if ($daysUntilDue === 0) {
            return $notifyDueToday
                ? ['due_today', ObligationNotification::TYPE_DUE_TODAY]
                : [null, null];
        }

        if (in_array($daysUntilDue, array_map('intval', $dueSoonDays), true)) {
            return ["due_{$daysUntilDue}", ObligationNotification::TYPE_DUE_SOON];
        }

        return [null, null];
    }

    protected function resolveRecipient(Obligation $obligation, mixed $fallbackEmail): ?string
    {
        $email = $obligation->responsibleUser?->email ?: $fallbackEmail;

        if (! is_string($email) || blank($email)) {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    protected function alreadyNotified(Obligation $obligation, string $milestone): bool
    {
        return ObligationNotification::query()
            ->where('obligation_id', $obligation->id)
            ->where('milestone', $milestone)
            ->where('status', ObligationNotification::STATUS_SENT)
            ->exists();
    }

    protected function dispatchNotification(
        Obligation $obligation,
        string $milestone,
        string $type,
        string $recipient,
    ): ?bool {
        $actionUrl = $this->resolveActionUrl($obligation);
        $notification = $this->claimNotification($obligation, $milestone, $type, $recipient);

        if ($notification === null) {
            return null;
        }

        try {
            Mail::mailer((string) config('mail.default', 'log'))
                ->to($recipient)
                ->send(new ObligationDueNotificationMail($obligation, $milestone, $type, $actionUrl));
        } catch (\Throwable $exception) {
            Log::warning('SendObligationDueNotifications: falha ao enviar', [
                'obligation_id' => $obligation->id,
                'emission_id' => $obligation->emission_id,
                'milestone' => $milestone,
                'message' => $exception->getMessage(),
            ]);

            $notification->update([
                'status' => ObligationNotification::STATUS_FAILED,
                'error_message' => Str::limit($exception->getMessage(), 500),
            ]);

            $this->historyRecorder->recordNotificationFailed($obligation, $milestone, $type, $recipient, $exception->getMessage());

            return false;
        }

        $notification->update([
            'status' => ObligationNotification::STATUS_SENT,
            'sent_at' => now(),
            'error_message' => null,
        ]);

        $this->historyRecorder->recordNotificationSent($obligation, $milestone, $type, $recipient);

        return true;
    }

    protected function claimNotification(
        Obligation $obligation,
        string $milestone,
        string $type,
        string $recipient,
    ): ?ObligationNotification {
        $deduplicationKey = hash('sha256', implode('|', [
            $obligation->id,
            $milestone,
            mb_strtolower($recipient),
        ]));

        try {
            return ObligationNotification::create([
                'obligation_id' => $obligation->id,
                'emission_id' => $obligation->emission_id,
                'notification_type' => $type,
                'milestone' => $milestone,
                'deduplication_key' => $deduplicationKey,
                'recipient' => $recipient,
                'status' => ObligationNotification::STATUS_PROCESSING,
            ]);
        } catch (UniqueConstraintViolationException) {
            $claimed = ObligationNotification::query()
                ->where('deduplication_key', $deduplicationKey)
                ->where(function ($query): void {
                    $query
                        ->where('status', ObligationNotification::STATUS_FAILED)
                        ->orWhere(function ($processingQuery): void {
                            $processingQuery
                                ->where('status', ObligationNotification::STATUS_PROCESSING)
                                ->where('updated_at', '<=', now()->subHour());
                        });
                })
                ->update([
                    'status' => ObligationNotification::STATUS_PROCESSING,
                    'error_message' => null,
                ]);

            return $claimed === 1
                ? ObligationNotification::query()->where('deduplication_key', $deduplicationKey)->first()
                : null;
        }
    }

    protected function resolveActionUrl(Obligation $obligation): string
    {
        try {
            return EmissionResource::getUrl('edit', ['record' => $obligation->emission_id], panel: 'admin');
        } catch (\Throwable) {
            return (string) config('app.url', '/');
        }
    }
}
