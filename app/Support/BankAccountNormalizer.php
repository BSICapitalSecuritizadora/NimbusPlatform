<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Canonical parsing and formatting for physical bank account identifiers.
 *
 * Phase 1A foundation for global bank accounts. It turns the free-text agency
 * and account values operators type (e.g. "1234-5", "0012345-6") into explicit
 * number/check-digit pairs stored as strings, so leading zeros survive and no
 * database driver can disagree about identity the way it could with integers.
 *
 * Parsing rules (shared by agency and account, only the accepted number
 * lengths differ):
 *
 * - Surrounding whitespace is ignored; inner whitespace around the separator
 *   or inside the digit run is tolerated ("1234 - 5", "12 345-6").
 * - The number and the check digit must be joined by exactly one separator
 *   from `-`, `/` or `.`. A value without a separator ("123456") is rejected
 *   instead of guessed, and so is a value with two separators ("1.234-5"),
 *   which is ambiguous with thousand-grouped input. Callers holding grouped
 *   values must strip the grouping before parsing.
 * - The number part holds digits only; the check digit is a single character
 *   from 0-9 or X (uppercased when stored, so "x" and "X" key to one account).
 * - Accepted lengths are deliberately wider than any single bank -- agency
 *   numbers 1-6 digits, account numbers 4-12 digits -- so no Bradesco-specific
 *   length leaks into the global rule. Legacy fund values (agency 4 digits,
 *   account 5-9 digits) fall inside these ranges.
 *
 * Known limitation: banks whose accounts carry no check digit cannot be
 * represented yet, because the canonical unique key always includes the
 * digit. Phase 1B diagnostics must flag any such account instead of forcing
 * it through this parser.
 *
 * This class never touches the database and never rewrites stored values; it
 * only parses, validates and formats. The future Phase 1B migration reuses it
 * to group legacy funds by physical account.
 */
final class BankAccountNormalizer
{
    /**
     * Separators accepted between the number and the check digit.
     *
     * @var list<string>
     */
    private const SEPARATORS = ['-', '/', '.'];

    private const AGENCY_NUMBER_MIN_LENGTH = 1;

    private const AGENCY_NUMBER_MAX_LENGTH = 6;

    private const ACCOUNT_NUMBER_MIN_LENGTH = 4;

    private const ACCOUNT_NUMBER_MAX_LENGTH = 12;

    /**
     * Parse a free-text agency (e.g. "1234-5") into its canonical parts.
     *
     * @return array{number: string, digit: string}
     *
     * @throws InvalidArgumentException When the value is malformed or ambiguous.
     */
    public static function parseAgency(string $input): array
    {
        return self::parse(
            $input,
            self::AGENCY_NUMBER_MIN_LENGTH,
            self::AGENCY_NUMBER_MAX_LENGTH,
            'agência'
        );
    }

    /**
     * Parse a free-text account (e.g. "0012345-6") into its canonical parts.
     *
     * @return array{number: string, digit: string}
     *
     * @throws InvalidArgumentException When the value is malformed or ambiguous.
     */
    public static function parseAccount(string $input): array
    {
        return self::parse(
            $input,
            self::ACCOUNT_NUMBER_MIN_LENGTH,
            self::ACCOUNT_NUMBER_MAX_LENGTH,
            'conta'
        );
    }

    /**
     * Format canonical agency parts for display ("1234" + "5" => "1234-5").
     *
     * @throws InvalidArgumentException When the parts are not canonical.
     */
    public static function formatAgency(string $number, string $digit): string
    {
        self::assertCanonicalParts(
            $number,
            $digit,
            self::AGENCY_NUMBER_MIN_LENGTH,
            self::AGENCY_NUMBER_MAX_LENGTH,
            'agência'
        );

        return $number.'-'.mb_strtoupper($digit);
    }

    /**
     * Format canonical account parts for display.
     *
     * @throws InvalidArgumentException When the parts are not canonical.
     */
    public static function formatAccount(string $number, string $digit): string
    {
        self::assertCanonicalParts(
            $number,
            $digit,
            self::ACCOUNT_NUMBER_MIN_LENGTH,
            self::ACCOUNT_NUMBER_MAX_LENGTH,
            'conta'
        );

        return $number.'-'.mb_strtoupper($digit);
    }

    /**
     * Normalize a bank-reported product code, preserving its exact digits.
     *
     * Only surrounding whitespace is removed: the code keeps leading zeros,
     * inner spacing and casing untouched, because no rule in this domain says
     * two differently-cased codes are the same product.
     *
     * @throws InvalidArgumentException When the code is blank.
     */
    public static function normalizeCodigoProduto(string $input): string
    {
        $code = self::trimUnicode($input);

        if ($code === '') {
            throw new InvalidArgumentException('O código do produto não pode estar em branco.');
        }

        return $code;
    }

    /**
     * Deterministic grouping key for one physical account.
     *
     * Mirrors the unique key on bank_accounts (bank + agency parts + account
     * parts) in a single string, so Phase 1B diagnostics can group legacy
     * funds without reimplementing the rule. It is a grouping helper only;
     * uniqueness itself is enforced by the database constraint.
     */
    public static function canonicalKey(
        int $bankId,
        string $agencyNumber,
        string $agencyDigit,
        string $accountNumber,
        string $accountDigit
    ): string {
        return implode(':', [
            $bankId,
            $agencyNumber,
            mb_strtoupper($agencyDigit),
            $accountNumber,
            mb_strtoupper($accountDigit),
        ]);
    }

    /**
     * @return array{number: string, digit: string}
     */
    private static function parse(string $input, int $minLength, int $maxLength, string $label): array
    {
        $value = self::trimUnicode($input);

        if ($value === '') {
            throw new InvalidArgumentException("A {$label} não pode estar em branco.");
        }

        $separatorCount = 0;

        foreach (self::SEPARATORS as $separator) {
            $separatorCount += substr_count($value, $separator);
        }

        if ($separatorCount !== 1) {
            throw new InvalidArgumentException(
                "A {$label} deve trazer número e dígito separados por um único separador (-, / ou ponto)."
            );
        }

        $normalized = str_replace(self::SEPARATORS, '-', $value);
        [$number, $digit] = explode('-', $normalized, 2);

        $number = (string) preg_replace('/\s+/u', '', $number);
        $digit = self::trimUnicode($digit);

        self::assertCanonicalParts($number, $digit, $minLength, $maxLength, $label);

        return ['number' => $number, 'digit' => mb_strtoupper($digit)];
    }

    private static function assertCanonicalParts(
        string $number,
        string $digit,
        int $minLength,
        int $maxLength,
        string $label
    ): void {
        if (! preg_match('/^\d+$/', $number)) {
            throw new InvalidArgumentException("A {$label} deve conter somente dígitos antes do separador.");
        }

        $length = strlen($number);

        if (($length < $minLength) || ($length > $maxLength)) {
            throw new InvalidArgumentException(
                "A {$label} deve ter entre {$minLength} e {$maxLength} dígitos antes do separador."
            );
        }

        if (! preg_match('/^[0-9Xx]$/', $digit)) {
            throw new InvalidArgumentException("O dígito da {$label} deve ser um único caractere (0-9 ou X).");
        }
    }

    private static function trimUnicode(string $value): string
    {
        return (string) preg_replace('/^\s+|\s+$/u', '', $value);
    }
}
