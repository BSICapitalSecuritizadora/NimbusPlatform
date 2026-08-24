<?php

namespace App\Enums;

/**
 * State of a contract installment, always derived, never stored.
 *
 * A status written to a column would be a lie the morning after: "a vencer"
 * becomes "em atraso" with nothing moving but the calendar. So it is computed
 * from the installment's own dates and values every time it is read.
 *
 * The cases are declared in the order the rules are applied, and they are
 * mutually exclusive -- one installment is in exactly one state, which is what
 * lets a badge and a filter agree with each other.
 *
 * Two orderings deserve the explanation:
 *
 * - Cancelada wins over everything, including a receipt. An installment that
 *   left the contractual flow is out of the flow even if money once landed on
 *   it.
 * - Em atraso wins over Parcialmente paga. A half paid installment past its due
 *   date reads as overdue, because the delay is the fact the operation acts on;
 *   the partial receipt is visible in the saldo next to it. Parcialmente paga is
 *   therefore what a partially received installment reads as only while it is
 *   still in time.
 */
enum ContractInstallmentStatus: string
{
    case Cancelled = 'cancelada';

    case Paid = 'paga';

    case Overdue = 'em_atraso';

    case PartiallyPaid = 'parcialmente_paga';

    case Upcoming = 'a_vencer';

    public function label(): string
    {
        return match ($this) {
            self::Cancelled => 'Cancelada',
            self::Paid => 'Paga',
            self::Overdue => 'Em atraso',
            self::PartiallyPaid => 'Parcialmente paga',
            self::Upcoming => 'A vencer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cancelled => 'gray',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::PartiallyPaid => 'warning',
            self::Upcoming => 'info',
        };
    }

    /**
     * Whether the installment still owes money to the contract. False for the
     * settled ones and for the cancelled ones, which left the flow entirely.
     *
     * This is the predicate every future feature -- saldo a receber,
     * inadimplência, recebimentos futuros -- will be built on.
     */
    public function hasOutstandingBalance(): bool
    {
        return match ($this) {
            self::Cancelled, self::Paid => false,
            self::Overdue, self::PartiallyPaid, self::Upcoming => true,
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
