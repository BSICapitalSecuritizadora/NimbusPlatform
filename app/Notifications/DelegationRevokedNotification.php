<?php

namespace App\Notifications;

use App\Models\ResponsibilityDelegation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DelegationRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ResponsibilityDelegation $delegation) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[NimbusPlatform] Delegação revogada')
            ->greeting('Olá, '.($notifiable->name ?? ''))
            ->line('A delegação #'.$this->delegation->getKey().' foi revogada e não concede mais autoridade.')
            ->line('Motivo original: '.$this->delegation->reason)
            ->line('Motivo da revogação: '.$this->delegation->revocation_reason)
            ->action('Ver delegações', url('/admin/responsibility-delegations'))
            ->line('Se precisar de nova delegação, crie outra com período atualizado.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'delegation_id' => $this->delegation->getKey(),
            'delegate_user_id' => $this->delegation->delegate_user_id,
            'delegator_user_id' => $this->delegation->delegator_user_id,
            'revoked_at' => $this->delegation->revoked_at?->toDateTimeString(),
            'revocation_reason' => $this->delegation->revocation_reason,
            'event' => 'delegation_revoked',
        ];
    }
}
