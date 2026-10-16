<?php

namespace App\Services\Bradesco;

use App\Exceptions\Bradesco\BradescoValidationException;
use App\Models\BankAccount;
use App\Support\BankIdentifierMasker;

/**
 * Maps a physical BankAccount to the Bradesco API identity.
 *
 * Manual v2.2.2 takes `agencia` (4 digits, no check digit) and `conta`
 * (7 digits, no check digit) as request params, and echoes
 * `numeroConta` + `digitoConta` in every response section. The check
 * digits never travel on the request; they only verify the answer.
 *
 * Leading zeros are preserved end to end: values stay strings, are
 * never cast to int, and are never padded or truncated. A canonical
 * part that exceeds the manual widths is rejected for human review
 * instead of reshaped into a different account.
 *
 * The Conta Azul UUID is never consulted here: Bradesco identity and
 * Conta Azul financial-account identity are separate concepts.
 */
final class BradescoAccountIdentity
{
    /**
     * @return array{agencia: string, conta: string}
     *
     * @throws BradescoValidationException When the canonical parts fall outside the documented widths.
     */
    public function requestParams(BankAccount $bankAccount): array
    {
        $agency = (string) $bankAccount->agency_number;
        $account = (string) $bankAccount->account_number;

        if (! preg_match('/^\d+$/', $agency) || strlen($agency) > 4) {
            throw BradescoValidationException::invalidIdentity(
                'agência fora do formato documentado (até 4 dígitos sem dígito)'
            );
        }

        if (! preg_match('/^\d+$/', $account) || strlen($account) > 7) {
            throw BradescoValidationException::invalidIdentity(
                'conta fora do formato documentado (até 7 dígitos sem dígito)'
            );
        }

        return ['agencia' => $agency, 'conta' => $account];
    }

    /**
     * Whether the account belongs to a supported API scope.
     *
     * The manual serves checking and savings accounts only, and refuses
     * accounts under financial institution "11-02". Nimbus stores no
     * account-type marker on BankAccount, so this stays a documented
     * checkpoint: callers pass the operator-declared type instead of
     * guessing it from any other identifier.
     *
     * @throws BradescoValidationException When the declared type is outside the documented scope.
     */
    public function assertSupportedStatementType(string $tipo): void
    {
        if (! in_array(strtolower($tipo), ['cc', 'cp'], true)) {
            throw BradescoValidationException::unsupportedAccount(
                "tipo [{$tipo}]; a API atende somente contas correntes (cc) e poupança (cp)"
            );
        }
    }

    /**
     * Verify that a response section echoes the requested account.
     *
     * Returns 'exact' when numeroConta/digitoConta equal the canonical
     * parts byte for byte, 'format_variant' when they agree numerically
     * but differ textually (response zero-padding, e.g. "075557" for a
     * stored "75557"), and 'mismatch' otherwise. Only a mismatch
     * rejects the payload; a variant is reported, never duplicated
     * into a second BankAccount.
     *
     * @param  array<string, mixed>  $section
     * @return 'exact'|'format_variant'|'mismatch'
     */
    public function verifyResponseSection(BankAccount $bankAccount, array $section): string
    {
        $numero = array_key_exists('numeroConta', $section) ? (string) $section['numeroConta'] : null;
        $digito = array_key_exists('digitoConta', $section) ? (string) $section['digitoConta'] : null;

        if ($numero === null || $digito === null) {
            return 'mismatch';
        }

        $storedNumber = (string) $bankAccount->account_number;
        $storedDigit = mb_strtoupper((string) $bankAccount->account_digit);

        if ($numero === $storedNumber && mb_strtoupper($digito) === $storedDigit) {
            return 'exact';
        }

        if (
            $this->numericallyEqual($numero, $storedNumber)
            && mb_strtoupper($digito) === $storedDigit
        ) {
            return 'format_variant';
        }

        return 'mismatch';
    }

    public function maskedLabel(BankAccount $bankAccount): string
    {
        return BankIdentifierMasker::maskedCanonicalKey(
            (int) $bankAccount->bank_id,
            (string) $bankAccount->agency_number,
            (string) $bankAccount->agency_digit,
            (string) $bankAccount->account_number,
            (string) $bankAccount->account_digit
        );
    }

    private function numericallyEqual(string $left, string $right): bool
    {
        if (! preg_match('/^\d+$/', $left) || ! preg_match('/^\d+$/', $right)) {
            return false;
        }

        return ltrim($left, '0') === ltrim($right, '0');
    }
}
