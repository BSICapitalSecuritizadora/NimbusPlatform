<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Concerns\MoneyFormatter;

/**
 * Aritmética monetária exata em centavos inteiros.
 *
 * Existe porque o veredito de conformidade de uma venda não pode depender de
 * ponto flutuante: `0.1 + 0.2` não é `0.3`, e um centavo perdido no
 * arredondamento é a diferença entre uma venda dentro e fora da política
 * comercial. {@see MoneyFormatter} continua servindo para exibir
 * e para comparações tolerantes de conciliação; aqui nada passa por `float`.
 *
 * Independente da frente de PU: nada em `App\Domain\PuCalculator` é importado.
 * O que precisa de precisão arbitrária usa BCMath, que o projeto já usa na
 * conciliação financeira de medições.
 */
final class IntegerMoney
{
    /**
     * Base do percentual: 100,00% = 10000 basis points.
     */
    public const BASIS_POINTS_SCALE = 10_000;

    /**
     * Converte um valor monetário em centavos inteiros, sem `float`.
     *
     * Aceita o que o domínio realmente entrega: a string de um cast
     * `decimal:2` ("1000000.00"), um inteiro, e as formas digitadas/importadas
     * ("R$ 1.000.000,00", "1,000,000.00"). Devolve `null` para vazio -- ausência
     * de valor não é zero.
     */
    public static function cents(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            /**
             * Renderiza o literal decimal antes de qualquer conta. Só chega aqui
             * entrada frouxa (estado de formulário, célula numérica de planilha);
             * as colunas persistidas são `decimal:2` e chegam como string.
             */
            $value = sprintf('%.4F', $value);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return self::parseDecimalString($value);
    }

    /**
     * Converte um percentual em basis points, sem `float`.
     *
     * "5" e "5,00" viram 500; "4.25" vira 425. Percentuais com mais de duas
     * casas são recusados em vez de arredondados: a política é gravada em
     * `decimal(5,2)`, e aceitar 4,255% aqui esconderia um dado que o banco
     * truncaria depois.
     */
    public static function basisPoints(mixed $percent): ?int
    {
        if ($percent === null) {
            return null;
        }

        if (is_int($percent)) {
            return $percent * 100;
        }

        if (is_float($percent)) {
            $percent = sprintf('%.4F', $percent);
        }

        $percent = trim((string) $percent);

        if ($percent === '') {
            return null;
        }

        $percent = str_replace('%', '', $percent);

        $basisPoints = self::parseDecimalString($percent);

        if ($basisPoints === null) {
            return null;
        }

        return $basisPoints;
    }

    /**
     * Menor preço, em centavos, que respeita exatamente o desconto autorizado.
     *
     * O arredondamento é para CIMA por decisão de negócio: o desconto é um
     * limite máximo, e arredondar para baixo concederia frações de centavo de
     * desconto além do autorizado.
     *
     * Overflow: a conta ingênua `centavos * (10000 - bp)` estoura o inteiro de
     * 64 bits bem dentro do que `decimal(15,2)` permite armazenar
     * (10^15 centavos × 10^4 = 10^19 > 9,2×10^18). A decomposição abaixo evita
     * isso: `q * fator` nunca ultrapassa o próprio valor, porque `fator` é no
     * máximo o denominador; e o resto `r` é menor que 10^4, então
     * `r * fator` cabe em 10^8.
     */
    public static function minimumAfterDiscount(int $cents, int $basisPoints): int
    {
        if (($basisPoints < 0) || ($basisPoints > self::BASIS_POINTS_SCALE)) {
            throw new \InvalidArgumentException('O desconto precisa estar entre 0 e 10000 basis points.');
        }

        if ($cents < 0) {
            throw new \InvalidArgumentException('O valor de referência não pode ser negativo.');
        }

        $factor = self::BASIS_POINTS_SCALE - $basisPoints;

        $whole = intdiv($cents, self::BASIS_POINTS_SCALE);
        $remainder = $cents % self::BASIS_POINTS_SCALE;

        return ($whole * $factor)
            + intdiv(($remainder * $factor) + self::BASIS_POINTS_SCALE - 1, self::BASIS_POINTS_SCALE);
    }

    /**
     * Desconto efetivamente praticado, em basis points.
     *
     * Informação de exibição e auditoria -- o veredito vem da comparação
     * monetária exata, nunca daqui. Negativo quando a venda ficou acima da
     * tabela (ágio). `null` quando não há referência positiva contra a qual
     * medir percentual.
     *
     * BCMath porque `diferença × 10000` estoura o inteiro de 64 bits nos
     * mesmos limites de `decimal(15,2)` descritos em
     * {@see self::minimumAfterDiscount()}, e aqui o denominador é o valor de
     * referência, o que impede a decomposição usada lá.
     */
    public static function effectiveDiscountBasisPoints(int $referenceCents, int $saleCents): ?int
    {
        if ($referenceCents <= 0) {
            return null;
        }

        $difference = bcsub((string) $referenceCents, (string) $saleCents, 0);
        $scaled = bcmul($difference, (string) self::BASIS_POINTS_SCALE, 0);

        return (int) self::divideRoundingHalfAwayFromZero($scaled, (string) $referenceCents);
    }

