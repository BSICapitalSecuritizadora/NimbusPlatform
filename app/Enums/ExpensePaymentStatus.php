<?php

namespace App\Enums;

enum ExpensePaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Pago',
            self::Pending => 'Pendente',
            self::Overdue => 'Vencido',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Pending => 'warning',
            self::Overdue => 'danger',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Paid => 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-300',
            self::Pending => 'border border-amber-500/30 bg-amber-500/15 text-amber-300',
            self::Overdue => 'border border-rose-500/30 bg-rose-500/15 text-rose-300',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Paid => 'bg-emerald-400',
            self::Pending => 'bg-amber-400',
            self::Overdue => 'bg-rose-400',
        };
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
}
