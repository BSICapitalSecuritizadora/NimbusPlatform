<?php

namespace App\Support\Bradesco;

use App\Support\Funds\FundAllocationMoney;
use InvalidArgumentException;

/**
 * Exact parsing of Bradesco monetary wire values.
 *
 * The manual types valorLancamento as numeric 13+2 and every example uses
 * the Brazilian print form: dot thousands, comma decimals ("1.280,00",
 * "250,00"). The sign travels separately in sinalSaldo ('+' positive,
 * '-' negative); a blank value or sign means unknown, never zero.
 *
 * Framework-free so unit tests cover it without the application.
 */
final class BradescoMoney
{
    /**
     * Parse one (valorLancamento, sinalSaldo) pair into signed cents.
     *
     * @throws InvalidArgumentException When the value or sign contradicts the documented form.
     */
    public static function toSignedCents(string $valorLancamento, string $sinalSaldo): int
    {
        $value = trim($valorLancamento);
        $sign = trim($sinalSaldo);

        if ($sign !== '+' && $sign !== '-') {
            throw new InvalidArgumentException("Sinal de saldo inválido: '{$sinalSaldo}'. O manual v2.2.2 admite apenas '+' e '-'.");
        }

        if (! preg_match('/^(?:\d{1,3}(?:\.\d{3})*|\d+),\d{2}$/', $value)) {
            throw new InvalidArgumentException("Valor de lançamento inválido: '{$valorLancamento}'. Esperado o formato 13+2 com ponto de milhar e vírgula decimal.");
        }

        $normalized = str_replace('.', '', $value);
        $normalized = str_replace(',', '.', $normalized);

        $cents = FundAllocationMoney::toCents($normalized);

        return $sign === '-' ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        return FundAllocationMoney::fromCents($cents);
    }
}
