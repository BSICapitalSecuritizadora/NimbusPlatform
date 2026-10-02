<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Enums\AmountTextAmbiguity;
use App\Support\Money\IntegerMoney;

/**
 * Leitura do valor de uma célula de planilha de importação, em centavos.
 *
 * A mesma para parcelas, contratos, unidades e valores de unidade -- e para a
 * coluna de desconto que vier depois. As regras:
 *
 * - célula numérica: o número que ela guarda, exato, via {@see IntegerMoney};
 *   nunca ambígua;
 * - texto só com vírgula: a vírgula é decimal, como se escreve em pt-BR. Antes,
 *   "553,919" virava R$ 553.919,00 -- mil vezes o valor -- em silêncio;
 * - texto com um ponto, 1 a 3 dígitos antes e exatamente 3 depois ("1.500"):
 *   continua milhar, a forma comum de escrever mil e quinhentos;
 * - dois separadores: vale o mais à direita como decimal ("1.553,92",
 *   "1,553.92"); vários grupos de milhar ("1.000.000", "1,000,000") e ponto com
 *   outra quantidade de dígitos ("10000.00", "1553.919") não deixam dúvida.
 *
 * Quando a leitura escolhida tem uma alternativa plausível -- só vírgula ou só
 * ponto, com 1 a 3 dígitos antes e 3 depois --, ela é marcada como ambígua e a
 * alternativa vai junto: a conferência mostra as duas e diz como escrever a que
 * não foi escolhida. {@see IntegerMoney} não muda: formulários e cálculo
 * continuam lendo como sempre leram.
 */
final readonly class SpreadsheetAmount
{
    private function __construct(
        public ?int $cents,
        public string $raw,
        public ?AmountTextAmbiguity $ambiguity = null,
        public ?int $alternativeCents = null,
    ) {}

    public static function read(mixed $cell): self
    {
        if (is_int($cell) || is_float($cell)) {
            return new self(IntegerMoney::cents($cell), self::describeNumber($cell));
        }

        if (! is_string($cell)) {
            return new self(null, '');
        }

        $raw = trim($cell);

        return self::readText($raw);
    }

    public function isFilled(): bool
    {
        return $this->raw !== '';
    }

    public function isAmbiguous(): bool
    {
        return $this->ambiguity !== null;
    }

    /**
     * O aviso da linha para a leitura ambígua, ou `null` quando não há dúvida.
     *
     * Ex.: "Valor pago '553,919' lido como R$ 553,92 (vírgula como separador
     * decimal). Se o valor é R$ 553.919,00, escreva 553.919,00 ou use célula
     * numérica."
     */
    public function warning(string $fieldLabel): ?string
    {
        if (($this->ambiguity === null) || ($this->cents === null) || ($this->alternativeCents === null)) {
            return null;
        }

        $alternative = IntegerMoney::format($this->alternativeCents);

        return sprintf(
            "%s '%s' lido como R$ %s (%s). Se o valor é R$ %s, escreva %s ou use célula numérica.",
            $fieldLabel,
            $this->raw,
            IntegerMoney::format($this->cents),
            $this->ambiguity->label(),
            $alternative,
            $alternative,
        );
    }

    private static function readText(string $raw): self
    {
        $value = str_replace(['R$', ' ', "\u{00A0}"], '', $raw);

        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');

        if (($digits === '') || (preg_match('/^[0-9.,]+$/', $digits) !== 1)) {
            return new self(null, $raw);
        }

        $commas = substr_count($digits, ',');
        $dots = substr_count($digits, '.');

        /**
         * Dois separadores diferentes: o mais à direita é o decimal, sem dúvida
         * nenhuma. Agrupar milhar com os dois ao mesmo tempo não existe.
         */
        if (($commas > 0) && ($dots > 0)) {
            $decimal = strrpos($digits, ',') > strrpos($digits, '.') ? ',' : '.';

            return new self(self::decimalCents($digits, $decimal, $negative), $raw);
        }

        $separator = $commas > 0 ? ',' : ($dots > 0 ? '.' : null);

        if ($separator === null) {
            return new self(self::decimalCents($digits, null, $negative), $raw);
        }

        $groups = explode($separator, $digits);

        /**
         * Vários separadores iguais só fazem sentido como milhar ("1.000.000",
         * "1,000,000"). Fora desse formato o texto não é um valor.
         */
        if (count($groups) > 2) {
            return self::isThousandsGrouping($groups)
                ? new self(self::decimalCents($digits, null, $negative), $raw)
                : new self(null, $raw);
        }

        $couldBeThousands = self::isThousandsGrouping($groups);

        if ($separator === ',') {
            $cents = self::decimalCents($digits, ',', $negative);

            return $couldBeThousands
                ? new self($cents, $raw, AmountTextAmbiguity::CommaAsDecimal, self::decimalCents($digits, null, $negative))
                : new self($cents, $raw);
        }

        if ($couldBeThousands) {
            return new self(
                self::decimalCents($digits, null, $negative),
                $raw,
                AmountTextAmbiguity::DotAsThousands,
                self::decimalCents($digits, '.', $negative),
            );
        }

        return new self(self::decimalCents($digits, '.', $negative), $raw);
    }

    /**
     * Um agrupamento de milhar: 1 a 3 dígitos, sem zero à esquerda, antes do
     * primeiro separador, e grupos de exatamente 3 depois. É a mesma definição
     * de {@see IntegerMoney}: "0,500" e "1234.567" nunca são milhar.
     *
     * @param  list<string>  $groups
     */
    private static function isThousandsGrouping(array $groups): bool
    {
        if ((count($groups) < 2) || (preg_match('/^[1-9]\d{0,2}$/', $groups[0]) !== 1)) {
            return false;
        }

        foreach (array_slice($groups, 1) as $group) {
            if (preg_match('/^\d{3}$/', $group) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Centavos do texto lido com o separador decimal dado (ou sem decimal),
     * arredondando a terceira casa meio para cima, como {@see IntegerMoney}.
     */
    private static function decimalCents(string $digits, ?string $decimalSeparator, bool $negative): ?int
    {
        if ($decimalSeparator === null) {
            $units = str_replace([',', '.'], '', $digits);
            $fraction = '';
        } else {
            $position = (int) strrpos($digits, $decimalSeparator);
            $units = str_replace([',', '.'], '', substr($digits, 0, $position));
            $fraction = str_replace([',', '.'], '', substr($digits, $position + 1));
        }

        if (($units === '') && ($fraction === '')) {
            return null;
        }

        $units = ($units === '') ? '0' : $units;
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        $cents = ((int) $units * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /**
     * A célula numérica como o operador a veria, para a mensagem.
     */
    private static function describeNumber(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
