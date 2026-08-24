<?php

namespace App\Actions\ContractInstallments;

use App\Models\ContractInstallment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies what {@see AnalyzeContractInstallmentSpreadsheet} decided.
 *
 * Nothing is written unless the whole spreadsheet is importable, and everything
 * happens inside one transaction: a monthly position is reconciled completely or
 * not at all.
 *
 * Creations and updates take different paths on purpose.
 *
 * New installments are inserted in bulk. They have no history to preserve, and
 * the derived `number_normalized` is written explicitly because a bulk insert
 * bypasses model events.
 *
 * Updates go through the model, one at a time. They are the small set in a
 * monthly file -- dozens against thousands of untouched rows -- and they are
 * exactly where the trail matters: saving through the model lets
 * {@see ContractInstallment} log which field held which value before the import
 * changed it. A mass `update()` would be faster and would record nothing.
 *
 * Rows that came back unchanged are not touched at all: no write, no
 * `updated_at`, no activity entry.
 */
class ImportContractInstallmentsFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{created: int, updated: int, unchanged: int, contracts: int}
     */
    public function handle(ContractInstallmentSpreadsheetAnalysis $analysis): array
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

        return [
            'created' => $toCreate->count(),
            'updated' => $updated,
            'unchanged' => $analysis->unchangedCount(),
            'contracts' => $toCreate->concat($toUpdate)->pluck('contract_id')->unique()->count(),
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
                'contract_id' => $row['contract_id'],
                'number' => $row['number'],
                'number_normalized' => $row['number_normalized'],
                'due_date' => $row['due_date'],
                'expected_value' => $row['expected_value'],
                'payment_date' => $row['payment_date'],
                'paid_value' => $row['paid_value'],
                'cancellation_date' => $row['cancellation_date'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk(self::CHUNK_SIZE)
            ->each(fn (Collection $chunk) => ContractInstallment::query()->insert($chunk->all()));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function update(Collection $rows): int
    {
        $updated = 0;

        $rows->chunk(self::CHUNK_SIZE)->each(function (Collection $chunk) use (&$updated): void {
            // One query per chunk, then saves through the model so the activity
            // log sees each change.
            $installments = ContractInstallment::query()
                ->whereKey($chunk->pluck('installment_id')->all())
                ->get()
                ->keyBy('id');

            foreach ($chunk as $row) {
                $installment = $installments->get($row['installment_id']);

                if ($installment === null) {
                    continue;
                }

                $installment->fill($row['comparison']->attributes());

                // Belt and braces: the comparison already found a difference, so
                // this only guards against a row whose value the casts consider
                // identical after all.
                if (! $installment->isDirty()) {
                    continue;
                }

                $installment->save();
                $updated++;
            }
        });

        return $updated;
    }
}
