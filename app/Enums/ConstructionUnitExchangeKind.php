<?php

namespace App\Enums;

use App\Services\SalesBoards\ConstructionUnitExchangeService;

/**
 * Como uma permuta entrou no sistema.
 *
 * `Baseline` é a posição declarada enquanto a emissão está em elaboração --
 * o ponto de partida contra o qual tudo depois é comparado. `Extraordinary` é
 * a permuta registrada com a operação já em curso, pela Gestão, com motivo e
 * autor ({@see ConstructionUnitExchangeService}).
 */
enum ConstructionUnitExchangeKind: string
{
    case Baseline = 'baseline';

    case Extraordinary = 'extraordinary';

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'Posição inicial',
            self::Extraordinary => 'Permuta extraordinária',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Baseline => 'gray',
            self::Extraordinary => 'warning',
        };
    }
}
