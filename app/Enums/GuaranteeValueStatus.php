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

    /**
     * Valor apurado, mas sem o Quadro de Vendas da própria competência em algum
     * empreendimento (posição transportada ou ausente). Entra na soma — é a
     * melhor posição conhecida —, mas nunca passa por completo.
     */
    case Partial = 'partial';
    case Pending = 'pending';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automática',
            self::Manual => 'Informado manualmente',
            self::Partial => 'Parcial',
            self::Pending => 'Pendente',
            self::NotApplicable => 'Não aplicável',
        };
    }

    /** O valor pode entrar no somatório da cobertura? */
    public function isResolved(): bool
    {
        return $this === self::Automatic || $this === self::Manual || $this === self::Partial;
    }

    public function color(): string
    {
        return match ($this) {
            self::Automatic => 'success',
            self::Manual => 'info',
            self::Partial => 'warning',
            self::Pending => 'warning',
            self::NotApplicable => 'gray',
        };
    }
}
