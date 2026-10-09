<?php

namespace App\Enums;

/**
 * Situação de uma revisão de medição (R0, R1, R2...) -- independente da etapa
 * do fluxo de cinco etapas da própria revisão.
 *
 * A R0 nasce vigente: a medição original é a autoritativa desde o envio (o
 * avanço físico dela só conta depois da Engenharia aprovada, como sempre). A
 * R1 nasce em rascunho, vai à análise no envio e vira a vigente quando a
 * Compliance aprova -- Engenharia, Gestão e Compliance aprovaram a correção --,
 * substituindo a anterior. Recusada e cancelada nunca substituíram nada; a
 * substituída continua inteira como histórico.
 */
enum MeasurementRevisionStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Effective = 'effective';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::UnderReview => 'Em análise',
            self::Effective => 'Vigente',
            self::Superseded => 'Substituída',
            self::Rejected => 'Recusada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::UnderReview => 'warning',
            self::Effective => 'success',
            self::Superseded => 'info',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /**
     * Rascunho ou em análise: a correção ainda não substituiu a vigente.
     */
    public function isPending(): bool
    {
        return $this === self::Draft || $this === self::UnderReview;
    }

    /**
     * Substituída, recusada ou cancelada: a revisão é só histórico.
     */
    public function isClosed(): bool
    {
        return match ($this) {
            self::Superseded, self::Rejected, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::UnderReview, self::Cancelled],
            self::UnderReview => [self::Effective, self::Rejected],
            self::Effective => [self::Superseded],
            self::Superseded, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
