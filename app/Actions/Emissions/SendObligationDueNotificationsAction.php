<?php

namespace App\Actions\Emissions;

use App\Filament\Resources\Emissions\EmissionResource;
use App\Mail\ObligationDueNotificationMail;
use App\Models\Obligation;
use App\Models\ObligationNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
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

                    $recipient = $this->resolveRecipient($obligation, $fallbackEmail);

                    if ($recipient === null) {
                        $ignored++;

                        continue;
                    }

                    if ($this->alreadyNotified($obligation, $milestone)) {
                        $ignored++;

                        continue;
                    }

                    if ($sent >= $maxPerRun) {
                        $ignored++;

                        continue;
                    }

                    if ($this->dispatchNotification($obligation, $milestone, $type, $recipient)) {
                        $sent++;
                    } else {
                        $failed++;
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
    ): bool {
        $actionUrl = $this->resolveActionUrl($obligation);

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

            ObligationNotification::create([
                'obligation_id' => $obligation->id,
                'emission_id' => $obligation->emission_id,
                'notification_type' => $type,
                'milestone' => $milestone,
                'recipient' => $recipient,
                'status' => ObligationNotification::STATUS_FAILED,
                'error_message' => Str::limit($exception->getMessage(), 500),
            ]);

            return false;
        }

        ObligationNotification::create([
            'obligation_id' => $obligation->id,
            'emission_id' => $obligation->emission_id,
            'notification_type' => $type,
            'milestone' => $milestone,
            'recipient' => $recipient,
            'status' => ObligationNotification::STATUS_SENT,
            'sent_at' => now(),
        ]);

        return true;
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
