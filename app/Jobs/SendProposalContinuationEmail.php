<?php

namespace App\Jobs;

use App\Mail\ProposalContinuationLinkMail;
use App\Models\ProposalContinuationAccess;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendProposalContinuationEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 1800];

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $accessId) {}

    public function uniqueId(): string
    {
        return (string) $this->accessId;
    }

    public function handle(): void
    {
        $access = ProposalContinuationAccess::query()
            ->with(['proposal.company', 'proposal.contact'])
            ->find($this->accessId);

        if (! $access || ! $access->isActive() || $access->sent_at !== null) {
            return;
        }

        $code = $access->decrypted_code;
        $email = $access->sent_to_email;

        if (! $access->proposal || ! $code || ! $email) {
            throw new \RuntimeException('Os dados necessários para o e-mail de continuação não estão disponíveis.');
        }

        Mail::mailer(config('proposals.mail.mailer'))
            ->to($email)
            ->send(new ProposalContinuationLinkMail(
                $access->proposal,
                $access,
                $code,
                $access->generated_url,
            ));

        $access->forceFill([
            'sent_at' => now(),
            'mail_failed_at' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        ProposalContinuationAccess::query()
            ->whereKey($this->accessId)
            ->update(['mail_failed_at' => now()]);

        Log::error('Falha definitiva no envio do e-mail de continuação da proposta.', [
            'access_id' => $this->accessId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
