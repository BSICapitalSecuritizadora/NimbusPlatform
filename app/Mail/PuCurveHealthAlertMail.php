<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PuCurveHealthAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $issues
     * @param  array<string, int>  $queueMetrics
     * @param  array<string, int>  $statusCounts
     */
    public function __construct(
        public array $issues,
        public array $queueMetrics,
        public array $statusCounts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'hello@example.com'),
                config('mail.from.name', 'BSI Capital'),
            ),
            subject: 'Alerta operacional — Calculadora de PU',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pu.curve-health-alert',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
