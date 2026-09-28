<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Por que a data efetiva de um evento de PU difere da data original do contrato.
 */
enum PuEventDateChangeReason: string
{
    case Weekend = 'weekend';
    case Holiday = 'holiday';
    case IssuerDecision = 'issuer_decision';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Weekend => 'Fim de semana',
            self::Holiday => 'Feriado sem expediente no mercado',
            self::IssuerDecision => 'Decisão da securitizadora ou assembleia',
            self::Other => 'Outro motivo',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }
}
