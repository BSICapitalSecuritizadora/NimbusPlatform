<?php

namespace App\Mail\Nimbus;

use App\Models\Nimbus\PortalUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendPortalAccessCode extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public PortalUser $user;

    public string $code;

    /**
     * Create a new message instance.
     */
    public function __construct(PortalUser $user, string $code)
    {
        $this->user = $user;
        $this->code = $code;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
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
