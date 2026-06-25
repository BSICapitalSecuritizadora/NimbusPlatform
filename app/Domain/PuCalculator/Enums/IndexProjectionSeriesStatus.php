<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Ciclo de vida de uma SÉRIE PROJETADA de número-índice (ex.: curva IPCA de mercado/ANBIMA).
 *
 * A curva operacional só pode usar projeção de uma série APROVADA (maker/checker). Qualquer outro
 * status bloqueia a geração com mensagem clara. A aprovação é uma decisão de negócio (a projeção não é
 * comparada contra gabarito externo), registrada com maker (quem importou) e checker (quem aprovou).
 */
enum IndexProjectionSeriesStatus: string
{
    case Draft = 'draft';
    case Imported = 'imported';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Obsolete = 'obsolete';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Imported => 'Importada',
            self::Approved => 'Aprovada',
            self::Rejected => 'Rejeitada',
            self::Obsolete => 'Obsoleta',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Imported => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Obsolete => 'warning',
        };
    }

    /**
     * Série elegível para uso operacional da curva (projeção aprovada).
     */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Estados a partir dos quais o checker ainda pode aprovar/rejeitar a série.
     */
    public function isPendingDecision(): bool
    {
        return $this === self::Draft || $this === self::Imported;
    }
}
