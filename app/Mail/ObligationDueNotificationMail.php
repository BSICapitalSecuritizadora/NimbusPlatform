<?php

namespace App\Mail;

use App\Models\Obligation;
use App\Models\ObligationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ObligationDueNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Obligation $obligation,
        public string $milestone,
        public string $notificationType,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        $emissionName = (string) ($this->obligation->emission?->name ?? 'Emissão não informada');

        $subject = match ($this->notificationType) {
            ObligationNotification::TYPE_DUE_TODAY => "Obrigação vence hoje — {$emissionName}",
            ObligationNotification::TYPE_OVERDUE => "Obrigação vencida — {$emissionName}",
            default => "Obrigação próxima do vencimento — {$emissionName}",
        };

        return new Envelope(
            from: new Address(
                config('mail.from.address', 'hello@example.com'),
                config('mail.from.name', 'BSI Capital'),
            ),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.obligations.due-notification',
            with: [
                'obligation' => $this->obligation,
                'emission' => $this->obligation->emission,
                'notificationType' => $this->notificationType,
                'actionUrl' => $this->actionUrl,
                'headline' => match ($this->notificationType) {
                    ObligationNotification::TYPE_DUE_TODAY => 'Obrigação vence hoje',
                    ObligationNotification::TYPE_OVERDUE => 'Obrigação vencida',
                    default => 'Obrigação próxima do vencimento',
                },
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
