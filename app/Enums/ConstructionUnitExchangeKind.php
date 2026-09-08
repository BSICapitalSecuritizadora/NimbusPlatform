<?php

namespace App\Enums;

/**
 * Como uma permuta entrou no sistema.
 *
 * `Baseline` é a posição declarada enquanto a emissão está em elaboração --
 * o ponto de partida contra o qual tudo depois é comparado. `Extraordinary`
 * existe para a permuta que acontece com a operação já em curso, e o workflow
 * dela (contrato, evidência, não conformidade, Gestão) é das fases seguintes:
 * nesta fase nenhuma tela cria uma.
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
