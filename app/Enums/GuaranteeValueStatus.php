<?php

namespace App\Enums;

/**
 * Situação do valor de uma garantia numa competência.
 *
 * Existe para o módulo nunca exibir "R$ 0,00" onde a resposta correta é "valor
 * não informado" ou "não aplicável" (§25 do escopo).
 */
enum GuaranteeValueStatus: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Pending = 'pending';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automática',
            self::Manual => 'Informado manualmente',
            self::Pending => 'Pendente',
            self::NotApplicable => 'Não aplicável',
        };
    }

    /** O valor pode entrar no somatório da cobertura? */
    public function isResolved(): bool
    {
        return $this === self::Automatic || $this === self::Manual;
    }

    public function color(): string
    {
        return match ($this) {
            self::Automatic => 'success',
            self::Manual => 'info',
            self::Pending => 'warning',
            self::NotApplicable => 'gray',
        };
    }
}
