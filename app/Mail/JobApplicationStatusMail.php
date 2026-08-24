<?php

namespace App\Mail;

use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JobApplicationStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public JobApplication $application,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->application->status) {
            JobApplication::STATUS_HIRED => 'BSI Capital — Processo seletivo: candidatura contratada',
            JobApplication::STATUS_REJECTED => 'BSI Capital — Processo seletivo: atualização da candidatura',
            default => 'BSI Capital — Atualização da sua candidatura',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.status-updated');
    }

    public function attachments(): array
    {
        return [];
    }
}
