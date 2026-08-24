<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum VacancyEmploymentType: string
{
    case CLT = 'CLT';
    case CLTHibrido = 'CLT Híbrido';
    case PJ = 'PJ';
    case Estagio = 'Estágio';
    case Freelance = 'Freelance';
    case Temporario = 'Temporário';

    public function label(): string
    {
        return $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::CLT, self::CLTHibrido => 'primary',
            self::PJ => 'info',
            self::Estagio => 'warning',
            self::Freelance => 'gray',
            self::Temporario => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public static function labelFor(self|string|null $type): string
    {
        $resolved = self::fromValue($type);

        if ($resolved) {
            return $resolved->label();
        }

        if (blank($type)) {
            return '—';
        }

        return Str::headline((string) $type);
    }

    public static function fromValue(self|string|null $type): ?self
    {
        if ($type instanceof self) {
            return $type;
        }

        if (blank($type)) {
            return null;
        }

        return self::tryFrom((string) $type);
    }

    /**
     * Normalize legacy values (case-insensitive) to the enum or null.
     */
    public static function normalize(?string $value): ?self
    {
        if (blank($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $trimmed) === 0) {
                return $case;
            }
        }

        // Legacy fallback mappings
        return match (mb_strtolower($trimmed)) {
            'clt hibrido', 'clt híbrido', 'clt-hibrido' => self::CLTHibrido,
            'estagio', 'estágio' => self::Estagio,
            'temporario', 'temporário' => self::Temporario,
            default => null,
        };
    }
}
