<?php

namespace App\Mail;

use App\Models\Emission;
use App\Models\EmissionAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmissionAccessCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Emission $emission,
        public EmissionAccess $access,
        public string $code,
        public string $accessUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('nimbus.mail.from.address', config('mail.from.address')),
                (string) config('nimbus.mail.from.name', config('mail.from.name')),
            ),
            subject: 'Seu código de acesso à operação - BSI Capital',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.emissions.access-code',
            with: [
                'emission' => $this->emission,
                'access' => $this->access,
                'code' => $this->code,
                'accessUrl' => $this->accessUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
