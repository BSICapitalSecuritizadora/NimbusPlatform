<?php

namespace App\Enums;

/**
 * O que a comparação entre a posição legada e a derivada encontrou.
 *
 * `Different` **não** é erro. Os dois motores podem legitimamente discordar --
 * o legado é digitado e o derivado é apurado unidade a unidade -- e o que a
 * homologação exige não é que coincidam, mas que a diferença seja entendida e
 * registrada por quem entendeu.
 *
 * `NoLegacyPosition` existe para que ausência de histórico nunca vire zero.
 * Comparar contra zero afirmaria que a posição legada era zero, que é uma
 * afirmação diferente de "não havia posição legada".
 */
enum SalesBoardRolloutComparisonStatus: string
{
    case Matched = 'coincide';

    case Different = 'divergente';

    case NoLegacyPosition = 'sem_posicao_legada';

    /**
     * A comparação exige uma justificativa explícita da Gestão?
     */
    public function requiresAcknowledgement(): bool
    {
        return $this !== self::Matched;
    }

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Coincide com o legado',
            self::Different => 'Diverge do legado',
            self::NoLegacyPosition => 'Sem posição legada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Matched => 'success',
            self::Different => 'warning',
            self::NoLegacyPosition => 'info',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Matched => 'A posição derivada reproduz exatamente a posição registrada no legado.',
            self::Different => 'A posição derivada difere da registrada. A diferença precisa ser entendida e aceita antes da homologação.',
            self::NoLegacyPosition => 'Não há posição legada para esta competência. A homologação se apoia apenas nas fontes operacionais.',
        };
    }
}
