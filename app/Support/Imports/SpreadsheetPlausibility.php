<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\Enums\ImportRowWarningCode;
use App\Support\BusinessTime;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\CompetenceCalendar;
use App\Support\SalesBoards\SalesBoardPlausibility;
use Carbon\CarbonImmutable;

/**
 * Se um valor lido da planilha faz sentido diante de uma referência já
 * cadastrada: aviso para o caso duvidoso, erro de linha para o claramente
 * impossível.
 *
 * Existe porque a leitura antiga transformava "553,919" em R$ 553.919,00 sem
 * nenhum sinal, e porque um zero a mais na digitação multiplica o financiado por
 * dez. A referência de cada regra é o que o banco já sabe sobre o mesmo objeto:
 *
 * - parcela contra o valor da venda do contrato (e o pago contra o previsto);
 * - valor da venda contra o valor de tabela da unidade na data da venda e, sem
 *   ele, na data da posição -- a mesma referência da derivação;
 * - novo valor de unidade contra o vigente e, antes de qualquer valor
 *   cadastrado, contra as vendas de que ele passaria a ser a referência;
 * - valor base de unidade contra a mediana do empreendimento.
 *
 * Os fatores de escala vêm de {@see SalesBoardPlausibility}: a importação nunca
 * é mais permissiva que um bloqueador da derivação. A parcela é mais rígida que
 * a derivação (erro já acima do dobro da venda), porque nenhuma parcela chega
 * perto disso. Toda conta é em centavos inteiros, sem `float`; sem referência,
 * nenhuma regra avalia.
 */
final class SpreadsheetPlausibility
{
    /** Parcela acima deste múltiplo da venda é erro. */
    public const INSTALLMENT_ERROR_MULTIPLE_OF_SALE = 2;

    /** Parcela acima deste múltiplo da venda é aviso. */
    public const INSTALLMENT_WARNING_MULTIPLE_OF_SALE = 1;

    /** Parcela prevista abaixo desta fração da venda, em basis points (0,01%), é aviso. */
    public const INSTALLMENT_FLOOR_BASIS_POINTS_OF_SALE = 1;

    /** Pago a partir deste múltiplo do previsto é aviso. */
    public const PAID_WARNING_MULTIPLE_OF_EXPECTED = SalesBoardPlausibility::ATYPICAL_VALUE_FACTOR;

    /** Venda a este fator da tabela (ou ao inverso dele) é aviso. */
    public const SALE_WARNING_FACTOR = SalesBoardPlausibility::ATYPICAL_VALUE_FACTOR;

    /** Venda a este fator da tabela (ou ao inverso dele) é erro. */
    public const SALE_ERROR_FACTOR = SalesBoardPlausibility::SCALE_FACTOR;

    /** Novo valor a este fator do vigente (ou ao inverso dele) é aviso. */
    public const UNIT_VALUE_WARNING_FACTOR = SalesBoardPlausibility::ATYPICAL_VALUE_FACTOR;

    /** Novo valor a este fator do vigente (ou ao inverso dele) é erro. */
    public const UNIT_VALUE_ERROR_FACTOR = SalesBoardPlausibility::SCALE_FACTOR;

    /** Valor base além deste fator da mediana (ou do inverso dele) é aviso. */
    public const UNIT_BASE_WARNING_FACTOR = 5;

    /** Valor base além deste fator da mediana (ou do inverso dele) é erro. */
    public const UNIT_BASE_ERROR_FACTOR = 100;

    /** Mínimo de valores base no empreendimento para a mediana valer. */
    public const UNIT_BASE_MIN_PEERS = 5;

