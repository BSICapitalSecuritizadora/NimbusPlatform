<?php

namespace App\Support\Contracts;

use App\Enums\ContractStatus;

/**
 * The commercial history of one unit, read as a line of time.
 *
 * A unit is held by at most one contract at a time. `occupied_unit_lock` states
 * that for *now* -- it is a unique index over the contracts currently holding
 * their unit, and it cannot say anything about a period that has already closed.
 * This says the same thing for the whole history: the periods of the contracts
 * of one unit never overlap.
 *
 * The two protections answer different questions and neither replaces the other:
 *
 *   occupied_unit_lock  -> who holds the unit right now
 *   this                -> was it ever held by two contracts at once
 *
 * Deliberately ignorant of status: whether a contract holds its unit is asked of
 * {@see ContractStatus::occupiesUnit()} when a period is built, never
 * decided here. This class only compares intervals.
 *
 * Only pairs involving a period marked as the subject of the current operation
 * are reported. Two contracts already on record that nobody is touching are left
 * alone -- a monthly reconciliation is not the moment to refuse a file because of
 * an inconsistency it did not create and cannot fix.
 */
final class ContractOccupancyTimeline
{
    /**
     * @param  list<ContractOccupancyPeriod>  $periods
     */
    private function __construct(
        private readonly array $periods,
    ) {}

    /**
     * @param  list<ContractOccupancyPeriod|null>  $periods  nulls are dropped, so a
     *                                                       caller can pass the result of a
     *                                                       build that legitimately produced
     *                                                       nothing
     */
    public static function of(array $periods): self
    {
        return new self(array_values(array_filter(
            $periods,
            static fn (?ContractOccupancyPeriod $period): bool => $period instanceof ContractOccupancyPeriod,
        )));
    }

    /**
     * The earliest overlap the operation would create, or null when the history
     * stays coherent.
     *
     * Sorted before pairing, so the answer depends on the dates and never on the
     * order the periods happened to be collected in -- which is what makes a
     * spreadsheet listing the distrato first and one listing the new contract
     * first reach the same verdict.
     */
    public function firstOverlap(): ?ContractOccupancyOverlap
    {
        $periods = $this->periods;

        usort(
            $periods,
            static fn (ContractOccupancyPeriod $a, ContractOccupancyPeriod $b): int => $a->startsBefore($b) ? -1 : 1,
        );

        foreach ($periods as $index => $earlier) {
            foreach (array_slice($periods, $index + 1) as $later) {
                if (! $earlier->isSubject && ! $later->isSubject) {
                    continue;
                }

                if (! $earlier->overlaps($later)) {
                    continue;
                }

                return new ContractOccupancyOverlap(
                    earlier: $earlier,
                    later: $later,
                    days: $earlier->overlapDays($later),
                );
            }
        }

        return null;
    }
}
