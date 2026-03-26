<?php

namespace App\Actions\Proposals;

use App\Mail\ProposalContinuationLinkMail;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendProposalContinuationLink
{
    public function __construct(
        protected CreateProposalContinuationAccess $createProposalContinuationAccess,
    ) {}

    public function handle(Proposal $proposal): ProposalContinuationAccess
    {
        ['access' => $access, 'code' => $code] = $this->createProposalContinuationAccess->handle($proposal);

        $continuationUrl = URL::temporarySignedRoute(
            'site.proposal.continuation.access',
            $access->expires_at,
            ['access' => $access],
        );

        Mail::mailer(config('proposals.mail.mailer'))
            ->to($proposal->contact->email)
            ->send(
                new ProposalContinuationLinkMail($proposal, $access, $code, $continuationUrl),
            );

        return $access;
    }
}