    /**
     * Parcela contra o valor da venda do contrato.
     *
     * Erro acima do dobro da venda; aviso acima da venda, para previsto abaixo de
     * 0,01% dela e para pago a partir do dobro do previsto.
     */
    public static function installment(?int $expectedCents, ?int $paidCents, ?int $saleCents): PlausibilityVerdict
    {
        $verdict = PlausibilityVerdict::plausible();

        if (($saleCents !== null) && ($saleCents > 0)) {
            foreach (['Valor previsto' => $expectedCents, 'Valor pago' => $paidCents] as $label => $cents) {
                if ($cents === null) {
                    continue;
                }

                if (self::exceedsMultiple($cents, $saleCents, self::INSTALLMENT_ERROR_MULTIPLE_OF_SALE)) {
                    return PlausibilityVerdict::impossible(sprintf(
                        '%s (R$ %s) maior que o dobro do valor da venda do contrato (R$ %s): confira a leitura do valor.',
                        $label,
                        IntegerMoney::format($cents),
                        IntegerMoney::format($saleCents),
                    ));
                }

                if (self::exceedsMultiple($cents, $saleCents, self::INSTALLMENT_WARNING_MULTIPLE_OF_SALE)) {
                    $verdict = $verdict->and(PlausibilityVerdict::doubtful(
                        ImportRowWarningCode::InstallmentAboveSaleValue,
                        sprintf(
                            '%s (R$ %s) acima do valor da venda do contrato (R$ %s). Confira se a parcela está certa.',
                            $label,
                            IntegerMoney::format($cents),
                            IntegerMoney::format($saleCents),
                        ),
                    ));
                }
            }

            if (($expectedCents !== null) && self::isBelowBasisPoints($expectedCents, $saleCents, self::INSTALLMENT_FLOOR_BASIS_POINTS_OF_SALE)) {
                $verdict = $verdict->and(PlausibilityVerdict::doubtful(
                    ImportRowWarningCode::InstallmentFarBelowSaleValue,
                    sprintf(
                        'Valor previsto (R$ %s) abaixo de 0,01%% do valor da venda do contrato (R$ %s). Confira a leitura do valor.',
                        IntegerMoney::format($expectedCents),
                        IntegerMoney::format($saleCents),
                    ),
                ));
            }
        }

        if (($paidCents !== null) && ($expectedCents !== null)
            && SalesBoardPlausibility::isAtLeastTwiceOf($paidCents, $expectedCents)) {
            $verdict = $verdict->and(PlausibilityVerdict::doubtful(
                ImportRowWarningCode::PaidFarAboveExpected,
                sprintf(
                    'Valor pago (R$ %s) é o dobro ou mais do valor previsto (R$ %s). Confira a leitura do valor.',
                    IntegerMoney::format($paidCents),
                    IntegerMoney::format($expectedCents),
                ),
            ));
        }

        return $verdict;
    }

    /**
     * Valor da venda contra o valor de tabela da unidade na data da venda.
     *
     * Erro a dez vezes ou mais (ou a um décimo ou menos); aviso a duas vezes ou
     * mais (ou à metade ou menos).
     *
     * @param  string|null  $tableDate  a data da venda em que a tabela foi lida (`Y-m-d`)
     */
    public static function sale(int $saleCents, ?int $tableCents, ?string $tableDate): PlausibilityVerdict
    {
        if (($tableCents === null) || ($tableCents <= 0)) {
            return PlausibilityVerdict::plausible();
        }

        $where = filled($tableDate) ? ' em '.CarbonImmutable::parse((string) $tableDate)->format('d/m/Y') : '';

        if (SalesBoardPlausibility::isOutOfScale($saleCents, $tableCents)) {
            return PlausibilityVerdict::impossible(sprintf(
                'Valor da venda (R$ %s) é %s valor de tabela da unidade%s (R$ %s): um dos dois foi lido errado. Confira a planilha e o valor da unidade.',
                IntegerMoney::format($saleCents),
                self::proportion($saleCents, $tableCents, 'o'),
                $where,
                IntegerMoney::format($tableCents),
            ));
        }

        if (self::isAtTwiceOrHalf($saleCents, $tableCents)) {
            return PlausibilityVerdict::doubtful(
                ImportRowWarningCode::SaleValueOffTable,
                sprintf(
                    'Valor da venda (R$ %s) é %s valor de tabela da unidade%s (R$ %s). Confira se a negociação justifica a diferença.',
                    IntegerMoney::format($saleCents),
                    self::proportion($saleCents, $tableCents, 'o'),
                    $where,
                    IntegerMoney::format($tableCents),
                ),
            );
        }

        return PlausibilityVerdict::plausible();
    }