    /**
     * Centavos como decimal canônico ("100000000" vira "1000000.00"), pronto
     * para uma coluna `decimal(15,2)` sem passar por `float`.
     */
    public static function decimalString(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = str_pad((string) abs($cents), 3, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($absolute, 0, -2).'.'.substr($absolute, -2);
    }

    /**
     * Centavos formatados como moeda brasileira, sem símbolo.
     */
    public static function format(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = (string) abs($cents);
        $absolute = str_pad($absolute, 3, '0', STR_PAD_LEFT);

        $units = substr($absolute, 0, -2);
        $fraction = substr($absolute, -2);

        $grouped = strrev(implode('.', str_split(strrev($units), 3)));

        return ($negative ? '-' : '').$grouped.','.$fraction;
    }

    /**
     * Basis points formatados como percentual brasileiro, sem símbolo.
     */
    public static function formatBasisPoints(int $basisPoints): string
    {
        $negative = $basisPoints < 0;
        $absolute = str_pad((string) abs($basisPoints), 3, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '')
            .substr($absolute, 0, -2)
            .','
            .substr($absolute, -2);
    }

    /**
     * Divisão inteira arredondando meio para longe do zero, em BCMath.
     */
    private static function divideRoundingHalfAwayFromZero(string $numerator, string $denominator): string
    {
        $negative = (bccomp($numerator, '0', 0) < 0);
        $absolute = $negative ? bcmul($numerator, '-1', 0) : $numerator;

        $doubled = bcadd(bcmul($absolute, '2', 0), $denominator, 0);
        $quotient = bcdiv($doubled, bcmul($denominator, '2', 0), 0);

        return $negative ? bcmul($quotient, '-1', 0) : $quotient;
    }

    /**
     * Interpreta um decimal escrito em qualquer das convenções aceitas e
     * devolve centésimos inteiros, arredondando a terceira casa meio para cima.
     *
     * A separação decimal é decidida pelo separador mais à direita: em
     * "1.000.000,00" a vírgula é decimal, em "1,000,000.00" é o ponto. Um único
     * separador seguido de exatamente três dígitos é agrupamento de milhar
     * ("1.000" é mil), qualquer outro é decimal ("1.5" é um e meio).
     */
    private static function parseDecimalString(string $value): ?int
    {
        $value = str_replace(['R$', ' ', "\u{00A0}"], '', $value);

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        if (! preg_match('/^[0-9.,]+$/', $value)) {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        $decimalSeparator = match (true) {
            ($lastComma !== false) && ($lastDot !== false) => ($lastComma > $lastDot) ? ',' : '.',
            $lastComma !== false => self::isGrouping($value, ',') ? null : ',',
            $lastDot !== false => self::isGrouping($value, '.') ? null : '.',
            default => null,
        };

        if ($decimalSeparator === null) {
            $units = str_replace([',', '.'], '', $value);
            $fraction = '';
        } else {
            $position = strrpos($value, $decimalSeparator);
            $units = str_replace([',', '.'], '', substr($value, 0, $position));
            $fraction = str_replace([',', '.'], '', substr($value, $position + 1));
        }

        if (($units === '') && ($fraction === '')) {
            return null;
        }

        $units = ($units === '') ? '0' : $units;
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        $cents = (int) $units * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /**
     * Um separador é agrupamento de milhar quando o número inteiro se decompõe
     * exatamente como milhares: 1 a 3 dígitos antes do primeiro separador e
     * grupos de exatamente 3 depois.
     *
     * A exigência sobre o primeiro grupo é o que separa "1.000" (mil) de
     * "1234.567": nenhum número agrupado por milhar tem quatro dígitos antes do
     * primeiro separador, nem começa por zero -- "0.005" é meio centavo, não
     * cinco.
     */
    private static function isGrouping(string $value, string $separator): bool
    {
        $groups = explode($separator, $value);

        if (count($groups) < 2) {
            return false;
        }

        if (! preg_match('/^[1-9]\d{0,2}$/', $groups[0])) {
            return false;
        }

        foreach (array_slice($groups, 1) as $group) {
            if (! preg_match('/^\d{3}$/', $group)) {
                return false;
            }
        }

        return true;
    }
}
