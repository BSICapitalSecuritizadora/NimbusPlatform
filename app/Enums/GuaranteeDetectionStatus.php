<?php

namespace App\Enums;

/**
 * Ciclo de vida de uma garantia detectada em documento (§4 do escopo).
 *
 * Nenhuma extração vira garantia oficial sem passar por `Approved`, e o
 * reprocessamento marca candidatas antigas como `Superseded` em vez de
 * apagá-las, preservando o rastro do que foi proposto.
 */
enum GuaranteeDetectionStatus: string
{
    case Suggested = 'suggested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Pendente de revisão',
            self::Approved => 'Confirmada',
            self::Rejected => 'Rejeitada',
            self::Superseded => 'Substituída por novo processamento',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Suggested => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Superseded => 'gray',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Suggested;
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
