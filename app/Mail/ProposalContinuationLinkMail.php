<?php

namespace App\Mail;

use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalContinuationLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Proposal $proposal,
        public ProposalContinuationAccess $access,
        public string $code,
        public string $continuationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('proposals.mail.from.address', config('mail.from.address')),
                config('proposals.mail.from.name', config('mail.from.name')),
            ),
            subject: 'Continue o preenchimento da sua proposta',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proposals.continuation-link',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
