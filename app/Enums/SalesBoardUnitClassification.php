<?php

namespace App\Enums;

use App\DTOs\SalesBoards\SalesBoardDerivedLine;

/**
 * Em que balde do Quadro de Vendas uma unidade caiu numa data.
 *
 * `Undetermined` é um balde de primeira classe, não um erro de programação: uma
 * unidade com dois contratos ocupantes ou com quitação impossível de decidir não
 * pode ser empurrada para nenhum dos outros quatro sem inventar um número. Ela
 * aparece, é contada à parte e bloqueia a automação da competência.
 */
enum SalesBoardUnitClassification: string
{
    case Stock = 'estoque';

    case Financed = 'financiado';

    case Settled = 'quitado';

    case Exchanged = 'permutado';

    case Undetermined = 'indeterminado';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Estoque',
            self::Financed => 'Financiado',
            self::Settled => 'Quitado',
            self::Exchanged => 'Permutado',
            self::Undetermined => 'Indeterminado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Stock => 'gray',
            self::Financed => 'info',
            self::Settled => 'success',
            self::Exchanged => 'warning',
            self::Undetermined => 'danger',
        };
    }

    /**
     * Critério do valor que a unidade leva para o seu balde.
     *
     * É a definição de {@see SalesBoardDerivedLine::bucketValueCents()} em
     * texto: as telas e o formulário legado repetem esta frase para que o
     * número registrado à mão e o publicado pela automação signifiquem a mesma
     * coisa. O balde financiado não é saldo devedor da carteira.
     */
    public function valueCriterion(): string
    {
        return match ($this) {
            self::Stock => 'Valor de referência (tabela) de cada unidade em estoque na competência',
            self::Financed => 'Preço de venda de cada contrato vendido e ainda não quitado',
            self::Settled => 'Preço de venda de cada contrato já quitado',
            self::Exchanged => 'Valor atribuído a cada permuta',
            self::Undetermined => 'Sem valor: a unidade fica fora dos quatro grupos até ser resolvida',
        };
    }

    /**
     * Os quatro baldes que compõem a posição publicável. `Undetermined` fica de
     * fora porque não é uma posição, é a ausência dela.
     *
     * @return list<self>
     */
    public static function resolvedCases(): array
    {
        return [self::Stock, self::Financed, self::Settled, self::Exchanged];
    }

    public function isResolved(): bool
    {
        return $this !== self::Undetermined;
    }
}
