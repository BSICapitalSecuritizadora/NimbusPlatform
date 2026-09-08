<?php

namespace App\Enums;

/**
 * O que a construtora está declarando quando discorda do snapshot.
 *
 * Tipado, e não texto livre, porque a Fase E vai precisar consolidar isso em não
 * conformidades e decidir sobre cada uma. Um campo de observação livre obrigaria
 * alguém a ler trezentas frases e adivinhar a intenção de cada uma; um tipo
 * fechado diz de saída qual é a pergunta.
 *
 * `SaleMismatch` foi deliberadamente quebrado em valor, data e contrato. Os três
 * são discordâncias diferentes, com campos obrigatórios diferentes, e um tipo
 * único obrigaria a validação a aceitar qualquer combinação -- perdendo
 * justamente a garantia de que a declaração está completa.
 *
 * Cada caso carrega as próprias regras: em que seção pode ser aberto, em que
 * fato congelado precisa se ancorar e o que a construtora precisa informar. Quem
 * aplica essas regras é o {@see App\Services\SalesBoards\SalesBoardBuilderDivergenceValidator};
 * aqui elas só são declaradas.
 */
enum SalesBoardBuilderDivergenceType: string
{
    case SaleMissing = 'venda_ausente';

    case SaleExtra = 'venda_inexistente';

    case SaleValueMismatch = 'valor_da_venda_divergente';

    case SaleDateMismatch = 'data_da_venda_divergente';

    case SaleContractMismatch = 'contrato_da_venda_divergente';

    case SettlementMismatch = 'quitacao_divergente';

    case CancellationMismatch = 'distrato_divergente';

    case StockMismatch = 'classificacao_divergente';

    case ExchangeMismatch = 'permuta_divergente';

    case UnitValueMismatch = 'valor_de_referencia_divergente';

    case Other = 'outra';

    /**
     * Em que seções este tipo pode ser aberto.
     *
     * @return list<SalesBoardBuilderReviewSection>
     */
    public function sections(): array
    {
        $position = [
            SalesBoardBuilderReviewSection::PositionStock,
            SalesBoardBuilderReviewSection::PositionFinanced,
            SalesBoardBuilderReviewSection::PositionSettled,
            SalesBoardBuilderReviewSection::PositionExchanged,
        ];

        return match ($this) {
            self::SaleMissing,
            self::SaleExtra,
            self::SaleValueMismatch,
            self::SaleDateMismatch,
            self::SaleContractMismatch => [SalesBoardBuilderReviewSection::MovementSales],
            self::SettlementMismatch => [
                SalesBoardBuilderReviewSection::MovementSettlements,
                SalesBoardBuilderReviewSection::PositionSettled,
                SalesBoardBuilderReviewSection::PositionFinanced,
            ],
            self::CancellationMismatch => [
                SalesBoardBuilderReviewSection::MovementCancellations,
                SalesBoardBuilderReviewSection::PositionStock,
            ],
            self::StockMismatch, self::ExchangeMismatch => $position,
            self::UnitValueMismatch => [...$position, SalesBoardBuilderReviewSection::MovementSales],
            self::Other => SalesBoardBuilderReviewSection::ordered(),
        };
    }

    /**
     * O movimento congelado em que a declaração precisa se ancorar, se houver.
     */
    public function requiredMovementType(): ?SalesBoardMovementType
    {
        return match ($this) {
            self::SaleExtra,
            self::SaleValueMismatch,
            self::SaleDateMismatch,
            self::SaleContractMismatch => SalesBoardMovementType::Sale,
            default => null,
        };
    }

    /**
     * A unidade congelada em que a declaração precisa se ancorar.
     */
    public function requiresLine(): bool
    {
        return in_array($this, [self::StockMismatch, self::ExchangeMismatch], true);
    }

    /**
     * Precisa de alguma âncora -- linha **ou** movimento -- sem exigir qual.
     *
     * É o caso das divergências que podem ser abertas dos dois lados: negar uma
     * quitação que o Nimbus mostrou (movimento) ou negar que a unidade estivesse
     * quitada no fechamento (linha) são a mesma discordância vista de ângulos
     * diferentes.
     */
    public function requiresAnyAnchor(): bool
    {
        return in_array($this, [
            self::SettlementMismatch,
            self::CancellationMismatch,
            self::UnitValueMismatch,
        ], true);
    }

    /**
     * Campos que a construtora é obrigada a declarar.
     *
     * @return list<string>
     */
    public function requiredDeclarations(): array
    {
        return match ($this) {
            /**
             * Venda ausente é o único caso sem âncora possível: o fato que a
             * construtora afirma não está no snapshot. Então ela precisa
             * descrevê-lo por inteiro -- qual unidade, quando e por quanto --
             * senão não há o que a Gestão investigue.
             */
            self::SaleMissing => ['declared_unit', 'declared_date', 'declared_value'],
            self::SaleValueMismatch => ['declared_value'],
            self::SaleDateMismatch => ['declared_date'],
            self::SaleContractMismatch => ['declared_contract_code'],
            self::StockMismatch => ['declared_classification'],
            self::UnitValueMismatch => ['declared_value'],
            default => [],
        };
    }

    /**
     * Campos dos quais ao menos um precisa vir preenchido.
     *
     * @return list<string>
     */
    public function requiredAnyDeclarations(): array
    {
        return match ($this) {
            self::ExchangeMismatch => ['declared_classification', 'declared_value'],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SaleMissing => 'Venda não apresentada',
            self::SaleExtra => 'Venda que não deveria constar',
            self::SaleValueMismatch => 'Valor da venda divergente',
            self::SaleDateMismatch => 'Data da venda divergente',
            self::SaleContractMismatch => 'Contrato da venda divergente',
            self::SettlementMismatch => 'Quitação divergente',
            self::CancellationMismatch => 'Distrato divergente',
            self::StockMismatch => 'Classificação divergente',
            self::ExchangeMismatch => 'Permuta divergente',
            self::UnitValueMismatch => 'Valor de referência divergente',
            self::Other => 'Outra divergência',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SaleMissing, self::SaleExtra => 'danger',
            self::Other => 'gray',
            default => 'warning',
        };
    }
}
