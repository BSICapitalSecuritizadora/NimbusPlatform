<?php

namespace App\Enums;

/**
 * Situação de um valor extraído para um campo do instrumento.
 *
 * Este enum é o que transforma a tabela de campos em fila de revisão: uma linha
 * `PendingReview` **é** a proposta de alteração (§20 e §21 do escopo). Confirmar
 * promove a linha a `Confirmed` e rebaixa a anterior a `Superseded`; nada é
 * apagado, e por isso o histórico e a consulta retroativa saem de graça.
 */
enum LegalInstrumentFieldStatus: string
{
    case PendingReview = 'pending_review';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pendente de revisão',
            self::Confirmed => 'Vigente',
            self::Rejected => 'Rejeitada',
            self::Superseded => 'Substituída',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::PendingReview => 'warning',
            self::Rejected => 'danger',
            self::Superseded => 'gray',
        };
    }

    /** O valor compõe a posição vigente? */
    public function countsTowardPosition(): bool
    {
        return $this === self::Confirmed;
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
