<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Resultado da reconciliação entre as fontes financeiras para UMA data.
 *
 * Nenhum estado decide sozinho: a reconciliação classifica e para. Não há voto de maioria nem
 * desempate automático — calendário financeiro é decisão governada, e `Conflict` existe justamente
 * para que a divergência apareça em vez de ser resolvida em silêncio.
 */
enum CalendarSourceReconciliationStatus: string
{
    /** Ambas as fontes afirmam a mesma decisão para a data. Única classe elegível a virar decisão. */
    case Confirmed = 'confirmed';

    /** Só a ANBIMA conhece a data. Não é conflito: a FEBRABAN pode simplesmente não publicá-la. */
    case SourceOnlyAnbima = 'source_only_anbima';

    /** Só a FEBRABAN conhece a data. */
    case SourceOnlyFebraban = 'source_only_febraban';

    /** As fontes afirmam decisões opostas para a data. Exige decisão humana. */
    case Conflict = 'conflict';

    /** Nenhuma fonte cobre a data (ou o ano não foi importado). Ausência não é confirmação. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmado pelas duas fontes',
            self::SourceOnlyAnbima => 'Somente ANBIMA',
            self::SourceOnlyFebraban => 'Somente FEBRABAN',
            self::Conflict => 'Conflito entre fontes',
            self::Unknown => 'Sem cobertura',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::SourceOnlyAnbima, self::SourceOnlyFebraban => 'warning',
            self::Conflict => 'danger',
            self::Unknown => 'gray',
        };
    }

    /**
     * Elegibilidade a virar decisão do calendário consolidado. Somente `Confirmed` qualifica; todo o
     * resto exige curadoria explícita antes de qualquer materialização.
     */
    public function isEligibleForMaterialization(): bool
    {
        return $this === self::Confirmed;
    }

    public function requiresHumanDecision(): bool
    {
        return $this === self::Conflict;
    }
}
