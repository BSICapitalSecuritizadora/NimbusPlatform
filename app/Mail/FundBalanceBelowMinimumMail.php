<?php

namespace App\Mail;

use App\Models\Fund;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FundBalanceBelowMinimumMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Fund $fund,
        public CarbonInterface $checkedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'hello@example.com'),
                config('mail.from.name', 'BSI Capital'),
            ),
            subject: sprintf(
                'Saldo abaixo do mínimo (%s - Conta %s)',
                (string) ($this->fund->emission?->name ?? 'Emissao nao informada'),
                (string) $this->fund->account,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.funds.balance-below-minimum',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