    /**
     * As datas da posição contra as quais a importação mede a venda sem tabela
     * na data da venda: o fim da última competência encerrada -- a posição da
     * próxima apuração -- e o dia de negócio de hoje -- a das competências
     * seguintes. A derivação mede contra a posição da competência que apura; a
     * importação não sabe qual será, e confere as duas para não aceitar a venda
     * que alguma delas bloquearia.
     *
     * @return list<string> datas `Y-m-d`, sem repetição
     */
    public static function positionReferenceDates(): array
    {
        return array_values(array_unique([
            CompetenceCalendar::lastClosedMonth()->endOfMonth()->toDateString(),
            BusinessTime::dateString(),
        ]));
    }

    /**
     * Valor da venda contra a referência da derivação
     * ({@see SalesBoardPlausibility::saleScaleReference()}): a tabela da
     * unidade na data da venda e, sem ela (ausente ou zero), a tabela na data
     * da posição.
     *
     * Com a tabela da data da venda, a regra é a de {@see self::sale()}: erro a
     * uma ordem de grandeza, aviso a duas vezes. Com a da posição, só o erro: o
     * ágio duvidoso da derivação também só olha a tabela da data da venda, e
     * avisar contra uma tabela de anos depois seria ruído.
     *
     * @param  array<string, ResolvedUnitValue|null>  $atPositions  data da posição (`Y-m-d`) => valor da unidade nela; vazio quando a posição não vale para a linha
     */
    public static function saleAgainstReference(int $saleCents, string $saleDate, ?ResolvedUnitValue $atSale, array $atPositions): PlausibilityVerdict
    {
        $saleDayReference = SalesBoardPlausibility::saleScaleReference($atSale, null);

        if ($saleDayReference !== null) {
            return self::sale($saleCents, $saleDayReference->valueCents, $saleDate);
        }

        foreach ($atPositions as $day => $atPosition) {
            $reference = SalesBoardPlausibility::saleScaleReference($atSale, $atPosition);

            if (($reference === null) || ! SalesBoardPlausibility::isOutOfScale($saleCents, (int) $reference->valueCents)) {
                continue;
            }

            return PlausibilityVerdict::impossible(sprintf(
                'Valor da venda (R$ %s) é %s valor de tabela da unidade em %s (R$ %s), a referência do Quadro de Vendas quando não há tabela na data da venda: um dos dois foi lido errado. Confira a planilha e o valor da unidade.',
                IntegerMoney::format($saleCents),
                self::proportion($saleCents, (int) $reference->valueCents, 'o'),
                CarbonImmutable::parse((string) $day)->format('d/m/Y'),
                IntegerMoney::format((int) $reference->valueCents),
            ));
        }

        return PlausibilityVerdict::plausible();
    }

    /**
     * Novo valor de unidade sem valor cadastrado na data contra uma venda de
     * que ele passaria a ser a referência na data da venda.
     *
     * A derivação mede a venda contra a tabela da data da venda. Quando a linha
     * passa a ser essa tabela -- vigência até a data da venda, sem outro valor
     * no meio --, um valor a uma ordem de grandeza da venda faria a obra travar
     * na apuração seguinte: é erro da linha; a duas vezes, aviso, a mesma régua
     * do ágio duvidoso da derivação.
     */
    public static function unitValueAgainstSale(int $valueCents, int $saleCents, string $contractCode, ?string $saleDate): PlausibilityVerdict
    {
        if (($saleCents <= 0) || ($valueCents <= 0)) {
            return PlausibilityVerdict::plausible();
        }

        $sale = self::saleLabel($saleCents, $contractCode, $saleDate);

        if (SalesBoardPlausibility::isOutOfScale($saleCents, $valueCents)) {
            return PlausibilityVerdict::impossible(sprintf(
                'Valor atualizado (R$ %s) é %s valor da venda %s: um dos dois foi lido errado. A unidade não tem valor cadastrado nesta data, e este valor passaria a ser a referência da venda no Quadro de Vendas. Confira a planilha e o contrato.',
                IntegerMoney::format($valueCents),
                self::proportion($valueCents, $saleCents, 'o'),
                $sale,
            ));
        }

        if (self::isAtTwiceOrHalf($saleCents, $valueCents)) {
            return PlausibilityVerdict::doubtful(
                ImportRowWarningCode::SaleValueOffTable,
                sprintf(
                    'Valor atualizado (R$ %s) é %s valor da venda %s. Confira se a tabela está certa.',
                    IntegerMoney::format($valueCents),
                    self::proportion($valueCents, $saleCents, 'o'),
                    $sale,
                ),
            );
        }

        return PlausibilityVerdict::plausible();
    }

