<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\Services\SalesBoards\SalesBoardDerivationService;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\SpreadsheetPlausibility;

/**
 * Fonte única dos limiares de plausibilidade do Quadro de Vendas.
 *
 * A derivação ({@see SalesBoardDerivationService}) bloqueia a posição quando um
 * valor está a uma ordem de grandeza da referência ou quando uma data que decide
 * a posição é implausível, e avisa no caso só duvidoso. A importação
 * ({@see SpreadsheetPlausibility}) usa os mesmos fatores e as mesmas funções. A
 * regra que as amarra: a importação nunca é mais permissiva que um bloqueador da
 * derivação -- senão um arquivo aceito com aviso travaria a obra na apuração
 * seguinte.
 *
 * Por que cada limiar, pelo tamanho do erro que precisa pegar:
 *
 * - {@see self::SCALE_FACTOR} (10): os erros de digitação são um zero a mais ou a
 *   menos (×10, ÷10), e os do parser antigo eram ×1000 e ÷1000. Nenhuma venda
 *   legítima fica a uma ordem de grandeza da tabela -- a política comercial
 *   limita o desconto, e a correção entre a venda e a posição não chega a 3× --,
 *   e nenhuma parcela chega a dez vezes o preço, nem uma quitação lançada numa
 *   parcela só com correção. O placeholder de referência (R$ 1,00) cai aqui de
 *   propósito: ele é a referência que precisa ser corrigida;
 * - {@see self::ATYPICAL_VALUE_FACTOR} (2): separa o duvidoso do impossível. Uma
 *   venda pelo dobro da tabela pede conferência, e uma parcela única pouco acima
 *   da venda (quitação corrigida) é legítima -- "acima da venda" por pouco só
 *   faria ruído;
 * - {@see self::ATYPICAL_PAYMENT_FACTOR} (100): o pagamento parcial lido como
 *   milhar ("553,919" virando R$ 553.919,00 sobre um previsto de R$ 600,00), sem
 *   acusar a quitação lançada numa parcela só;
 * - {@see self::MINIMUM_YEAR} (1990): o mesmo piso que os importadores aplicam
 *   ({@see SpreadsheetDate}). Nenhuma venda, recebimento ou vigência da carteira é
 *   tão antiga; um ano assim é dígito perdido ("0026", "0202").
 *
 * Constantes de domínio, e não configuração: como o piso de data dos
 * importadores, são parte da regra, e um limiar lido de `env()` cru já foi
 * incidente. Toda conta é em centavos inteiros, sem `float`, e as fronteiras são
 * inclusivas: exatamente dez vezes já está fora de escala.
 */
final class SalesBoardPlausibility
{
    public const SCALE_FACTOR = 10;

    public const ATYPICAL_VALUE_FACTOR = 2;

    public const ATYPICAL_PAYMENT_FACTOR = 100;

    public const MINIMUM_YEAR = SpreadsheetDate::MINIMUM_YEAR;

    /**
     * O valor está a uma ordem de grandeza da referência ou mais, para cima
     * (≥ 10×) ou para baixo (≤ 1/10). Referência ausente ou zero não avalia.
     *
     * Divisão inteira em vez de multiplicação: com valores inteiros,
     * `value ≥ 10 × ref` equivale a `⌊value / 10⌋ ≥ ref`, e `value ≤ ref / 10`
     * a `value ≤ ⌊ref / 10⌋` -- as mesmas fronteiras, sem estouro.
     */
    public static function isOutOfScale(int $value, int $reference): bool
    {
        if ($reference <= 0) {
            return false;
        }

        return (intdiv($value, self::SCALE_FACTOR) >= $reference)
            || (intdiv($reference, self::SCALE_FACTOR) >= $value);
    }

