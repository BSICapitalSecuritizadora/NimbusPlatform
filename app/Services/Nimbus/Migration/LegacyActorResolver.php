<?php

namespace App\Services\Nimbus\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves admin actor with priority:
 * 1. stable oid (if present)
 * 2. normalized email
 * 3. explicit mapping (not implemented — requires external file)
 * 4. unresolved → SYSTEM/null.
 *
 * Never fabricates ADMIN_LEGACY type.
 */
class LegacyActorResolver
{
    public function resolve(?string $legacyAdminId, ?array $legacyAdminRow = null): array
    {
        if ($legacyAdminId === null || $legacyAdminId === '') {
            return $this->unresolved(null, 'no_admin_id');
        }

        // If we have a row with azure_oid / ms_object_id
        if ($legacyAdminRow !== null) {
            $oid = $legacyAdminRow['azure_oid'] ?? $legacyAdminRow['ms_object_id'] ?? $legacyAdminRow['azure_object_id'] ?? null;
            if ($oid !== null && $oid !== '') {
                // Try to find current user by oid (if such column exists)
                // In current NimbusPlatform users table has azure_id? Check if column exists.
                try {
                    $found = DB::table('users')->where('azure_id', $oid)->first();
                    if ($found) {
                        return ['resolved_user_id' => $found->id, 'method' => 'OID', 'legacy' => $legacyAdminRow];
                    }
                } catch (\Throwable $e) {
                    // column may not exist — ignore
                }
            }

            $email = $legacyAdminRow['email'] ?? null;
            if ($email !== null && trim($email) !== '') {
                $normalized = Str::lower(trim($email));
                $found = DB::table('users')->whereRaw('LOWER(email) = ?', [$normalized])->first();
                if ($found) {
                    return ['resolved_user_id' => $found->id, 'method' => 'EMAIL', 'legacy' => $legacyAdminRow];
                }
            }
        }

        // Fallback: try legacy id → user id raw lookup? Explicit mapping not provided → unresolved
        return $this->unresolved($legacyAdminId, 'no_match');
    }

    private function unresolved(?string $legacyId, string $reason): array
    {
        return [
            'resolved_user_id' => null,
            'method' => 'UNRESOLVED',
            'reason' => $reason,
            'legacy_admin_id' => $legacyId,
            // Preserve legacy metadata (email hash only, not raw if not needed)
            'legacy_metadata' => $legacyId !== null ? ['legacy_actor_id' => $legacyId, 'legacy_actor_type' => 'ADMIN'] : null,
        ];
    }
}
