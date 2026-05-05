<?php

namespace App\Mail\Nimbus;

use App\Models\Nimbus\PortalUser;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendPortalAccessCode extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PortalUser $user,
        public string $code,
        public string $accessUrl,
        public ?CarbonInterface $expiresAt = null,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('nimbus.mail.from.address', config('mail.from.address')),
                (string) config('nimbus.mail.from.name', config('mail.from.name')),
            ),
            subject: 'Seu Código de Acesso ao Portal - BSI Capital',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.nimbus.access-code',
            with: [
                'user' => $this->user,
                'code' => $this->code,
                'accessUrl' => $this->accessUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
