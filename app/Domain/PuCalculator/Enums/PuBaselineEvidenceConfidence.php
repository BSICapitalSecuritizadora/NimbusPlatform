<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Régua única de confiança das evidências do baseline de PU.
 *
 * A coluna continua gravando a string ('high', 'medium', 'low') porque o gate
 * de prontidão e o fechamento do Alto Bellevue comparam o valor cru; o enum
 * existe para que o limiar numérico e o rótulo tenham uma só definição.
 */
enum PuBaselineEvidenceConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public const HIGH_SCORE = 0.85;

    public const MEDIUM_SCORE = 0.60;

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= self::HIGH_SCORE => self::High,
            $score >= self::MEDIUM_SCORE => self::Medium,
            default => self::Low,
        };
    }

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
            self::Low => 'gray',
        };
    }

    /** A menor das duas: um critério nunca promove o que o outro rebaixou. */
    public function cappedBy(self $other): self
    {
        return $this->rank() <= $other->rank() ? $this : $other;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $confidence): array => [$confidence->value => $confidence->label()])
            ->all();
    }

    private function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
