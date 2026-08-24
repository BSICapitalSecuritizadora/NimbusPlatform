<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Enums\ReconciliationOutcome;

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
 */
class ContractBatchProjection
{
    /**
     * @param  list<array<string, mixed>>  $rows  every row already classified on its own
     * @param  array<int, list<array{id: int, code: string, status: ContractStatus, client: string|null}>>  $databaseOccupants  unit id => contracts holding it right now
     * @return array{rows: list<array<string, mixed>>, occupancies: array<int, ProjectedUnitOccupancy>}
     */
    public function resolve(array $rows, array $databaseOccupants): array
    {
        $occupancies = $this->project($rows, $databaseOccupants);

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

            $rows[$index] = $this->annotateResale($rows[$index], $occupancy);
        }

        return ['rows' => $rows, 'occupancies' => $occupancies];
    }

    /**
     * The final holder of every unit the file can still act on.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, list<array{id: int, code: string, status: ContractStatus, client: string|null}>>  $databaseOccupants
     * @return array<int, ProjectedUnitOccupancy>
     */
    private function project(array $rows, array $databaseOccupants): array
    {
        $occupancies = [];

        foreach ($this->groupByUnit($rows) as $unitId => $group) {
            $onRecord = $databaseOccupants[$unitId] ?? [];

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
                    'sale_date' => null,
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
     * @param  list<array{id: int, code: string, status: ContractStatus, client: string|null}>  $onRecord
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
     * @param  list<array{id: int, code: string, status: ContractStatus, client: string|null}>  $onRecord
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
