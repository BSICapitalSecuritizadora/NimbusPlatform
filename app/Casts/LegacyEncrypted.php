<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Transitional encrypted cast that reads legacy plaintext rows.
 *
 * New writes are always encrypted via Crypt::encryptString.
 * Reads distinguish:
 *  - valid ciphertext → decrypts
 *  - known legacy plaintext (strict format) → returns plaintext for compatibility
 *  - corrupted/malformed ciphertext or arbitrary string → fails safely (DecryptException)
 *
 * Existing unprefixed ciphertexts (Phase B) are backward-compatible: they are
 * recognized as ciphertext-like and decrypted via Crypt.
 */
class LegacyEncrypted implements CastsAttributes
{
    /**
     * @param  mixed  $value  Raw DB value (ciphertext, legacy plaintext, or corrupt)
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $raw = (string) $value;

        // Try decrypt first — valid ciphertext (with or without prefix) will succeed.
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            // Decrypt failed. Determine if this was intended to be ciphertext.
            if (self::looksLikeCiphertext($raw)) {
                // Ciphertext corruption / key mismatch / truncation.
                // Do NOT return raw value as if it were PII.
                throw new DecryptException('Unable to decrypt Nimbus PII attribute ['.$key.'] — possible corruption or key mismatch.');
            }

            // Not ciphertext-like: check if it matches known legacy plaintext format.
            if (self::looksLikeLegacyPlaintext($key, $raw)) {
                return $raw;
            }

            // Arbitrary malformed string — fail safely, do not trust.
            throw new DecryptException('Unable to decrypt Nimbus PII attribute ['.$key.'] — malformed value.');
        }
    }

    /**
     * @param  mixed  $value  Plaintext from application (digits or null)
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    /**
     * Does the string look like Laravel ciphertext?
     * Laravel encryptString produces base64(JSON{iv,value,mac,tag}).
     */
    private static function looksLikeCiphertext(string $value): bool
    {
        // Ciphertext is base64, relatively long, and decodes to JSON with expected keys.
        // Use strict base64 check and JSON structure.
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $data = json_decode($decoded, true);

        if (! is_array($data)) {
            return false;
        }

        // Laravel payload always has at least iv, value, mac
        return isset($data['iv'], $data['value'], $data['mac']);
    }

    /**
     * Strict legacy-format recognition — only historically accepted representations.
     */
    private static function looksLikeLegacyPlaintext(string $key, string $value): bool
    {
        $trimmed = trim($value);

        if ($key === 'document_number') {
            // CPF: 11 digits, optionally formatted as 000.000.000-00
            // Accept digitsOnly 11 and original contains only digits, dots, dashes, spaces
            if (! preg_match('/^[\d.\-\s]+$/', $trimmed)) {
                return false;
            }
            $digits = preg_replace('/\D+/', '', $trimmed);

            return $digits !== '' && strlen($digits) === 11;
        }

        if ($key === 'phone_number') {
            // Phone: 10 or 11 digits, optionally formatted as (00) 0000-0000 or (00) 00000-0000
            if (! preg_match('/^[\d\(\)\-\s]+$/', $trimmed)) {
                return false;
            }
            $digits = preg_replace('/\D+/', '', $trimmed);

            return $digits !== '' && (strlen($digits) === 10 || strlen($digits) === 11);
        }

        return false;
    }
}
