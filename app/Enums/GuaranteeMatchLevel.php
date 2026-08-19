<?php

namespace App\Enums;

/**
 * Força da correspondência entre uma garantia detectada e uma já cadastrada.
 *
 * Distinta da confiança da extração ({@see GuaranteeConfidenceLevel}): a
 * extração pode ler a conta com certeza absoluta e ainda assim ser incerto se
 * essa conta pertence à garantia "Reserva de Obras" já registrada.
 */
enum GuaranteeMatchLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Alta',
            self::Medium => 'Média',
            self::Low => 'Baixa',
            self::None => 'Nenhuma',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::High => 'success',
            self::Medium => 'warning',
            self::Low, self::None => 'gray',
        };
    }

    /**
     * A correspondência é forte o suficiente para o sistema propor
     * complementar em vez de criar?
     *
     * Correspondência baixa não vira sugestão de complemento: enriquecer a
     * garantia errada é mais caro de desfazer do que cadastrar uma a mais.
     */
    public function suggestsConsolidation(): bool
    {
        return $this === self::High || $this === self::Medium;
    }

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 0.8 => self::High,
            $score >= 0.55 => self::Medium,
            $score > 0.0 => self::Low,
            default => self::None,
        };
    }
}
