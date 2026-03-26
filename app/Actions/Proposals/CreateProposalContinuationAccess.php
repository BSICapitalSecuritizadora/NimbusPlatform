<?php

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use Illuminate\Support\Facades\Crypt;
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

            $codeLength = max(4, (int) config('proposals.continuation_access.code_length', 6));
            $code = str_pad(
                (string) random_int(0, (10 ** $codeLength) - 1),
                $codeLength,
                '0',
                STR_PAD_LEFT,
            );

            $access = $proposal->continuationAccesses()->create([
                'token' => (string) Str::uuid(),
                'sent_to_email' => $proposal->contact->email,
                'code_hash' => Hash::make($code),
                'code_encrypted' => Crypt::encryptString($code),
                'sent_at' => now(),
                'expires_at' => now()->addDays((int) config('proposals.continuation_access.expires_in_days', 7)),
            ]);

            return [
                'access' => $access,
                'code' => $code,
            ];
        });
    }
}
