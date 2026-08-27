<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Exceptions\ContractImportConcurrencyException;
use App\Models\Contract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Applies what {@see AnalyzeContractSpreadsheet} decided.
 *
 * Nothing is written unless the whole spreadsheet is importable, and everything
 * happens inside one transaction: a monthly position is reconciled completely or
 * not at all. A resale is the case that makes this non-negotiable -- distratar
 * the old contract and fail to open the new one would leave the unit sold to
 * nobody.
 *
 * The order of the statements is part of the design, not an accident of how the
 * methods were written:
 *
 *   1. updates that free a unit (ativo/quitado becoming distratado);
 *   2. every other update;
 *   3. the new contracts.
 *
 * `occupied_unit_lock` refuses a second holder for a unit even between two
 * statements of the same transaction, so the contract leaving has to be written
 * before the one arriving. The analysis already sorted the updates that way --
 * see {@see ContractSpreadsheetAnalysis::rowsToUpdate()}. Steps 2 and 3 cannot
 * collide with each other: two contracts holding one unit is exactly what the
 * projection refuses, so a plan that reaches here has at most one holder per
 * unit.
 *
 * New contracts are inserted in bulk -- no history to preserve, and the derived
 * columns are written explicitly because a bulk insert bypasses model events.
 * Updates go through the model one at a time, which is what lets
 * {@see Contract} record which field held which value before the import moved
 * it. Rows that came back unchanged are not touched at all.
 *
 * The rows carry resolved ids -- never the spreadsheet text -- so nothing here
 * can invent a client, a unit or a development.
 */
class ImportContractsFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{created: int, updated: int, unchanged: int, units: int, clients: int, constructions: int}
     *
     * @throws ContractImportConcurrencyException when the units moved between the analysis and the confirmation
     */
    public function handle(ContractSpreadsheetAnalysis $analysis): array
    {
        if (! $analysis->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $toCreate = $analysis->rowsToCreate();
        $toUpdate = $analysis->rowsToUpdate();

        $updated = 0;

        try {
            DB::transaction(function () use ($analysis, $toCreate, $toUpdate, &$updated): void {
                $this->guardAgainstConcurrentChanges($analysis);

                $updated = $this->update($toUpdate);
                $this->create($toCreate);
            });
        } catch (QueryException $exception) {
            if (! $this->isOccupiedUnitViolation($exception)) {
                throw $exception;
            }

            throw ContractImportConcurrencyException::raced();
        }

        $touched = $toCreate->concat($toUpdate);

        return [
            'created' => $toCreate->count(),
            'updated' => $updated,
            'unchanged' => $analysis->unchangedCount(),
            'units' => $touched->pluck('construction_unit_id')->unique()->count(),
            'clients' => $touched->flatMap(fn (array $row): array => $row['client_ids'])->unique()->count(),
            'constructions' => $touched->pluck('construction_id')->unique()->count(),
        ];
    }

    /**
     * The projection decided what the file does to each unit against the
     * position it read during the analysis. Confirming can happen minutes later,
     * and in the meantime someone may have sold, distratado or reactivated a
     * contract on one of those units -- which would turn a plan that was valid
     * into one the database is right to refuse.
     *
     * Reading the position again here, inside the transaction and before the
     * first write, is what turns that into a sentence the operator can act on.
     * The unique index remains the real guarantee: a contract committed after
     * this check still collides, and {@see handle()} translates that too.
     */
    private function guardAgainstConcurrentChanges(ContractSpreadsheetAnalysis $analysis): void
    {
        $occupancies = $analysis->unitOccupancies;

        if ($occupancies === []) {
            return;
        }

        $current = Contract::query()
            ->whereIn('construction_unit_id', array_keys($occupancies))
            ->whereIn('status', ContractStatus::occupyingValues())
            ->get(['id', 'construction_unit_id'])
            ->groupBy('construction_unit_id')
            ->map(fn (Collection $contracts): array => $contracts
                ->map(fn (Contract $contract): int => (int) $contract->getKey())
                ->sort()
                ->values()
                ->all());

        foreach ($occupancies as $unitId => $occupancy) {
            if ($occupancy->databaseOccupantIds === $current->get($unitId, [])) {
                continue;
            }

            throw ContractImportConcurrencyException::forUnit($occupancy->unitLabel);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function create(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $now = now();

        $rows
            ->map(fn (array $row): array => [
                'construction_unit_id' => $row['construction_unit_id'],
                'construction_id' => $row['construction_id'],
                'code' => $row['code'],
                'code_normalized' => $row['code_normalized'],
                'sale_date' => $row['sale_date'],
                'sale_value' => $row['sale_value'],
                'status' => $row['contract_status']->value,
                'cancellation_date' => $row['cancellation_date'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk(self::CHUNK_SIZE)
            ->each(fn (Collection $chunk) => Contract::query()->insert($chunk->all()));

        $this->linkBuyers($rows);
    }

    /**
     * Records the buyers of the contracts just inserted.
     *
     * A bulk insert gives back no ids and fires no model event, so the buyer
     * table cannot be filled by the model and cannot be filled row by row
     * either without undoing the reason the insert is bulk in the first place.
     * The identity the file already resolved -- development plus normalized code
     * -- reads every new id back in one query, and the links go in in bulk too.
     *
     * Two queries per chunk instead of two per contract, whatever the number of
     * buyers: a file with three thousand new contracts costs a dozen statements,
     * not six thousand.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function linkBuyers(Collection $rows): void
    {
        $rows->chunk(self::CHUNK_SIZE)->each(function (Collection $chunk): void {
            $ids = Contract::query()
                ->whereIn('construction_id', $chunk->pluck('construction_id')->unique()->all())
                ->whereIn('code_normalized', $chunk->pluck('code_normalized')->unique()->all())
                ->get(['id', 'construction_id', 'code_normalized'])
                ->keyBy(fn (Contract $contract): string => $contract->construction_id.'|'.$contract->code_normalized);

            $links = $chunk
                ->flatMap(function (array $row) use ($ids): array {
                    $contract = $ids->get($row['construction_id'].'|'.$row['code_normalized']);

                    if ($contract === null) {
                        return [];
                    }

                    return array_map(fn (int $clientId): array => [
                        'contract_id' => $contract->getKey(),
                        'client_id' => $clientId,
                    ], $row['client_ids']);
                })
                ->values();

            if ($links->isNotEmpty()) {
                DB::table('contract_clients')->insertOrIgnore($links->all());
            }
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function update(Collection $rows): int
    {
        $updated = 0;

        $rows->chunk(self::CHUNK_SIZE)->each(function (Collection $chunk) use (&$updated): void {
            $contracts = Contract::query()
                ->with('clients:id')
                ->whereKey($chunk->pluck('contract_id')->all())
                ->get()
                ->keyBy('id');

            foreach ($chunk as $row) {
                $contract = $contracts->get($row['contract_id']);

                if ($contract === null) {
                    continue;
                }

                $contract->fill($row['comparison']->attributes());

                $movedFields = $contract->isDirty();

                if ($movedFields) {
                    $contract->save();
                }

                /**
                 * The buyer set is compared and written apart from the columns:
                 * a contract can gain a buyer without a single field moving, and
                 * that is still an update -- with its own activity, which
                 * `LogsActivity` would never write because it only watches
                 * columns.
                 */
                $movedBuyers = $contract->syncBuyers($row['client_ids']);

                if ($movedFields || $movedBuyers) {
                    $updated++;
                }
            }
        });

        return $updated;
    }

    /**
     * Whether the database refused the write because a unit would have ended up
     * with two holders. Matched on the index name, which both MySQL and SQLite
     * put in the message -- the SQLSTATE alone (23000) also covers the contract
     * code, which is a different problem and must keep its own error.
     */
    private function isOccupiedUnitViolation(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();

        $message = $exception->getMessage()
            .($previous instanceof Throwable ? ' '.$previous->getMessage() : '');

        return str_contains($message, 'occupied_unit_lock');
    }
}
