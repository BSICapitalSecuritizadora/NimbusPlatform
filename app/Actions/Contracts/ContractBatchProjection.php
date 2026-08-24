<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Enums\ReconciliationOutcome;
use App\Support\Contracts\ContractOccupancyPeriod;
use App\Support\Contracts\ContractOccupancyTimeline;

/**
 * Reads the whole spreadsheet as one position instead of a sequence of lines.
 *
 * {@see ContractReconciler} answers "this row against this contract"; this
 * answers "this file against the units it touches". The distinction is what
 * makes a distrato and the resale that follows it importable together: the row
 * opening the new contract is no longer held against the database as it stands,
 * but against the state the file as a whole leaves behind.
 *
 * The projection is a set operation, never a walk over the rows in order, so a
 * file listing the distrato first and one listing the new contract first reach
 * the same verdict.
 *
 * Two questions are asked of every unit, in this order and never mixed:
 *
 *   1. would more than one contract hold it? -- the occupancy the database
 *      itself refuses through `occupied_unit_lock`;
 *   2. would any two contracts hold it at the same time? -- the history, which
 *      no index can express, checked by {@see ContractOccupancyTimeline}.
 *
 * The order matters. Two contracts both holding the unit trivially overlap, so
 * the timeline runs only where occupancy already came out right; that way a unit
 * whose previous contract merely went quitado is reported as what it is -- a
 * unit that was never freed -- and not as a date problem.
 *
 * Three rules keep this from weakening anything:
 *
 * - Only rows that could actually be applied take part. A row that failed
 *   validation, or that the reconciler refused, moves nothing -- an invalid
 *   distrato therefore never frees a unit for the row below it.
 * - A unit is freed only by an explicit, valid change in the file. A contract
 *   the spreadsheet does not mention keeps holding its unit, exactly as before.
 * - Whether a status holds the unit is asked of {@see ContractStatus} every
 *   time. Quitado moving in is a change, not a release.
 *
 * @phpstan-import-type Occupant from ProjectedUnitOccupancy
 * @phpstan-import-type Release from ProjectedUnitOccupancy
 *
 * @phpstan-type UnitContract array{id: int, code: string, status: ContractStatus, client: string|null, sale_date: string|null, cancellation_date: string|null}
 */
class ContractBatchProjection
{
    /**
     * @param  list<array<string, mixed>>  $rows  every row already classified on its own
     * @param  array<int, list<UnitContract>>  $unitContracts  unit id => every contract of that unit
     * @return array{rows: list<array<string, mixed>>, occupancies: array<int, ProjectedUnitOccupancy>}
     */
    public function resolve(array $rows, array $unitContracts): array
    {
        $occupancies = $this->project($rows, $unitContracts);
        $overlaps = $this->findOverlaps($rows, $unitContracts, $occupancies);

        foreach ($rows as $index => $row) {
            $occupancy = $occupancies[$row['construction_unit_id'] ?? null] ?? null;

            if ($occupancy === null) {
                continue;
            }

            $rows[$index]['releases_unit'] = $occupancy->releasesFromLine($row['line']);

            if ($occupancy->isOverOccupied()) {
                $rows[$index] = $this->refuse($rows[$index], $occupancy);

                continue;
            }

            if (isset($overlaps[$row['line']])) {
                $rows[$index] = [
                    ...$rows[$index],
                    'outcome' => ReconciliationOutcome::Conflict,
                    'message' => $overlaps[$row['line']],
                ];

                continue;
            }

            $rows[$index] = $this->annotateResale($rows[$index], $occupancy);
        }

        return ['rows' => $rows, 'occupancies' => $occupancies];
    }

    /**
     * The final holder of every unit the file can still act on.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, list<UnitContract>>  $unitContracts
     * @return array<int, ProjectedUnitOccupancy>
     */
    private function project(array $rows, array $unitContracts): array
    {
        $occupancies = [];

        foreach ($this->groupByUnit($rows) as $unitId => $group) {
            $onRecord = $this->holdersOf($unitContracts[$unitId] ?? []);

            /** @var array<int, true> $decidedByFile */
            $decidedByFile = [];
            /** @var list<Occupant> $fromFile */
            $fromFile = [];
            /** @var list<Release> $releases */
            $releases = [];

            foreach ($group['rows'] as $row) {
                $status = $row['contract_status'];
                $contractId = $row['contract_id'] ?? null;

                if ($contractId !== null) {
                    $decidedByFile[$contractId] = true;
                }

                if (($status instanceof ContractStatus) && $status->occupiesUnit()) {
                    $fromFile[] = [
                        'code' => (string) $row['code'],
                        'status' => $status,
                        'line' => (int) $row['line'],
                        'contract_id' => $contractId,
                        'client' => $row['client_label'],
                        'sale_date' => $row['sale_date'],
                    ];

                    continue;
                }

                /**
                 * The row lets go of the unit -- but only a contract that was
                 * actually holding it releases anything. A brand new contract
                 * arriving already distratado frees nothing, because it never
                 * held the unit in the first place.
                 */
                if (($contractId !== null) && $this->holdsUnit($onRecord, $contractId)) {
                    $releases[] = [
                        'code' => (string) $row['code'],
                        'status' => $status instanceof ContractStatus ? $status : ContractStatus::Cancelled,
                        'line' => (int) $row['line'],
                        'contract_id' => $contractId,
                        'client' => $row['client_label'],
                        'cancellation_date' => $row['cancellation_date'],
                    ];
                }
            }

            /** @var list<Occupant> $untouched */
            $untouched = [];

            foreach ($onRecord as $occupant) {
                if (isset($decidedByFile[$occupant['id']])) {
                    continue;
                }

                $untouched[] = [
                    'code' => $occupant['code'],
                    'status' => $occupant['status'],
                    'line' => null,
                    'contract_id' => $occupant['id'],
                    'client' => $occupant['client'],
                    'sale_date' => $occupant['sale_date'],
                ];
            }

            $occupancies[$unitId] = new ProjectedUnitOccupancy(
                unitId: $unitId,
                unitLabel: $group['label'],
                occupants: [...$untouched, ...$fromFile],
                releases: $releases,
                databaseOccupantIds: $this->sortedIds($onRecord),
            );
        }

        return $occupancies;
    }

