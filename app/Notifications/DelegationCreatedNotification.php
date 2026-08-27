<?php

namespace App\Notifications;

use App\Models\ResponsibilityDelegation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DelegationCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ResponsibilityDelegation $delegation) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $delegator = $this->delegation->delegator;
        $scope = $this->delegation->scope_type === 'global'
            ? 'todas as responsabilidades'
            : ($this->delegation->scope_type === 'operation'
                ? 'operação '.($this->delegation->scopeOperation?->code ?? '#'.$this->delegation->scope_operation_id)
                : 'etapa '.(ResponsibilityDelegation::STAGE_OPTIONS[$this->delegation->scope_stage] ?? $this->delegation->scope_stage));

        return (new MailMessage)
            ->subject('[NimbusPlatform] Nova delegação de responsabilidade')
            ->greeting('Olá, '.($notifiable->name ?? ''))
            ->line('Você recebeu uma delegação de '.($delegator?->name ?? 'um responsável').' para '.$scope.'.')
            ->when(filled($this->delegation->scope_responsibility_label), fn (MailMessage $mail): MailMessage => $mail
                ->line('Responsabilidade: '.$this->delegation->scope_responsibility_label.'.'))
            ->line('Período: '.$this->delegation->starts_at->format('d/m/Y H:i').' até '.$this->delegation->ends_at->format('d/m/Y H:i').'.')
            ->line('Motivo: '.$this->delegation->reason)
            ->action('Ver delegações', url('/admin/responsibility-delegations'))
            ->line('A delegação ficará ativa automaticamente no período informado.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'delegation_id' => $this->delegation->getKey(),
            'delegator_user_id' => $this->delegation->delegator_user_id,
            'scope_type' => $this->delegation->scope_type,
            'scope_operation_id' => $this->delegation->scope_operation_id,
            'scope_stage' => $this->delegation->scope_stage,
            'scope_responsibility' => $this->delegation->scope_responsibility,
            'starts_at' => $this->delegation->starts_at->toDateTimeString(),
            'ends_at' => $this->delegation->ends_at->toDateTimeString(),
            'event' => 'delegation_created',
        ];
    }
}
