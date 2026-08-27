<?php

namespace App\Notifications;

use App\Models\ResponsibilityDelegation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DelegationExpiringNotification extends Notification implements ShouldQueue
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
            ->subject('[NimbusPlatform] Delegação próxima do vencimento')
            ->greeting('Olá, '.($notifiable->name ?? ''))
            ->line('A delegação #'.$this->delegation->getKey().' de '.($this->delegation->delegator?->name ?? '—').' para '.($this->delegation->delegate?->name ?? '—').' expira em '.$this->delegation->ends_at->format('d/m/Y H:i').'.')
            ->line('Escopo: '.(ResponsibilityDelegation::SCOPE_OPTIONS[$this->delegation->scope_type] ?? $this->delegation->scope_type))
            ->when(filled($this->delegation->scope_responsibility_label), fn (MailMessage $mail): MailMessage => $mail
                ->line('Responsabilidade: '.$this->delegation->scope_responsibility_label.'.'))
            ->line('Motivo: '.$this->delegation->reason)
            ->action('Ver delegações', url('/admin/responsibility-delegations'))
            ->line('Crie uma nova delegação caso a cobertura precise continuar.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'delegation_id' => $this->delegation->getKey(),
            'delegator_user_id' => $this->delegation->delegator_user_id,
            'delegate_user_id' => $this->delegation->delegate_user_id,
            'ends_at' => $this->delegation->ends_at->toDateTimeString(),
            'event' => 'delegation_expiring',
        ];
    }
}
