<?php

namespace App\Enums;

/**
 * Lifecycle of the commercial relationship between a client and a unit.
 *
 * Deliberately small: only states with a real rule behind them. A settled or
 * permutada unit still holds its contract -- only a distrato releases it for a
 * resale.
 */
enum ContractStatus: string
{
    case Active = 'ativo';

    case Settled = 'quitado';

    case Exchanged = 'permutado';

    case Cancelled = 'distratado';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Settled => 'Quitado',
            self::Exchanged => 'Permutado',
            self::Cancelled => 'Distratado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Settled => 'info',
            self::Exchanged => 'warning',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Whether a contract in this status still ties the unit to its buyer, and
     * therefore blocks another one from being opened for the same unit.
     */
    public function occupiesUnit(): bool
    {
        return $this !== self::Cancelled;
    }

    public function requiresCancellationDate(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * Values whose contracts hold the unit. Mirrored by the generated column
     * that backs the single-live-contract index.
     *
     * @return list<string>
     */
    public static function occupyingValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->occupiesUnit()),
        ));
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

    /**
     * Resolves the spellings accepted by the import spreadsheet.
     */
    public static function tryFromLabel(?string $value): ?self
    {
        $normalized = mb_strtolower(trim((string) $value));

        return match ($normalized) {
            'ativo', 'ativa', 'vigente', 'active' => self::Active,
            'quitado', 'quitada', 'pago', 'paga', 'liquidado', 'settled' => self::Settled,
            'permutado', 'permutada', 'permuta', 'exchanged' => self::Exchanged,
            'distratado', 'distratada', 'distrato', 'cancelado', 'cancelada', 'rescindido', 'cancelled' => self::Cancelled,
            default => null,
        };
    }
}
