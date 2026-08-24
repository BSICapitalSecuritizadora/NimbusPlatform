<?php

namespace App\Support\Reconciliation;

use App\Enums\ChangeSeverity;

/**
 * One field that differs between what the database holds and what the
 * spreadsheet brought, already formatted the way the confirmation screen shows
 * it.
 *
 * Values arrive here as display strings on purpose: the comparison that decided
 * they differ ran on domain values -- money in integer cents, dates as Y-m-d,
 * identifications through {@see App\Support\IdentifierNormalizer} -- so the
 * strings are only ever read by a human.
 */
final class FieldChange
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly ?string $current,
        public readonly ?string $new,
        public readonly ChangeSeverity $severity,
        /** The value to write, in the shape the model expects. */
        public readonly mixed $value = null,
    ) {}

    public function currentForDisplay(): string
    {
        return $this->current ?? '—';
    }

    public function newForDisplay(): string
    {
        return $this->new ?? '—';
    }
}
