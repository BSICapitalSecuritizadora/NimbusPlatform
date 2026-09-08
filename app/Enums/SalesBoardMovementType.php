<?php

namespace App\Enums;

/**
 * O que aconteceu com um contrato dentro da competência congelada.
 *
 * Movimento não é posição. Uma venda feita e distratada no mesmo mês não aparece
 * em nenhum balde no fechamento, mas produziu dois movimentos -- e um snapshot
 * que guardasse só a posição final apagaria o mês inteiro dessa unidade.
 *
 * Não há caso para permuta extraordinária: ela ainda não existe como movimento
 * no motor, e declarar aqui um tipo que nenhum caminho de código produz seria
 * schema morto.
 */
enum SalesBoardMovementType: string
{
    case Sale = 'venda';

    case Settlement = 'quitacao';

    case Cancellation = 'distrato';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venda',
            self::Settlement => 'Quitação',
            self::Cancellation => 'Distrato',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sale => 'info',
            self::Settlement => 'success',
            self::Cancellation => 'danger',
        };
    }
}