    /**
     * The overlap message for every line the file would put on a unit whose
     * history it breaks, keyed by line.
     *
     * Only units that came out of the projection with a single holder are looked
     * at: where occupancy itself is wrong, that is the finding, and adding a date
     * complaint on top of it would only bury it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, list<UnitContract>>  $unitContracts
     * @param  array<int, ProjectedUnitOccupancy>  $occupancies
     * @return array<int, string>
     */
    private function findOverlaps(array $rows, array $unitContracts, array $occupancies): array
    {
        $messages = [];

        foreach ($this->groupByUnit($rows) as $unitId => $group) {
            $occupancy = $occupancies[$unitId] ?? null;

            if (($occupancy === null) || $occupancy->isOverOccupied()) {
                continue;
            }

            $overlap = ContractOccupancyTimeline::of(
                $this->periodsOf($group['rows'], $unitContracts[$unitId] ?? []),
            )->firstOverlap();

            if ($overlap === null) {
                continue;
            }

            foreach ($overlap->lines() as $line) {
                $messages[$line] = $overlap->describe($group['label']);
            }
        }

        return $messages;
    }

    /**
     * The unit's whole history as the file would leave it: each contract on
     * record either as the file rewrites it or exactly as it stands, plus the
     * contracts the file opens.
     *
     * A row that changes nothing is not a subject. That is what keeps re-sending
     * a position already reconciled from being refused over dates it did not
     * write, and what keeps two historical contracts nobody is touching out of
     * the verdict.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<UnitContract>  $onRecord
     * @return list<ContractOccupancyPeriod|null>
     */
    private function periodsOf(array $rows, array $onRecord): array
    {
        $byContract = [];

        foreach ($rows as $row) {
            if ($row['contract_id'] !== null) {
                $byContract[$row['contract_id']] = $row;
            }
        }

        $periods = [];

        foreach ($onRecord as $contract) {
            $row = $byContract[$contract['id']] ?? null;

            $periods[] = $row === null
                ? new ContractOccupancyPeriod(
                    code: $contract['code'],
                    clientName: $contract['client'],
                    startsOn: (string) $contract['sale_date'],
                    endsOn: $contract['status']->occupiesUnit() ? null : $contract['cancellation_date'],
                    isSubject: false,
                )
                : ContractOccupancyPeriod::fromValues(
                    code: $row['code'],
                    clientName: $row['client_label'],
                    saleDate: $row['sale_date'],
                    cancellationDate: $row['cancellation_date'],
                    status: $row['contract_status'],
                    isSubject: $row['outcome'] !== ReconciliationOutcome::Unchanged,
                    line: $row['line'],
                );
        }

        foreach ($rows as $row) {
            if ($row['contract_id'] !== null) {
                continue;
            }

            $periods[] = ContractOccupancyPeriod::fromValues(
                code: $row['code'],
                clientName: $row['client_label'],
                saleDate: $row['sale_date'],
                cancellationDate: $row['cancellation_date'],
                status: $row['contract_status'],
                isSubject: true,
                line: $row['line'],
            );
        }

        return $periods;
    }

    /**
     * Rows that confirming would actually apply, gathered per unit. Blocking
     * rows are left out on purpose: they change nothing, so they may not free
     * a unit for anybody else.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array{label: string, rows: list<array<string, mixed>>}>
     */
    private function groupByUnit(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $unitId = $row['construction_unit_id'] ?? null;

            if (($unitId === null) || $row['outcome']->blocksImport() || ($row['outcome'] === ReconciliationOutcome::Empty)) {
                continue;
            }

            $grouped[$unitId]['label'] ??= (string) $row['unit_label'];
            $grouped[$unitId]['rows'][] = $row;
        }

        return $grouped;
    }

    /**
     * The contracts of a unit that currently hold it.
     *
     * @param  list<UnitContract>  $contracts
     * @return list<UnitContract>
     */
    private function holdersOf(array $contracts): array
    {
        return array_values(array_filter(
            $contracts,
            static fn (array $contract): bool => $contract['status']->occupiesUnit(),
        ));
    }

    /**
     * @param  list<UnitContract>  $onRecord
     */
    private function holdsUnit(array $onRecord, int $contractId): bool
    {
        foreach ($onRecord as $occupant) {
            if ($occupant['id'] === $contractId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<UnitContract>  $onRecord
     * @return list<int>
     */
    private function sortedIds(array $onRecord): array
    {
        $ids = array_map(static fn (array $occupant): int => $occupant['id'], $onRecord);

        sort($ids);

        return array_values($ids);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function refuse(array $row, ProjectedUnitOccupancy $occupancy): array
    {
        if (! $occupancy->occupiesFromLine($row['line'])) {
            return $row;
        }

        return [
            ...$row,
            'outcome' => ReconciliationOutcome::Conflict,
            'message' => $occupancy->conflictMessage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function annotateResale(array $row, ProjectedUnitOccupancy $occupancy): array
    {
        $note = $occupancy->resaleNoteFor($row['line']);

        if ($note === null) {
            return $row;
        }

        return [
            ...$row,
            'message' => blank($row['message'] ?? null) ? $note : $row['message'].' · '.$note,
        ];
    }
}
