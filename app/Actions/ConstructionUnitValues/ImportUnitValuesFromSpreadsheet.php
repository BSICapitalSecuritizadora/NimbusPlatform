<?php

namespace App\Actions\ConstructionUnitValues;

use App\Enums\UnitValueSource;
use App\Models\ConstructionUnitValue;
use App\Models\ImportRun;
use App\Support\Imports\ImportRunDraft;
use App\Support\Money\IntegerMoney;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists the repricings approved by {@see AnalyzeUnitValueSpreadsheet}.
 *
 * Only appends. A row classified as correction of an existing effective date
 * becomes another line for that date, never an update of the previous one --
 * the value that was wrong is also part of the history, because it was the one
 * in force while nobody knew it was wrong.
 *
 * Everything happens inside one transaction, as in the other importers: a batch
 * half applied would leave part of the portfolio repriced and part not, with
 * nothing on the screen saying which.
 *
 * An informative divergence never writes: the value already holds, from a date
 * that only a person can say is wrong.
 *
 * With a draft, the {@see ImportRun} is opened inside the same transaction and
 * its id is stamped on every line appended -- the "created" of a run of values
 * are the lines it added to the history.
 */
class ImportUnitValuesFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  string|null  $batchReason  Motivo do lote, usado nas linhas que não
     *                                    trouxeram motivo próprio.
     * @return array{created: int, unchanged: int, units: int, constructions: int, run: ImportRun|null}
     */
    public function handle(UnitValueSpreadsheetAnalysis $analysis, ?string $batchReason = null, ?ImportRunDraft $draft = null): array
    {
        if (! $analysis->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $writableRows = $analysis->writableRows();
        $userId = auth()->id();

        /**
         * A vigência é gravada no mesmo formato que o cast `date` do model grava
         * ("2026-03-01 00:00:00"), e não como o "2026-03-01" puro da análise. No
         * MySQL a coluna é DATE e tanto faz; no SQLite da suíte a coluna é texto,
         * e a linha importada ordenava antes da lançada à mão na mesma vigência,
         * qualquer que fosse o id -- o desempate "mesma vigência, vence o maior
         * id" deixava de valer justamente nos testes.
         */
        $model = new ConstructionUnitValue;
        $run = null;
        $unchanged = $analysis->unchangedCount() + $analysis->informativeDivergenceCount();

        DB::transaction(function () use ($writableRows, $batchReason, $userId, $model, $analysis, $draft, $unchanged, &$run): void {
            $run = $draft?->open();
            $runId = $run?->getKey();
            $now = now();

            $writableRows
                ->map(fn (array $row): array => [
                    'construction_unit_id' => $row['construction_unit_id'],
                    'value' => IntegerMoney::decimalString((int) $row['value_cents']),
                    'effective_from' => $model->fromDateTime($row['effective_from_date']),
                    'source' => UnitValueSource::SpreadsheetImport->value,
                    'reason' => $row['reason'] ?? $batchReason,
                    'created_by_id' => $userId,
                    'import_run_id' => $runId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->chunk(self::CHUNK_SIZE)
                ->each(fn ($chunk) => ConstructionUnitValue::query()->insert($chunk->all()));

            $run?->forceFill([
                'records_analyzed' => $analysis->totalLines(),
                'records_created' => $writableRows->count(),
                'records_unchanged' => $unchanged,
                'records_warned' => $analysis->warningCount(),
            ])->save();
        });

        return [
            'created' => $writableRows->count(),
            'unchanged' => $unchanged,
            'units' => $writableRows->pluck('construction_unit_id')->unique()->count(),
            'constructions' => $analysis->collect()
                ->pluck('construction')
                ->filter()
                ->unique()
                ->count(),
            'run' => $run,
        ];
    }
}
