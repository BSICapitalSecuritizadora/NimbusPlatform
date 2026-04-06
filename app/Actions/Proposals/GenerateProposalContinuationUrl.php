<?php

namespace App\Actions\Proposals;

use App\Models\ProposalContinuationAccess;
use Illuminate\Support\Facades\URL;

class GenerateProposalContinuationUrl
{
    public static function for(ProposalContinuationAccess $access): string
    {
        return URL::temporarySignedRoute(
            'site.proposal.continuation.access',
            $access->expires_at,
            ['access' => $access],
        );
    }
}
