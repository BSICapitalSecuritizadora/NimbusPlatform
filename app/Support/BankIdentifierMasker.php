<?php

namespace App\Support;

/**
 * Masking for bank identifiers in diagnostic output.
 *
 * Phase 1B.1 diagnostics must never print full agency/account numbers or
 * Conta Azul identifiers: reports are copied into tickets and chat, where a
 * full identifier lives forever. Every masked value keeps just enough shape
 * (length, last digits) for a human to reconcile a row against the masked
 * group it belongs to, and the fund id next to it is the exact locator.
 *
 * Pure functions only: no database, no configuration, deterministic output.
 */
final class BankIdentifierMasker
{
    /**
     * Mask a canonical number, keeping the last two digits visible.
     *
     * "0012345" becomes "*****45", preserving the length (so a zero-padded
     * value is still visibly longer) without revealing which leading digits
     * are zeros.
     */
    public static function maskNumber(string $number): string
    {
        $length = strlen($number);

        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 2).substr($number, -2);
    }

    /**
     * Mask a canonical agency ("1234" + "5" => "***34-5").
     *
     * The check digit stays visible: it is a single character, useless on its
     * own, but it disambiguates review candidates that share a tail.
     */
    public static function maskAgency(string $number, string $digit): string
    {
        return self::maskNumber($number).'-'.mb_strtoupper($digit);
    }

    /**
     * Mask a canonical account ("0012345" + "6" => "*****45-6").
     */
    public static function maskAccount(string $number, string $digit): string
    {
        return self::maskNumber($number).'-'.mb_strtoupper($digit);
    }

    /**
     * Mask a strict canonical group key for review listings.
     *
     * The bank id stays visible (a foreign key, not an identifier); agency
     * and account parts are masked.
     */
    public static function maskedCanonicalKey(
        int $bankId,
        string $agencyNumber,
        string $agencyDigit,
        string $accountNumber,
        string $accountDigit
    ): string {
        return sprintf(
            '%d:%s:%s',
            $bankId,
            self::maskAgency($agencyNumber, $agencyDigit),
            self::maskAccount($accountNumber, $accountDigit)
        );
    }

    /**
     * Mask a raw legacy value that failed to parse.
     *
     * Malformed input is operator-typed free text, so nothing about its
     * content is trusted: only the length and the last two characters are
     * shown, which is enough to tell two blocked rows apart.
     */
    public static function maskRaw(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '(blank)';
        }

        $normalized = trim($value);

        return self::maskNumber($normalized).' ('.strlen($normalized).' chars)';
    }

    /**
     * Mask a Conta Azul identifier, keeping the first two and last two
     * characters visible.
     */
    public static function maskContaAzulId(?string $identifier): string
    {
        if ($identifier === null || trim($identifier) === '') {
            return '(none)';
        }

        $normalized = trim($identifier);
        $length = strlen($normalized);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($normalized, 0, 2).str_repeat('*', $length - 4).substr($normalized, -2);
    }
}
