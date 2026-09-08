<?php

namespace App\Enums;

/**
 * O que exatamente mudou entre duas versões congeladas -- ou entre a versão
 * congelada e o que a fonte viva produziria agora.
 *
 * Cada código aponta para uma unidade ou um contrato. "Algo mudou" não é
 * resposta: quem vai conferir precisa saber qual linha abrir.
 */
enum SalesBoardDiffCode: string
{
    case UnitAdded = 'unidade_incluida';

    case UnitRemoved = 'unidade_removida';

    case ClassificationChanged = 'classificacao_alterada';

    case ContractChanged = 'contrato_alterado';

    case ContractCodeChanged = 'codigo_do_contrato_alterado';

    case ContractSaleDateChanged = 'data_da_venda_alterada';

    case ContractSaleValueChanged = 'valor_da_venda_alterado';

    case UnitReferenceValueChanged = 'valor_de_referencia_alterado';

    case SettlementChanged = 'quitacao_alterada';

    case ExchangeChanged = 'permuta_alterada';

    case LineSourceOnlyChanged = 'fonte_da_unidade_alterada';

    case MovementAdded = 'movimento_incluido';

    case MovementRemoved = 'movimento_removido';

    case MovementChanged = 'movimento_alterado';

    case MovementSourceOnlyChanged = 'fonte_do_movimento_alterada';

    /**
     * Mudança que não altera nada do que foi congelado.
     *
     * Continua sendo reportada: esconder uma alteração da fonte só porque os
     * números coincidiram é o tipo de silêncio que a Fase C existe para acabar.
     */
    public function isSourceOnly(): bool
    {
        return in_array($this, [self::LineSourceOnlyChanged, self::MovementSourceOnlyChanged], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::UnitAdded => 'Unidade incluída',
            self::UnitRemoved => 'Unidade removida',
            self::ClassificationChanged => 'Classificação alterada',
            self::ContractChanged => 'Contrato da unidade alterado',
            self::ContractCodeChanged => 'Código do contrato alterado',
            self::ContractSaleDateChanged => 'Data da venda alterada',
            self::ContractSaleValueChanged => 'Valor da venda alterado',
            self::UnitReferenceValueChanged => 'Valor de referência alterado',
            self::SettlementChanged => 'Quitação alterada',
            self::ExchangeChanged => 'Permuta alterada',
            self::LineSourceOnlyChanged => 'Fonte da unidade alterada, sem impacto',
            self::MovementAdded => 'Movimento incluído',
            self::MovementRemoved => 'Movimento removido',
            self::MovementChanged => 'Movimento alterado',
            self::MovementSourceOnlyChanged => 'Fonte do movimento alterada, sem impacto',
        };
    }
}