    /**
     * Novo valor de unidade sem valor cadastrado na data contra uma venda sem
     * tabela na data dela, de que ele passaria a ser a referência como valor
     * da unidade na data da posição.
     *
     * É a reserva da derivação ({@see SalesBoardPlausibility::saleScaleReference()}):
     * sem tabela na data da venda, a escala é medida contra a tabela da
     * posição. Só o erro, como na importação de contratos
     * ({@see self::saleAgainstReference()}): o ágio duvidoso da derivação só
     * olha a tabela da data da venda, e avisar contra uma tabela de anos depois
     * seria ruído.
     *
     * @param  string  $positionDate  a data da posição em que a linha passaria a valer (`Y-m-d`)
     */
    public static function unitValueAgainstSaleAtPosition(
        int $valueCents,
        int $saleCents,
        string $contractCode,
        ?string $saleDate,
        string $positionDate,
    ): PlausibilityVerdict {
        if (($saleCents <= 0) || ($valueCents <= 0) || ! SalesBoardPlausibility::isOutOfScale($saleCents, $valueCents)) {
            return PlausibilityVerdict::plausible();
        }

        return PlausibilityVerdict::impossible(sprintf(
            'Valor atualizado (R$ %s) é %s valor da venda %s: um dos dois foi lido errado. A venda não tem valor de tabela na data dela, e este valor passaria a ser a referência dela no Quadro de Vendas, como valor da unidade em %s. Confira a planilha e o contrato.',
            IntegerMoney::format($valueCents),
            self::proportion($valueCents, $saleCents, 'o'),
            self::saleLabel($saleCents, $contractCode, $saleDate),
            CarbonImmutable::parse($positionDate)->format('d/m/Y'),
        ));
    }

    /**
     * A venda como as mensagens a citam: "do contrato X (R$ 600.000,00,
     * vendido em 10/03/2026)".
     */
    private static function saleLabel(int $saleCents, string $contractCode, ?string $saleDate): string
    {
        return sprintf(
            'do contrato %s (R$ %s%s)',
            $contractCode,
            IntegerMoney::format($saleCents),
            filled($saleDate) ? ', vendido em '.CarbonImmutable::parse((string) $saleDate)->format('d/m/Y') : '',
        );
    }

    /**
     * Novo valor de unidade contra o valor vigente na mesma data.
     *
     * Erro a dez vezes ou mais (ou a um décimo ou menos); aviso a duas vezes ou
     * mais (ou à metade ou menos).
     */
    public static function unitValue(int $valueCents, ?int $currentCents): PlausibilityVerdict
    {
        if (($currentCents === null) || ($currentCents <= 0)) {
            return PlausibilityVerdict::plausible();
        }

        if (SalesBoardPlausibility::isOutOfScale($valueCents, $currentCents)) {
            return PlausibilityVerdict::impossible(sprintf(
                'Valor atualizado (R$ %s) é %s valor vigente da unidade (R$ %s): um dos dois foi lido errado. Confira a planilha e o histórico de valores da unidade.',
                IntegerMoney::format($valueCents),
                self::proportion($valueCents, $currentCents, 'o'),
                IntegerMoney::format($currentCents),
            ));
        }

        if (self::isAtTwiceOrHalf($valueCents, $currentCents)) {
            return PlausibilityVerdict::doubtful(
                ImportRowWarningCode::UnitValueFarFromCurrent,
                sprintf(
                    'Valor atualizado (R$ %s) é %s valor vigente da unidade (R$ %s). Confira se o reajuste está certo.',
                    IntegerMoney::format($valueCents),
                    self::proportion($valueCents, $currentCents, 'o'),
                    IntegerMoney::format($currentCents),
                ),
            );
        }

        return PlausibilityVerdict::plausible();
    }

