<?php

namespace App\Enums;

/**
 * Em que ponto está a validação da construtora sobre uma versão congelada.
 *
 * Três estados, e nenhum deles é decisão da Gestão. A construtora só declara: o
 * que confere e o que não confere. Aprovar, rejeitar ou devolver é da Fase E, e
 * declarar esses estados aqui convidaria alguém a usá-los antes de a regra
 * existir.
 */
enum SalesBoardBuilderReviewStatus: string
{
    case Draft = 'em_andamento';

    case Submitted = 'enviada';

    case Superseded = 'substituida';

    /**
     * Rascunho é a única fase em que a construtora ainda pode mudar de ideia.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return $this !== self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Em validação',
            self::Submitted => 'Enviada para análise',
            self::Superseded => 'Substituída por nova versão',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Submitted => 'success',
            self::Superseded => 'gray',
        };
    }
}
