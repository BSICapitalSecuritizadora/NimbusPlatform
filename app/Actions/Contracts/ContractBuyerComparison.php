<?php

namespace App\Actions\Contracts;

use App\Enums\ChangeSeverity;
use App\Support\Reconciliation\FieldChange;

/**
 * The difference between the buyers a contract has and the buyers a file brings.
 *
 * Ids only. Names belong to the presentation, and documents never belong to a
 * comparison that ends up in an audit trail.
 */
final class ContractBuyerComparison
{
    /**
     * @param  list<int>  $current
     * @param  list<int>  $imported
     * @param  list<int>  $added
     * @param  list<int>  $removed
     */
    public function __construct(
        public readonly array $current,
        public readonly array $imported,
        public readonly array $added,
        public readonly array $removed,
    ) {}

    public function isUnchanged(): bool
    {
        return ($this->added === []) && ($this->removed === []);
    }

    /**
     * Losing a buyer is refused; gaining one is shown and confirmed.
     */
    public function severity(): ChangeSeverity
    {
        return match (true) {
            $this->removed !== [] => ChangeSeverity::Blocked,
            $this->added !== [] => ChangeSeverity::Critical,
            default => ChangeSeverity::Normal,
        };
    }

    /**
     * The change as the conference screen reads it, or null when the set held.
     *
     * @param  array<int, string>  $names  client id => name, for display only
     */
    public function toFieldChange(array $names): ?FieldChange
    {
        if ($this->isUnchanged()) {
            return null;
        }

        return new FieldChange(
            field: 'client_ids',
            label: 'Compradores',
            current: $this->describe($this->current, $names),
            new: $this->describe($this->imported, $names),
            severity: $this->severity(),
            value: $this->imported,
        );
    }

    /**
     * "+ Maria da Silva" / "- João da Silva", which is what the operator needs
     * to see before confirming -- the full lists alone make them diff by eye.
     *
     * @param  array<int, string>  $names
     */
    public function summary(array $names): string
    {
        $parts = [];

        foreach ($this->added as $id) {
            $parts[] = '+ '.($names[$id] ?? "Cliente #{$id}");
        }

        foreach ($this->removed as $id) {
            $parts[] = '- '.($names[$id] ?? "Cliente #{$id}");
        }

        return implode(', ', $parts);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $names
     */
    private function describe(array $ids, array $names): string
    {
        if ($ids === []) {
            return '—';
        }

        return implode(', ', array_map(
            fn (int $id): string => $names[$id] ?? "Cliente #{$id}",
            $ids,
        ));
    }
}
