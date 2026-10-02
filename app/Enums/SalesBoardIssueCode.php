<?php

namespace App\Enums;

use App\Support\SalesBoards\SalesBoardIssuePresenter;

/**
 * O que impede -- ou apenas contamina -- a derivação de uma posição.
 *
 * Os valores são identificadores estáveis, gravados como texto: os códigos de
 * bloqueio da prontidão (`last_blocker_codes`, `blocker_codes`) e os avisos
 * congelados com cada versão e com cada homologação (`warnings`). Renomear um
 * valor é migração de dado, não refatoração. Caso novo precisa de rótulo
 * ({@see self::label()}), severidade ({@see self::severity()}), dica em
 * {@see SalesBoardIssuePresenter} e a decisão de
 * {@see self::isVisibleToBuilder()} -- o teste de catálogo cobra as quatro.
 *
 * A severidade separa duas coisas que é fácil confundir. Um bloqueador é
 * *ausência de dado*: o Nimbus não consegue explicar um número -- ou o número
 * que explicaria está fora de qualquer escala plausível e publicá-lo como
 * completo seria pior do que não publicar. Um aviso é um *fato desagradável já
 * apurado* -- uma venda fora da política é uma irregularidade que a derivação
 * detectou justamente porque funcionou, e bloquear por causa dela esconderia o
 * achado.
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

    case SettlementStatusDivergence = 'SETTLEMENT_STATUS_DIVERGENCE';

    case FutureSaleDate = 'FUTURE_SALE_DATE';

    case CancelledContractWithoutDate = 'CANCELLED_CONTRACT_WITHOUT_DATE';

    case ExchangeValueMissing = 'EXCHANGE_VALUE_MISSING';

    case SaleValueOutOfScale = 'SALE_VALUE_OUT_OF_SCALE';

    case InstallmentValueOutOfScale = 'INSTALLMENT_VALUE_OUT_OF_SCALE';

    case SourceDateBefore1990 = 'SOURCE_DATE_BEFORE_1990';

    case SaleValueAtypical = 'SALE_VALUE_ATYPICAL';

    case InstallmentValueAtypical = 'INSTALLMENT_VALUE_ATYPICAL';

    case UnderpaidInstallments = 'UNDERPAID_INSTALLMENTS';

    case PaymentDateInFuture = 'PAYMENT_DATE_IN_FUTURE';

    /**
     * A unidade deixou de compor o Quadro nesta competência pela baixa
     * registrada -- só na competência em que a presença muda, não em todas as
     * seguintes.
     */
    case UnitRetired = 'UNIT_RETIRED';

    /**
     * A unidade voltou a compor o Quadro nesta competência pela reativação.
     */
    case UnitReactivated = 'UNIT_REACTIVATED';

    /**
     * A unidade está baixada na data da posição, mas um contrato ou uma
     * permuta a ocupa. A unidade não some calada: vira linha indeterminada.
     */
    case RetiredUnitInUse = 'RETIRED_UNIT_IN_USE';

    /**
     * Venda de competência já fechada, lançada depois (extemporânea), ou venda
     * publicada com valor ou data revistos, abaixo do preço mínimo autorizado
     * na data da venda. Vira pendência da Gestão, e não bloqueia: um problema
     * num mês fechado não segura a apuração do mês corrente.
     */
    case LateSaleNonConform = 'LATE_SALE_NON_CONFORM';

    /**
     * A mesma venda extemporânea ou revista, sem conformidade determinável na
     * data da venda. Também vira pendência da Gestão, e também não bloqueia.
     */
    case LateSaleUndetermined = 'LATE_SALE_UNDETERMINED';

    /**
     * A unidade está diferente da posição congelada da competência anterior, na
     * data dela, sem venda, distrato ou quitação datados que expliquem --
     * estorno de quitação, data de venda movida, permuta, inclusão ou saída do
     * inventário. Aparece na "Ponte com a competência anterior".
     */
    case UnexplainedReclassification = 'UNEXPLAINED_RECLASSIFICATION';

    /**
     * Os avisos são fatos apurados sobre dados que existem: a venda fora da
     * política, o status do contrato que contradiz o cronograma, a venda datada
     * no futuro, a venda bem acima da tabela, a parcela de valor atípico, o
     * pagamento abaixo do previsto que segura a quitação, o pagamento com data
     * futura, a unidade baixada ou reativada na competência, a venda de
     * competência anterior fora da política ou sem conformidade determinável e a
     * unidade que mudou na data da competência anterior sem movimento que
     * explique. Nenhum deles impede a apuração -- o Quadro segue a fonte
     * temporal -- e por isso saem à parte dos bloqueadores, nos avisos da
     * posição (`warnings()`) e da prontidão (`warningCounts()`), e ficam
     * congelados com a versão. A unidade baixada que continua ocupada é
     * bloqueador: a posição dela não tem resposta.
     */
    public function severity(): SalesBoardIssueSeverity
    {
        return match ($this) {
            self::SaleNonConform,
            self::SettlementStatusDivergence,
            self::FutureSaleDate,
            self::SaleValueAtypical,
            self::InstallmentValueAtypical,
            self::UnderpaidInstallments,
            self::PaymentDateInFuture,
            self::UnitRetired,
            self::UnitReactivated,
            self::LateSaleNonConform,
            self::LateSaleUndetermined,
            self::UnexplainedReclassification => SalesBoardIssueSeverity::Warning,
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
            self::SettlementStatusDivergence => 'Status do contrato diverge da quitação apurada pelo cronograma',
            self::FutureSaleDate => 'Contrato que ocupa a unidade com data de venda futura',
            self::CancelledContractWithoutDate => 'Contrato distratado sem data do distrato',
            self::ExchangeValueMissing => 'Permuta vigente sem valor',
            self::SaleValueOutOfScale => 'Valor da venda fora da escala do valor da unidade',
            self::InstallmentValueOutOfScale => 'Parcela com valor fora da escala do contrato',
            self::SourceDateBefore1990 => 'Data anterior a 1990 na fonte',
            self::SaleValueAtypical => 'Venda muito acima do valor de referência',
            self::InstallmentValueAtypical => 'Parcela com valor atípico',
            self::UnderpaidInstallments => 'Parcelas pagas abaixo do previsto mantêm o contrato financiado',
            self::PaymentDateInFuture => 'Parcela com data de pagamento futura',
            self::UnitRetired => 'Unidade baixada nesta competência',
            self::UnitReactivated => 'Unidade reativada nesta competência',
            self::RetiredUnitInUse => 'Unidade baixada com contrato ou permuta vigente na data',
            self::LateSaleNonConform => 'Venda de competência anterior fora da política comercial',
            self::LateSaleUndetermined => 'Venda de competência anterior sem conformidade determinável',
            self::UnexplainedReclassification => 'Unidade diferente da posição da competência anterior, sem movimento que explique',
        };
    }

    /**
     * Se o aviso é sobre o dado da própria construtora e pode ser mostrado a ela
     * na Validação.
     *
     * A superfície da construtora é desenhada para um canal externo: sem
     * política comercial interna e sem vocabulário técnico. Entram os pontos que
     * ela consegue conferir na própria carteira -- o status do contrato, a data e
     * o valor da venda, o valor e a data das parcelas, a unidade que saiu ou
     * voltou ao Quadro pela baixa, a unidade que mudou na data da competência
     * anterior sem movimento que explique. A venda fora da política -- do mês
     * ou de competência anterior -- fica de fora: é a régua interna da Gestão,
     * e ela já chega à Análise como pendência.
     *
     * Bloqueadores nunca chegam a uma versão congelada (a geração recusa a fonte
     * incompleta), e por isso respondem `false`. Sem `default` de propósito: um
     * caso novo sem decisão quebra aqui, e não vaza.
     */
    public function isVisibleToBuilder(): bool
    {
        return match ($this) {
            self::SettlementStatusDivergence,
            self::FutureSaleDate,
            self::SaleValueAtypical,
            self::InstallmentValueAtypical,
            self::UnderpaidInstallments,
            self::PaymentDateInFuture,
            self::UnitRetired,
            self::UnitReactivated,
            self::UnexplainedReclassification => true,

            self::SaleNonConform,
            self::LateSaleNonConform,
            self::LateSaleUndetermined,
            self::NoConstructionUnits,
            self::AmbiguousOccupancy,
            self::AmbiguousExchange,
            self::ExchangeOccupancyConflict,
            self::ExchangeSourceMissing,
            self::SettlementUndetermined,
            self::UnitValueMissing,
            self::SaleUnitValueMissing,
            self::SaleDiscountPolicyMissing,
            self::UnitConstructionMismatch,
            self::CancelledContractWithoutDate,
            self::ExchangeValueMissing,
            self::SaleValueOutOfScale,
            self::InstallmentValueOutOfScale,
            self::SourceDateBefore1990,
            self::RetiredUnitInUse => false,
        };
    }
}
