<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\Services\DecimalRounder;

/**
 * Apresentação pt-BR dos valores da curva de PU.
 *
 * APRESENTAÇÃO APENAS. Nenhuma conta financeira acontece aqui e nada nesta
 * classe alimenta a engine: ela recebe a string decimal já produzida pelo
 * cálculo e devolve texto. Reduzir a escala aqui não reduz a precisão interna,
 * que continua governada pelas regras contratuais em `DecimalRounder` e
 * `CdiFactorCompositionService`.
 *
 * Duas decisões deliberadas:
 *
 *  1. Nunca converte para `float`. Os valores chegam como string decimal de até
 *     16 casas, e `number_format()` exigiria uma passagem por ponto flutuante --
 *     justamente a conversão que a engine inteira evita por bcmath.
 *  2. Arredonda pelo MESMO `DecimalRounder::round()` da engine (meio para cima,
 *     afastando-se de zero). Truncar na exibição faria a tela discordar do valor
 *     calculado na 8ª casa, que é exatamente o tipo de divergência que esta
 *     curva precisa poder auditar.
 */
final class PuDecimalPresenter
{
    /** Casas decimais padronizadas para todo valor monetário da curva. */
    public const MONEY_SCALE = 8;

    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * Valor monetário da curva: PU, VNU, juros, amortização, pagamento, residual.
     */
    public function money(string|int|float|null $value): ?string
    {
        return $this->decimal($value, self::MONEY_SCALE);
    }

    /**
     * Taxa do indexador. Mesma escala dos monetários por consistência visual; o
     * valor armazenado e o resolvido permanecem intocados.
     */
    public function rate(string|int|float|null $value): ?string
    {
        return $this->decimal($value, self::MONEY_SCALE);
    }

    /**
     * Fator técnico (Fator DI, Fator Spread, Fator de Juros).
     *
     * Devolvido com a precisão integral que a engine produziu: esconder casas de
     * um fator inviabilizaria a auditoria de precisão, que é feita justamente na
     * 8ª e na 9ª casa. Não recebe separador de milhar nem arredondamento.
     */
    public function factor(string|int|float|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_replace('.', ',', (string) $value);
    }

    /**
     * Formata uma string decimal em pt-BR com escala fixa: milhar `.`, decimal `,`.
     */
    public function decimal(string|int|float|null $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $rounded = $this->rounder->round($value, $scale);

        // Um valor que arredonda para zero nunca é exibido como "-0,00000000".
        if (bccomp($rounded, '0', $scale) === 0) {
            $rounded = ltrim($rounded, '-');
        }

        return $this->toBrazilianNotation($rounded, $scale);
    }

    private function toBrazilianNotation(string $value, int $scale): string
    {
        $sign = str_starts_with($value, '-') ? '-' : '';
        [$integerPart, $decimalPart] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        if ($integerPart === '') {
            $integerPart = '0';
        }

        $groupedInteger = strrev(implode('.', str_split(strrev($integerPart), 3)));

        if ($scale <= 0) {
            return $sign.$groupedInteger;
        }

        return sprintf(
            '%s%s,%s',
            $sign,
            $groupedInteger,
            substr($decimalPart.str_repeat('0', $scale), 0, $scale),
        );
    }
}
