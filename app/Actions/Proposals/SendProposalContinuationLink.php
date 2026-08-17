<?php

namespace App\Actions\Proposals;

use App\Jobs\SendProposalContinuationEmail;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;

class SendProposalContinuationLink
{
    public function __construct(
        protected CreateProposalContinuationAccess $createProposalContinuationAccess,
    ) {}

    public function handle(Proposal $proposal, bool $forceNewAccess = true): ProposalContinuationAccess
    {
        $access = $forceNewAccess ? null : $proposal->continuationAccesses()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $access) {
            ['access' => $access] = $this->createProposalContinuationAccess->handle($proposal);
        }

        if ($access->mail_queued_at === null || $access->mail_failed_at !== null) {
            $access->forceFill([
                'mail_queued_at' => now(),
                'mail_failed_at' => null,
            ])->save();

            try {
                SendProposalContinuationEmail::dispatch($access->id)->afterCommit();
            } catch (\Throwable $exception) {
                $access->forceFill(['mail_failed_at' => now()])->save();

                throw $exception;
            }
        }

        return $access;
    }
}
