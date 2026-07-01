<?php

namespace App\Listeners;

use App\Models\ReminderLog;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

class LogNotificationListener
{
    public function handleSent(NotificationSent $event): void
    {
        $this->logNotification($event->notifiable, $event->notification, $event->channel, 'sent');
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $this->logNotification($event->notifiable, $event->notification, $event->channel, 'failed', $event->data ?? []);
    }

    protected function logNotification($notifiable, $notification, $channel, $status, array $data = []): void
    {
        // Ignorar logs para canais indesejados ou que poluem
        if (! in_array($channel, ['mail', 'database'])) {
            return;
        }

        $type = get_class($notification);
        $reason = method_exists($notification, 'getReason') ? $notification->getReason() : null;
        $severity = method_exists($notification, 'getSeverity') ? $notification->getSeverity() : 'info';

        $email = null;
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $email = $notifiable->routeNotificationFor('mail');
        } elseif (isset($notifiable->email)) {
            $email = $notifiable->email;
        }

        ReminderLog::create([
            'type' => class_basename($type),
            'channel' => $channel,
            'status' => $status,
            'notifiable_type' => is_object($notifiable) ? get_class($notifiable) : null,
            'notifiable_id' => is_object($notifiable) && method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
            'recipient_email' => $email,
            'severity' => $severity,
            'reason' => $reason,
            'error_message' => $status === 'failed' ? json_encode($data) : null,
            'sent_at' => now(),
            'payload' => method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : null,
        ]);
    }
}
