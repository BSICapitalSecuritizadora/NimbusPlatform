<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum VacancyWorkModel: string
{
    case Onsite = 'onsite';
    case Hybrid = 'hybrid';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Onsite => 'Presencial',
            self::Hybrid => 'Híbrido',
            self::Remote => 'Remoto',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Onsite => 'primary',
            self::Hybrid => 'warning',
            self::Remote => 'success',
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

    public static function labelFor(self|string|null $model): string
    {
        $resolved = self::fromValue($model);

        if ($resolved) {
            return $resolved->label();
        }

        if (blank($model)) {
            return '—';
        }

        return Str::headline((string) $model);
    }

    public static function fromValue(self|string|null $model): ?self
    {
        if ($model instanceof self) {
            return $model;
        }

        if (blank($model)) {
            return null;
        }

        return self::tryFrom((string) $model);
    }
}
