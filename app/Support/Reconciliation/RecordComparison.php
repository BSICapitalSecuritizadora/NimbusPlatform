<?php

namespace App\Support\Reconciliation;

use App\Enums\ChangeSeverity;
use App\Enums\ReconciliationOutcome;

/**
 * The result of holding one spreadsheet row against the record it matched.
 *
 * An empty list of changes is the ordinary case in a monthly file and means
 * exactly one thing: touch nothing. No update, no `updated_at`, no activity log
 * entry -- which is what makes importing the same position twice a no-op.
 */
final class RecordComparison
{
    /**
     * @param  list<FieldChange>  $changes
     */
    private function __construct(
        public readonly array $changes,
    ) {}

    /**
     * @param  list<FieldChange>  $changes
     */
    public static function of(array $changes): self
    {
        return new self(array_values(array_filter($changes)));
    }

    public function isUnchanged(): bool
    {
        return $this->changes === [];
    }

    /**
     * The severity of the whole row is the severity of its worst field: one
     * blocked field refuses the row even if everything else is routine.
     */
    public function outcome(): ReconciliationOutcome
    {
        if ($this->isUnchanged()) {
            return ReconciliationOutcome::Unchanged;
        }

        if ($this->hasSeverity(ChangeSeverity::Blocked)) {
            return ReconciliationOutcome::Conflict;
        }

        return $this->hasSeverity(ChangeSeverity::Critical)
            ? ReconciliationOutcome::CriticalUpdate
            : ReconciliationOutcome::Update;
    }

    /**
     * The attributes to write, keyed by column. Only the fields that actually
     * differ appear, so an update never rewrites a column the spreadsheet
     * agreed with.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->changes as $change) {
            if ($change->severity !== ChangeSeverity::Blocked) {
                $attributes[$change->field] = $change->value;
            }
        }

        return $attributes;
    }

    /**
     * @return list<FieldChange>
     */
    public function blockedChanges(): array
    {
        return array_values(array_filter(
            $this->changes,
            fn (FieldChange $change): bool => $change->severity === ChangeSeverity::Blocked,
        ));
    }

    /**
     * A one line summary of the row, for the preview table.
     */
    public function summary(): string
    {
        return collect($this->changes)
            ->map(fn (FieldChange $change): string => sprintf(
                '%s: %s → %s',
                $change->label,
                $change->currentForDisplay(),
                $change->newForDisplay(),
            ))
            ->implode(' · ');
    }

    private function hasSeverity(ChangeSeverity $severity): bool
    {
        foreach ($this->changes as $change) {
            if ($change->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
