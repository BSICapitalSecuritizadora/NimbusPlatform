<?php

namespace App\Mail\Nimbus;

use App\Models\Nimbus\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NimbusCorrectionResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Submission $submission,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('nimbus.mail.from.address', config('mail.from.address')),
                (string) config('nimbus.mail.from.name', config('mail.from.name')),
            ),
            subject: 'Resposta de correção recebida — '.$this->submission->reference_code,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.nimbus.correction-response',
            with: [
                'submission' => $this->submission,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
