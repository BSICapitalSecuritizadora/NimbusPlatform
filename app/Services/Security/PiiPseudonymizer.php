<?php

namespace App\Services\Security;

use Illuminate\Support\Str;

/**
 * Turns personal data into stable, non-reversible identifiers so failures can be
 * correlated in the logs without persisting e-mails, documents or phone numbers.
 *
 * The token is an HMAC keyed by the application key: the same input always yields
 * the same token, but the original value cannot be recovered from the log.
 */
class PiiPseudonymizer
{
    private const TOKEN_LENGTH = 16;

    public static function email(?string $email): ?string
    {
        return self::token(Str::lower(trim((string) $email)));
    }

    /**
     * For CPF/CNPJ and other numeric identifiers, so formatted and unformatted
     * values produce the same token.
     */
    public static function document(?string $document): ?string
    {
        return self::token(Str::digitsOnly((string) $document));
    }

    public static function value(?string $value): ?string
    {
        return self::token(trim((string) $value));
    }

    private static function token(string $normalizedValue): ?string
    {
        if ($normalizedValue === '') {
            return null;
        }

        $digest = hash_hmac('sha256', $normalizedValue, (string) config('app.key'));

        return 'pii_'.substr($digest, 0, self::TOKEN_LENGTH);
    }
}
