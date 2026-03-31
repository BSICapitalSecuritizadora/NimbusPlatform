<?php

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Mail\ProposalStatusUpdatedMail;
use App\Models\Proposal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyProposalStatusChange
{
    public function __construct(
        protected SendProposalContinuationLink $sendProposalContinuationLink,
    ) {}

    public function handle(Proposal $proposal, ProposalStatus|string $newStatus): void
    {
        $newStatus = ProposalStatus::fromValue($newStatus);

        if (! $newStatus) {
            return;
        }

        try {
            $proposal->loadMissing(['company', 'contact', 'latestContinuationAccess']);

            if (blank($proposal->contact?->email)) {
                return;
            }

            if ($newStatus === ProposalStatus::AwaitingInformation) {
                $this->sendProposalContinuationLink->handle($proposal);

                return;
            }

            if (! in_array($newStatus, [
                ProposalStatus::Approved,
                ProposalStatus::Rejected,
                ProposalStatus::Completed,
            ], true)) {
                return;
            }

            Mail::mailer(config('proposals.mail.mailer'))
                ->to($proposal->contact->email)
                ->send(new ProposalStatusUpdatedMail($proposal, $newStatus->value));
        } catch (\Throwable $exception) {
            Log::warning('Falha ao notificar alteração de status da proposta.', [
                'proposal_id' => $proposal->id,
                'status' => $newStatus->value,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