    /**
     * A referência contra a qual a escala de uma venda é medida: o valor da
     * unidade na data da venda e, na falta dele -- ausente ou zero --, o valor na
     * data da posição. Zero não é referência; sem nenhuma das duas, nada é
     * avaliado.
     *
     * Um ponto só para a derivação ({@see SalesBoardDerivationService}) e para a
     * importação de contratos ({@see SpreadsheetPlausibility}): quando a
     * importação olhava só a data da venda, a venda anterior à primeira tabela
     * da unidade entrava sem aviso e a obra travava na apuração seguinte, que a
     * media contra a tabela da data da posição.
     */
    public static function saleScaleReference(?ResolvedUnitValue $atSale, ?ResolvedUnitValue $atPosition): ?ResolvedUnitValue
    {
        foreach ([$atSale, $atPosition] as $value) {
            if (($value !== null) && (($value->valueCents ?? 0) > 0)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * O ágio duvidoso: a venda é o dobro da referência ou mais. Só o lado de
     * cima -- o lado do desconto já é a conformidade com a política comercial.
     */
    public static function isAtypicalPremium(int $saleValue, int $reference): bool
    {
        return self::isAtLeastTwiceOf($saleValue, $reference);
    }

    /**
     * O valor é dez vezes a base ou mais (a parcela contra o valor da venda). Só
     * o lado de cima: parcela pequena é rotina. Base ausente ou zero não avalia.
     */
    public static function exceedsScaleOf(int $value, int $base): bool
    {
        return self::isAtLeastMultipleOf($value, $base, self::SCALE_FACTOR);
    }

    /**
     * O valor é o dobro da base ou mais. Base ausente ou zero não avalia.
     */
    public static function isAtLeastTwiceOf(int $value, int $base): bool
    {
        return self::isAtLeastMultipleOf($value, $base, self::ATYPICAL_VALUE_FACTOR);
    }

    /**
     * O pago é cem vezes o previsto da própria parcela ou mais: a marca do
     * pagamento lido como milhar. Previsto ausente ou zero não avalia.
     */
    public static function isAtypicalPayment(int $paidValue, int $expectedValue): bool
    {
        return self::isAtLeastMultipleOf($paidValue, $expectedValue, self::ATYPICAL_PAYMENT_FACTOR);
    }

    /**
     * A data é anterior a 01/01 de {@see self::MINIMUM_YEAR}.
     *
     * Recebe o texto `Y-m-d` (com ou sem hora, como o banco devolve) e compara o
     * ano pelos dígitos, sem `Carbon`: o ano "0026" que se quer pegar é
     * exatamente o que um parse tolerante poderia reinterpretar.
     */
    public static function isBeforeMinimumYear(?string $date): bool
    {
        if (($date === null) || ($date === '')) {
            return false;
        }

        /**
         * O caminho comum, sem expressão regular: os dois bancos devolvem o ano
         * com quatro dígitos ("0026-03-10"). O resolvedor de quitação pergunta
         * isto para cada parcela da obra.
         */
        if ((strlen($date) >= 5) && ($date[4] === '-') && ctype_digit(substr($date, 0, 4))) {
            return (int) substr($date, 0, 4) < self::MINIMUM_YEAR;
        }

        if (preg_match('/^(\d{1,4})-\d{1,2}-\d{1,2}/', trim($date), $matches) !== 1) {
            return false;
        }

        return (int) $matches[1] < self::MINIMUM_YEAR;
    }

    /**
     * A proporção para a mensagem, já com o artigo da referência que vem depois:
     * "cerca de 1.000 vezes o", "cerca de 1/10 do", "diferente do" (com valor
     * não positivo). Só formata -- nenhuma decisão passa por aqui. É a mesma nas
     * mensagens da derivação e da importação.
     *
     * @param  string  $article  `o` ou `a`, o artigo da referência ("o valor", "a mediana")
     */
    public static function ratioLabel(int $value, int $reference, string $article = 'o'): string
    {
        if (($value <= 0) || ($reference <= 0)) {
            return 'diferente d'.$article;
        }

        if ($value >= $reference) {
            return 'cerca de '.self::ratio($value, $reference).' vezes '.$article;
        }

        return 'cerca de 1/'.self::ratio($reference, $value).' d'.$article;
    }

    /**
     * A razão para exibição: inteira a partir de 10, com uma casa abaixo disso.
     */
    private static function ratio(int $numerator, int $denominator): string
    {
        $tenths = (int) bcdiv(bcmul((string) $numerator, '10', 0), (string) $denominator, 0);

        if ($tenths >= 100) {
            return number_format((int) round($tenths / 10), 0, ',', '.');
        }

        return ($tenths % 10) === 0
            ? (string) intdiv($tenths, 10)
            : intdiv($tenths, 10).','.($tenths % 10);
    }

    /**
     * `value ≥ factor × base`, em inteiros e sem multiplicar: com a base
     * inteira, `⌊value / factor⌋ ≥ base` é a mesma desigualdade, e não estoura
     * nos limites de `decimal(15,2)`. Base não positiva não avalia.
     */
    private static function isAtLeastMultipleOf(int $value, int $base, int $factor): bool
    {
        if ($base <= 0) {
            return false;
        }

        return intdiv($value, $factor) >= $base;
    }
}
