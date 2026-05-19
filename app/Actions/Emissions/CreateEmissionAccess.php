<?php

namespace App\Actions\Emissions;

use App\Models\Emission;
use App\Models\EmissionAccess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateEmissionAccess
{
    /**
     * @param  array{name:string,email:string,phone:string}  $payload
     * @return array{access: EmissionAccess, code: string}
     */
    public function handle(Emission $emission, array $payload): array
    {
        return DB::transaction(function () use ($emission, $payload): array {
            $normalizedEmail = mb_strtolower(trim($payload['email']));

            $emission->accesses()
                ->where('requester_email', $normalizedEmail)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $codeLength = max(4, (int) config('emissions.access.code_length', 6));
            $code = str_pad(
                (string) random_int(0, (10 ** $codeLength) - 1),
                $codeLength,
                '0',
                STR_PAD_LEFT,
            );

            $access = $emission->accesses()->create([
                'token' => (string) Str::uuid(),
                'requester_name' => trim($payload['name']),
                'requester_email' => $normalizedEmail,
                'requester_phone' => trim($payload['phone']),
                'code_hash' => Hash::make($code),
                'code_encrypted' => Crypt::encryptString($code),
                'sent_at' => now(),
                'expires_at' => now()->addDays((int) config('emissions.access.expires_in_days', 7)),
            ]);

            return [
                'access' => $access,
                'code' => $code,
            ];
        });
    }
}
