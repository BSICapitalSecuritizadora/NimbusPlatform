<?php

namespace App\Actions\Contracts;

use App\Models\Contract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies what {@see AnalyzeContractSpreadsheet} decided.
 *
 * Nothing is written unless the whole spreadsheet is importable, and everything
 * happens inside one transaction: a monthly position is reconciled completely or
 * not at all.
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
     */
    public function handle(ContractSpreadsheetAnalysis $analysis): array
    {
        if (! $analysis->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $toCreate = $analysis->rowsToCreate();
        $toUpdate = $analysis->rowsToUpdate();

        $updated = 0;

        DB::transaction(function () use ($toCreate, $toUpdate, &$updated): void {
            $this->create($toCreate);
            $updated = $this->update($toUpdate);
        });

        $touched = $toCreate->concat($toUpdate);

        return [
            'created' => $toCreate->count(),
            'updated' => $updated,
            'unchanged' => $analysis->unchangedCount(),
            'units' => $touched->pluck('construction_unit_id')->unique()->count(),
            'clients' => $touched->pluck('client_id')->unique()->count(),
            'constructions' => $touched->pluck('construction_id')->unique()->count(),
        ];
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
                'client_id' => $row['client_id'],
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
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function update(Collection $rows): int
    {
        $updated = 0;

        $rows->chunk(self::CHUNK_SIZE)->each(function (Collection $chunk) use (&$updated): void {
            $contracts = Contract::query()
                ->whereKey($chunk->pluck('contract_id')->all())
                ->get()
                ->keyBy('id');

            foreach ($chunk as $row) {
                $contract = $contracts->get($row['contract_id']);

                if ($contract === null) {
                    continue;
                }

                $contract->fill($row['comparison']->attributes());

                if (! $contract->isDirty()) {
                    continue;
                }

                $contract->save();
                $updated++;
            }
        });

        return $updated;
    }
}
