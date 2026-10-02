<?php

namespace App\Enums;

use App\Support\Imports\SpreadsheetAmount;

/**
 * Por que um valor escrito como texto na planilha admite duas leituras.
 *
 * Célula numérica nunca é ambígua: o número que ela guarda é o valor. Texto com
 * um só separador seguido de exatamente três dígitos ("553,919", "1.500") pode
 * ser milhar ou decimal, e a leitura escolhida por {@see SpreadsheetAmount} vem
 * acompanhada da alternativa, para que a conferência mostre as duas.
 */
enum AmountTextAmbiguity: string
{
    /**
     * Só vírgula: lida como decimal, a convenção pt-BR ("553,919" é R$ 553,92).
     */
    case CommaAsDecimal = 'virgula_decimal';

    /**
     * Só um ponto, com 1 a 3 dígitos antes e 3 depois: lido como milhar
     * ("1.500" é mil e quinhentos), a forma mais comum de escrever o valor.
     */
    case DotAsThousands = 'ponto_milhar';

    public function label(): string
    {
        return match ($this) {
            self::CommaAsDecimal => 'vírgula como separador decimal',
            self::DotAsThousands => 'ponto como separador de milhar',
        };
    }
}
