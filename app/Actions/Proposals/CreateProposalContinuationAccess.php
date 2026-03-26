<?php

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateProposalContinuationAccess
{
    /**
     * @return array{access: ProposalContinuationAccess, code: string}
     */
    public function handle(Proposal $proposal): array
    {
        return DB::transaction(function () use ($proposal): array {
            $proposal->continuationAccesses()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $code = (string) random_int(100000, 999999);

            $access = $proposal->continuationAccesses()->create([
                'token' => (string) Str::uuid(),
                'sent_to_email' => $proposal->contact->email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addDays(7),
            ]);

            return [
                'access' => $access,
                'code' => $code,
            ];
        });
    }
}
