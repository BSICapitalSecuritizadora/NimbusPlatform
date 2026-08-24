<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum VacancyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Published => 'Publicada',
            self::Paused => 'Pausada',
            self::Closed => 'Encerrada',
            self::Archived => 'Arquivada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Paused => 'warning',
            self::Closed => 'danger',
            self::Archived => 'gray',
        };
    }

    public function isVisibleForSite(): bool
    {
        return $this === self::Published;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }

    public static function labelFor(self|string|null $status): string
    {
        $original = $status;
        $status = self::fromValue($status);

        if ($status) {
            return $status->label();
        }

        if (blank($original)) {
            return '—';
        }

        return Str::headline((string) $original);
    }

    public static function colorFor(self|string|null $status): string
    {
        return self::fromValue($status)?->color() ?? 'gray';
    }

    public static function fromValue(self|string|null $status): ?self
    {
        if ($status instanceof self) {
            return $status;
        }

        if (blank($status)) {
            return null;
        }

        return self::tryFrom((string) $status);
    }
}
