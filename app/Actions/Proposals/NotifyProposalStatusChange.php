<?php

namespace App\Actions\Proposals;

use App\Mail\ProposalStatusUpdatedMail;
use App\Models\Proposal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyProposalStatusChange
{
    public function __construct(
        protected SendProposalContinuationLink $sendProposalContinuationLink,
    ) {}

    public function handle(Proposal $proposal, string $newStatus): void
    {
        try {
            $proposal->loadMissing(['company', 'contact', 'latestContinuationAccess']);

            if (blank($proposal->contact?->email)) {
                return;
            }

            if ($newStatus === Proposal::STATUS_AWAITING_INFORMATION) {
                $this->sendProposalContinuationLink->handle($proposal);

                return;
            }

            if (! in_array($newStatus, [
                Proposal::STATUS_APPROVED,
                Proposal::STATUS_REJECTED,
                Proposal::STATUS_COMPLETED,
            ], true)) {
                return;
            }

            Mail::mailer(config('proposals.mail.mailer'))
                ->to($proposal->contact->email)
                ->send(new ProposalStatusUpdatedMail($proposal, $newStatus));
        } catch (\Throwable $exception) {
            Log::warning('Falha ao notificar alteração de status da proposta.', [
                'proposal_id' => $proposal->id,
                'status' => $newStatus,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
