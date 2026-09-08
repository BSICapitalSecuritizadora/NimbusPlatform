<?php

namespace App\Enums;

/**
 * O que a construtora disse sobre uma seção da posição.
 *
 * `Pending` não é "sem divergência": é "ninguém olhou ainda". Manter os dois
 * separados é o que permite bloquear uma submissão incompleta -- uma seção que
 * nunca foi aberta não pode ser lida como concordância silenciosa.
 */
enum SalesBoardBuilderReviewSectionStatus: string
{
    case Pending = 'pendente';

    case Confirmed = 'confirmada';

    case Divergent = 'divergente';

    /**
     * A seção já recebeu uma resposta -- qualquer que tenha sido.
     */
    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente de validação',
            self::Confirmed => 'Confirmada',
            self::Divergent => 'Com divergência',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Confirmed => 'success',
            self::Divergent => 'warning',
        };
    }
}
