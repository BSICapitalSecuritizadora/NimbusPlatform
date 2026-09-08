<?php

namespace App\Enums;

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
