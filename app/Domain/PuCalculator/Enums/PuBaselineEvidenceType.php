<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuBaselineEvidenceType: string
{
    case FirstIntegralizationDate = 'first_integralization_date';
    case IntegralizedQuantity = 'integralized_quantity';
    case ExternalPuReference = 'external_pu_reference';

    public function label(): string
    {
        return match ($this) {
            self::FirstIntegralizationDate => 'Data da primeira integralização',
            self::IntegralizedQuantity => 'Quantidade efetivamente integralizada',
            self::ExternalPuReference => 'Gabarito externo independente de PU',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