    /**
     * Valor base de unidade contra a mediana do empreendimento.
     *
     * Erro além de cem vezes a mediana (ou de um centésimo dela); aviso além de
     * cinco vezes (ou de um quinto). Sem mediana -- menos de
     * {@see self::UNIT_BASE_MIN_PEERS} valores --, não avalia.
     */
    public static function unitBaseValue(int $baseCents, ?int $medianCents): PlausibilityVerdict
    {
        if (($medianCents === null) || ($medianCents <= 0)) {
            return PlausibilityVerdict::plausible();
        }

        if (self::isBeyondFactor($baseCents, $medianCents, self::UNIT_BASE_ERROR_FACTOR)) {
            return PlausibilityVerdict::impossible(sprintf(
                'Valor base (R$ %s) é %s mediana das unidades do empreendimento (R$ %s): confira a leitura do valor.',
                IntegerMoney::format($baseCents),
                self::proportion($baseCents, $medianCents, 'a'),
                IntegerMoney::format($medianCents),
            ));
        }

        if (self::isBeyondFactor($baseCents, $medianCents, self::UNIT_BASE_WARNING_FACTOR)) {
            return PlausibilityVerdict::doubtful(
                ImportRowWarningCode::UnitBaseValueFarFromPeers,
                sprintf(
                    'Valor base (R$ %s) é %s mediana das unidades do empreendimento (R$ %s). Confira a leitura do valor.',
                    IntegerMoney::format($baseCents),
                    self::proportion($baseCents, $medianCents, 'a'),
                    IntegerMoney::format($medianCents),
                ),
            );
        }

        return PlausibilityVerdict::plausible();
    }

    /**
     * Mediana dos valores, ou `null` com menos de {@see self::UNIT_BASE_MIN_PEERS}
     * valores positivos. Com quantidade par, a menor das duas centrais: a regra
     * é de ordem de grandeza, e meio centavo não muda nenhuma fronteira.
     *
     * @param  list<int>  $values
     */
    public static function median(array $values): ?int
    {
        $values = array_values(array_filter($values, static fn (int $value): bool => $value > 0));

        if (count($values) < self::UNIT_BASE_MIN_PEERS) {
            return null;
        }

        sort($values);

        return $values[intdiv(count($values) - 1, 2)];
    }

    /**
     * `value > multiple × base`, sem multiplicar: com inteiros, a desigualdade
     * estrita equivale a `⌊value / multiple⌋ > base` ou a sobra de uma divisão
     * exata.
     */
    private static function exceedsMultiple(int $value, int $base, int $multiple): bool
    {
        $quotient = intdiv($value, $multiple);

        return ($quotient > $base) || (($quotient === $base) && (($value % $multiple) > 0));
    }

    /**
     * `value × 10000 < base × basisPoints`, sem estouro: compara pela divisão
     * inteira da base.
     */
    private static function isBelowBasisPoints(int $value, int $base, int $basisPoints): bool
    {
        $scaledBase = $base * $basisPoints;
        $quotient = intdiv($scaledBase, IntegerMoney::BASIS_POINTS_SCALE);
        $remainder = $scaledBase % IntegerMoney::BASIS_POINTS_SCALE;

        return ($value < $quotient) || (($value === $quotient) && ($remainder > 0));
    }

    /**
     * A duas vezes ou mais, ou à metade ou menos, da referência.
     */
    private static function isAtTwiceOrHalf(int $value, int $reference): bool
    {
        return SalesBoardPlausibility::isAtLeastTwiceOf($value, $reference)
            || SalesBoardPlausibility::isAtLeastTwiceOf($reference, $value);
    }

    /**
     * Estritamente além do fator, para cima ou para baixo.
     */
    private static function isBeyondFactor(int $value, int $reference, int $factor): bool
    {
        return self::exceedsMultiple($value, $reference, $factor)
            || self::exceedsMultiple($reference, $value, $factor);
    }

    /**
     * A proporção para a mensagem: "cerca de 3 vezes o" ou "cerca de 1/1000 do".
     * A mesma formatação das mensagens da derivação.
     */
    private static function proportion(int $value, int $reference, string $article): string
    {
        return SalesBoardPlausibility::ratioLabel($value, $reference, $article);
    }
}
