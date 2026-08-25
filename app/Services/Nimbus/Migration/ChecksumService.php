<?php

namespace App\Services\Nimbus\Migration;

class ChecksumService
{
    public static function sha256File(string $path): string
    {
        return hash_file('sha256', $path);
    }

    public static function sha256String(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Determine checksum status for a file record.
     *
     * @return array{status: string, legacy_sha256: ?string, actual_sha256: ?string, target_sha256: ?string}
     */
    public static function planStatus(?string $legacyRecorded, ?string $actualSourceSha256, ?string $targetSha256 = null): array
    {
        $legacy = $legacyRecorded !== null ? strtolower(trim($legacyRecorded)) : null;
        $actual = $actualSourceSha256 !== null ? strtolower(trim($actualSourceSha256)) : null;
        $target = $targetSha256 !== null ? strtolower(trim($targetSha256)) : null;

        if ($legacy !== null && $legacy !== '') {
            if ($actual === null) {
                return ['status' => 'UNVERIFIED_SOURCE', 'legacy_sha256' => $legacy, 'actual_source_sha256' => null, 'target_sha256' => $target];
            }
            if ($legacy !== $actual) {
                return ['status' => 'CHECKSUM_MISMATCH', 'legacy_sha256' => $legacy, 'actual_source_sha256' => $actual, 'target_sha256' => $target];
            }
            // legacy == actual
            if ($target !== null && $actual !== $target) {
                return ['status' => 'CHECKSUM_MISMATCH', 'legacy_sha256' => $legacy, 'actual_source_sha256' => $actual, 'target_sha256' => $target];
            }

            return ['status' => 'MATCH', 'legacy_sha256' => $legacy, 'actual_source_sha256' => $actual, 'target_sha256' => $target];
        }

        // No legacy recorded
        if ($actual === null) {
            return ['status' => 'UNVERIFIED_SOURCE', 'legacy_sha256' => null, 'actual_source_sha256' => null, 'target_sha256' => $target];
        }
        if ($target !== null && $actual !== $target) {
            return ['status' => 'CHECKSUM_MISMATCH', 'legacy_sha256' => null, 'actual_source_sha256' => $actual, 'target_sha256' => $target];
        }

        return ['status' => 'MATCH', 'legacy_sha256' => null, 'actual_source_sha256' => $actual, 'target_sha256' => $target];
    }
}
