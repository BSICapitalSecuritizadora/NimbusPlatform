<?php

namespace App\Enums;

/**
 * As sete seções em que a construtora valida uma competência.
 *
 * A validação é por seção, e não por unidade, por uma razão prática: um
 * empreendimento tem centenas de unidades, e pedir uma confirmação para cada uma
 * transformaria a revisão num exercício de rolagem que ninguém faz com atenção.
 * A construtora confirma um bloco inteiro -- "o estoque confere" -- e aponta as
 * exceções, que é como a conferência acontece de verdade.
 *
 * Quatro seções espelham os baldes da posição no fechamento e três espelham as
 * movimentações do mês. Juntas cobrem tudo o que o snapshot congelou: nenhum
 * fato apresentado à construtora fica fora de alguma seção.
 */
enum SalesBoardBuilderReviewSection: string
{
    case PositionStock = 'posicao_estoque';

    case PositionFinanced = 'posicao_financiado';

    case PositionSettled = 'posicao_quitado';

    case PositionExchanged = 'posicao_permutado';

    case MovementSales = 'movimento_vendas';

    case MovementSettlements = 'movimento_quitacoes';

    case MovementCancellations = 'movimento_distratos';

    /**
     * A ordem em que a revisão é apresentada: primeiro a posição no fechamento,
     * depois o que aconteceu no mês.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::PositionStock,
            self::PositionFinanced,
            self::PositionSettled,
            self::PositionExchanged,
            self::MovementSales,
            self::MovementSettlements,
            self::MovementCancellations,
        ];
    }

    /**
     * O balde do snapshot que esta seção apresenta, quando ela é de posição.
     */
    public function classification(): ?SalesBoardUnitClassification
    {
        return match ($this) {
            self::PositionStock => SalesBoardUnitClassification::Stock,
            self::PositionFinanced => SalesBoardUnitClassification::Financed,
            self::PositionSettled => SalesBoardUnitClassification::Settled,
            self::PositionExchanged => SalesBoardUnitClassification::Exchanged,
            default => null,
        };
    }

    /**
     * O tipo de movimento que esta seção apresenta, quando ela é de movimento.
     */
    public function movementType(): ?SalesBoardMovementType
    {
        return match ($this) {
            self::MovementSales => SalesBoardMovementType::Sale,
            self::MovementSettlements => SalesBoardMovementType::Settlement,
            self::MovementCancellations => SalesBoardMovementType::Cancellation,
            default => null,
        };
    }

    public function isPosition(): bool
    {
        return $this->classification() !== null;
    }

    public function label(): string
    {
        return match ($this) {
            self::PositionStock => 'Estoque',
            self::PositionFinanced => 'Financiado',
            self::PositionSettled => 'Quitado',
            self::PositionExchanged => 'Permutado',
            self::MovementSales => 'Vendas do mês',
            self::MovementSettlements => 'Quitações do mês',
            self::MovementCancellations => 'Distratos do mês',
        };
    }

    public function group(): string
    {
        return $this->isPosition() ? 'Posição no fechamento' : 'Movimentações do mês';
    }

    public function description(): string
    {
        return match ($this) {
            self::PositionStock => 'Unidades que estavam disponíveis no fechamento da competência.',
            self::PositionFinanced => 'Unidades vendidas com saldo em aberto no fechamento.',
            self::PositionSettled => 'Unidades cujo contrato estava integralmente pago no fechamento.',
            self::PositionExchanged => 'Unidades dadas em permuta e vigentes no fechamento.',
            self::MovementSales => 'Vendas realizadas dentro da competência.',
            self::MovementSettlements => 'Contratos que passaram a estar quitados dentro da competência.',
            self::MovementCancellations => 'Distratos ocorridos dentro da competência.',
        };
    }
}
