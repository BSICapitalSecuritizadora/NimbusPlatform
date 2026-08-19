<?php

namespace App\Enums;

enum ObligationFrequency: string
{
    case Once = 'once';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';
    case OnDemand = 'on_demand';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $frequency): array => [$frequency->value => $frequency->label()])
            ->all();
    }

    /** @return array<string, string> */
    public static function seriesOptions(): array
    {
        return collect(self::cases())
            ->reject(fn (self $frequency): bool => $frequency === self::Once)
            ->mapWithKeys(fn (self $frequency): array => [$frequency->value => $frequency->label()])
            ->all();
    }

    public static function fromLegacyLabel(?string $label): ?self
    {
        return match (mb_strtolower(trim((string) $label))) {
            'única', 'unica', 'único', 'unico' => self::Once,
            'mensal' => self::Monthly,
            'trimestral' => self::Quarterly,
            'semestral' => self::Semiannual,
            'anual' => self::Annual,
            'sob demanda' => self::OnDemand,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Única',
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Semiannual => 'Semestral',
            self::Annual => 'Anual',
            self::OnDemand => 'Sob demanda',
        };
    }

    public function intervalInMonths(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Annual => 12,
            self::Once, self::OnDemand => null,
        };
    }

    public function generatesAutomatically(): bool
    {
        return $this->intervalInMonths() !== null;
    }
}
