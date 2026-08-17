<?php

namespace App\Enums;

/**
 * Confiança da extração documental (§37 do escopo). Informação de baixa
 * confiança recebe destaque na revisão em vez de ser descartada em silêncio.
 */
enum GuaranteeConfidenceLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Alta',
            self::Medium => 'Média',
            self::Low => 'Baixa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::High => 'success',
            self::Medium => 'warning',
            self::Low => 'danger',
        };
    }

    public static function fromScore(?float $score): ?self
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 0.8 => self::High,
            $score >= 0.6 => self::Medium,
            default => self::Low,
        };
    }
}
