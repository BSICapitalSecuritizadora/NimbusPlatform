<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * Centralized blind-index (HMAC) service for PII exact-match lookups.
 *
 * Design:
 *  - HMAC-SHA256 with domain separation (purpose + version) so document_number
 *    and phone_number hashes are not interchangeable.
 *  - Secret is dedicated NIMBUS_PII_BLIND_INDEX_KEY (config('nimbus.pii_blind_index_key')),
 *    independent from APP_KEY rotation. APP_KEY still encrypts payloads via Crypt.
 *  - Version suffix `v1` baked into context. Future rotation introduces `v2`.
 *
 * Key separation:
 *  - APP_KEY → Laravel Crypt (encrypted columns)
 *  - NIMBUS_PII_BLIND_INDEX_KEY → HMAC blind indexes
 *  Changing APP_KEY does NOT change blind indexes.
 *
 * Fail-closed:
 *  - If dedicated key is missing/empty, throws LogicException with clear message
 *    (does not fallback to APP_KEY or empty string).
 */
class BlindIndexService
{
    public const VERSION = 'v1';

    /**
     * Derive a blind index for a normalized value under a purpose.
     * Uses dedicated blind-index key.
     *
     * @throws LogicException if key not configured
     */
    public static function for(string $purpose, ?string $normalizedValue): ?string
    {
        $normalized = trim((string) $normalizedValue);

        if ($normalized === '') {
            return null;
        }

        $keyMaterial = (string) Config::get('nimbus.pii_blind_index_key');

        if ($keyMaterial === '' || $keyMaterial === null) {
            throw new LogicException(
                'NIMBUS_PII_BLIND_INDEX_KEY is not configured. Set NIMBUS_PII_BLIND_INDEX_KEY in environment to enable Nimbus PII blind indexes.'
            );
        }

        $version = (string) Config::get('nimbus.pii_blind_index_version', self::VERSION);
        $context = "nimbus:pii:{$purpose}:{$version}";

        return hash_hmac('sha256', $normalized, $keyMaterial.$context);
    }

    /**
     * Legacy derivation using APP_KEY — for transition detection only.
     * Existing Phase B rows were hashed with APP_KEY; after migration to dedicated
     * key they are stale and need recompute. Do not use for new writes.
     */
    public static function legacyFor(string $purpose, ?string $normalizedValue): ?string
    {
        $normalized = trim((string) $normalizedValue);

        if ($normalized === '') {
            return null;
        }

        $keyMaterial = (string) Config::get('app.key');
        $context = "nimbus:pii:{$purpose}:".self::VERSION;

        return hash_hmac('sha256', $normalized, $keyMaterial.$context);
    }

    public static function documentNumber(?string $rawValue): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $rawValue);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return self::for('document_number', $digits);
    }

    public static function phoneNumber(?string $rawValue): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $rawValue);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return self::for('phone_number', $digits);
    }

    public static function legacyDocumentNumber(?string $rawValue): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $rawValue);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return self::legacyFor('document_number', $digits);
    }

    public static function legacyPhoneNumber(?string $rawValue): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $rawValue);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return self::legacyFor('phone_number', $digits);
    }
}
