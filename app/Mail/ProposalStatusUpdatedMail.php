<?php

namespace App\Mail;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalStatusUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Proposal $proposal,
        public string $status,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('proposals.mail.from.address', config('mail.from.address')),
                config('proposals.mail.from.name', config('mail.from.name')),
            ),
            subject: __('proposals.mail.status_updated_subject', [
                'status' => ProposalStatus::labelFor($this->status),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proposals.status-updated',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
