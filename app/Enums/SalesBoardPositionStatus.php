<?php

namespace App\Enums;

/**
 * Como a posição comercial de um empreendimento numa competência foi obtida.
 *
 * `NotYetPositioned` e `Unpositioned` são deliberadamente distintos: o primeiro
 * é um empreendimento cujo primeiro quadro só existe depois da competência
 * consultada — cobrar dele uma posição que ainda não existia inventaria uma
 * lacuna. O segundo nunca teve quadro nenhum, e aí a lacuna é real.
 */
enum SalesBoardPositionStatus: string
{
    case Current = 'current';
    case CarriedForward = 'carried_forward';
    case NotYetPositioned = 'not_yet_positioned';
    case Unpositioned = 'unpositioned';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Quadro da competência',
            self::CarriedForward => 'Última posição conhecida',
            self::NotYetPositioned => 'Sem quadro até a competência',
            self::Unpositioned => 'Sem quadro de vendas',
        };
    }

    /**
     * A posição existe e entra na soma da emissão.
     */
    public function isResolved(): bool
    {
        return match ($this) {
            self::Current, self::CarriedForward => true,
            default => false,
        };
    }

    /**
     * A emissão deveria ter uma posição deste empreendimento na competência.
     *
     * Um empreendimento que só passou a ter quadro depois da competência não é
     * cobrado — ver {@see self::NotYetPositioned}.
     */
    public function isExpected(): bool
    {
        return $this !== self::NotYetPositioned;
    }
}
