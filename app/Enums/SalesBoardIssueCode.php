<?php

namespace App\Enums;

/**
 * O que impede -- ou apenas contamina -- a derivação de uma posição.
 *
 * Enum de diagnóstico, não de persistência: nada aqui vira linha de tabela nesta
 * fase. A não conformidade comercial registrada e tratada é assunto das fases
 * seguintes, e antecipar o schema dela congelaria decisões ainda não tomadas.
 *
 * A severidade separa duas coisas que é fácil confundir. Um bloqueador é
 * *ausência de dado*: o Nimbus não consegue explicar um número. Um aviso é um
 * *fato desagradável já apurado* -- uma venda fora da política é uma
 * irregularidade que a derivação detectou justamente porque funcionou, e
 * bloquear por causa dela esconderia o achado.
 */
enum SalesBoardIssueCode: string
{
    case NoConstructionUnits = 'NO_CONSTRUCTION_UNITS';

    case AmbiguousOccupancy = 'AMBIGUOUS_OCCUPANCY';

    case AmbiguousExchange = 'AMBIGUOUS_EXCHANGE';

    case ExchangeOccupancyConflict = 'EXCHANGE_OCCUPANCY_CONFLICT';

    case ExchangeSourceMissing = 'EXCHANGE_SOURCE_MISSING';

    case SettlementUndetermined = 'SETTLEMENT_UNDETERMINED';

    case UnitValueMissing = 'UNIT_VALUE_MISSING';

    case SaleUnitValueMissing = 'SALE_UNIT_VALUE_MISSING';

    case SaleDiscountPolicyMissing = 'SALE_DISCOUNT_POLICY_MISSING';

    case UnitConstructionMismatch = 'UNIT_CONSTRUCTION_MISMATCH';

    case SaleNonConform = 'SALE_NON_CONFORM';

    public function severity(): SalesBoardIssueSeverity
    {
        return match ($this) {
            self::SaleNonConform => SalesBoardIssueSeverity::Warning,
            default => SalesBoardIssueSeverity::Blocker,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NoConstructionUnits => 'Empreendimento sem unidades cadastradas',
            self::AmbiguousOccupancy => 'Mais de um contrato ocupa a unidade na data',
            self::AmbiguousExchange => 'Mais de uma permuta vigente para a unidade',
            self::ExchangeOccupancyConflict => 'Permuta vigente conflita com o contrato ocupante',
            self::ExchangeSourceMissing => 'Contrato permutado sem fonte de permuta registrada',
            self::SettlementUndetermined => 'Quitação do contrato indeterminada na data',
            self::UnitValueMissing => 'Unidade em estoque sem valor vigente na data',
            self::SaleUnitValueMissing => 'Venda da competência sem valor de referência na data da venda',
            self::SaleDiscountPolicyMissing => 'Venda da competência sem política de desconto vigente',
            self::UnitConstructionMismatch => 'Contrato vinculado a empreendimento diferente do da unidade',
            self::SaleNonConform => 'Venda fora da política comercial vigente',
        };
    }
}
