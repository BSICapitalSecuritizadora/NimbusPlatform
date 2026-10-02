<?php

namespace App\Enums;

/**
 * Avisos que uma linha de planilha de importação pode carregar sem bloquear a
 * importação.
 *
 * Aviso é o caso duvidoso: a linha é gravada, mas quem confirma precisa ver o
 * ponto antes. O claramente impossível não é aviso, é erro da linha -- e uma
 * linha com erro bloqueia o arquivo inteiro, como sempre.
 *
 * Os valores são persistidos (propriedades da trilha da importação), então não
 * mudam depois de publicados.
 */
enum ImportRowWarningCode: string
{
    /** Valor em texto com duas leituras possíveis (milhar ou decimal). */
    case AmbiguousAmountText = 'valor_texto_ambiguo';

    /** Parcela (prevista ou paga) acima do valor da venda do contrato. */
    case InstallmentAboveSaleValue = 'parcela_acima_da_venda';

    /** Parcela prevista abaixo de 0,01% do valor da venda do contrato. */
    case InstallmentFarBelowSaleValue = 'parcela_muito_abaixo_da_venda';

    /** Valor pago o dobro ou mais do previsto da própria parcela. */
    case PaidFarAboveExpected = 'pago_muito_acima_do_previsto';

    /** Valor da venda a duas vezes ou mais (ou a metade ou menos) do valor de tabela. */
    case SaleValueOffTable = 'venda_fora_da_tabela';

    /** Novo valor de unidade a duas vezes ou mais (ou a metade ou menos) do vigente. */
    case UnitValueFarFromCurrent = 'valor_distante_do_vigente';

    /** Valor base da unidade distante da mediana das demais do empreendimento. */
    case UnitBaseValueFarFromPeers = 'valor_base_distante_das_demais';

    /** Contrato novo que repete código, unidade e comprador de outro empreendimento da Emissão. */
    case PossibleDuplicateAcrossConstructions = 'possivel_duplicidade_entre_empreendimentos';

    /** Valor gravado para unidade baixada: só conta se ela for reativada. */
    case RetiredUnit = 'unidade_baixada';

    public function label(): string
    {
        return match ($this) {
            self::AmbiguousAmountText => 'Valor em texto ambíguo',
            self::InstallmentAboveSaleValue => 'Parcela acima do valor da venda',
            self::InstallmentFarBelowSaleValue => 'Parcela muito abaixo do valor da venda',
            self::PaidFarAboveExpected => 'Pago muito acima do previsto',
            self::SaleValueOffTable => 'Venda fora do valor de tabela',
            self::UnitValueFarFromCurrent => 'Valor distante do vigente',
            self::UnitBaseValueFarFromPeers => 'Valor base distante das demais unidades',
            self::PossibleDuplicateAcrossConstructions => 'Possível duplicidade entre empreendimentos',
            self::RetiredUnit => 'Unidade baixada',
        };
    }
}
