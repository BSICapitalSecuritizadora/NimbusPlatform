<?php

namespace App\Notifications;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Models\Measurement;
use App\Models\ResponsibilityDelegation;
use App\Services\MeasurementWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeasurementSlaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Measurement $measurement,
        public string $alertType, // warning | overdue
        public array $slaContext,
        public ?ResponsibilityDelegation $delegation = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $typeLabel = $this->alertType === 'overdue' ? 'SLA vencido' : 'SLA próximo do vencimento';
        $stage = $this->slaContext['stage'] ?? null;
        $stageLabel = $stage ? (MeasurementWorkflow::STAGE_LABELS[$stage] ?? "Etapa {$stage}") : '—';
        $deadline = $this->slaContext['deadline_at']?->format('d/m/Y') ?? '—';
        $elapsed = $this->slaContext['elapsed_business_days'] ?? '—';
        $operation = $this->measurement->operation;

        $subject = sprintf('[NimbusPlatform] %s — %s (%s)', $typeLabel, $operation?->code ?? 'Operação', $stageLabel);

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Olá, '.($notifiable->name ?? ''))
            ->line("A medição #{$this->measurement->getKey()} referência ".($this->measurement->reference_month?->format('m/Y') ?? '—')." está com {$typeLabel} na etapa {$stageLabel}.")
            ->line('Operação: '.($operation?->code.' — '.$operation?->title ?? '—'))
            ->line("Deadline (dias úteis): {$deadline} — decorrentes: {$elapsed}")
            ->when($this->delegation !== null, fn (MailMessage $mail): MailMessage => $mail->line(
                'Responsabilidade delegada por '.($this->delegation?->delegator?->name ?? 'responsável original')
                .' até '.$this->delegation?->ends_at?->format('d/m/Y H:i').'.',
            ))
            ->action('Ver medição', MeasurementResource::getUrl('view', ['record' => $this->measurement]))
            ->line('Por favor, analise a medição o quanto antes para evitar atraso operacional.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'measurement_id' => $this->measurement->getKey(),
            'operation_id' => $this->measurement->operation_id,
            'stage' => $this->slaContext['stage'] ?? null,
            'alert_type' => $this->alertType,
            'status' => $this->slaContext['status'] ?? null,
            'deadline_at' => $this->slaContext['deadline_at']?->toDateTimeString(),
            'elapsed_business_days' => $this->slaContext['elapsed_business_days'] ?? null,
            'delegated' => $this->delegation !== null,
            'delegation_id' => $this->delegation?->getKey(),
            'delegator_user_id' => $this->delegation?->delegator_user_id,
            'event' => $this->alertType === 'overdue' ? 'sla_overdue' : 'sla_warning',
        ];
    }
}
