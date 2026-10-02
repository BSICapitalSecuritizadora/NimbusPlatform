<?php

namespace App\Actions\ConstructionUnits;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ImportRun;
use App\Support\Imports\ImportRunDraft;
use App\Support\Money\IntegerMoney;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists the rows approved by {@see AnalyzeConstructionUnitSpreadsheet}.
 *
 * Nothing is written unless the whole spreadsheet is importable, and the rows
 * are inserted in chunks inside a single transaction: either every unit is
 * created or none is.
 *
 * With a draft, the {@see ImportRun} is opened inside the same transaction and
 * its id is stamped on every unit created -- which is what later tells which
 * file created a unit, instead of the time it was created at.
 */
class ImportConstructionUnitsFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{units: int, emissions: int, constructions: int, run: ImportRun|null}
     */
    public function handle(ConstructionUnitSpreadsheetAnalysis $analysis, ?ImportRunDraft $draft = null): array
    {
        if (! $analysis->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $validRows = $analysis->validRows();
        $constructionIds = $validRows->pluck('construction_id')->unique()->values();

        /**
         * A data de referência vai no mesmo formato que o cast `date` do model
         * grava, para que o SQLite da suíte guarde o mesmo texto que um cadastro
         * manual guardaria.
         */
        $model = new ConstructionUnit;
        $run = null;

        DB::transaction(function () use ($validRows, $model, $analysis, $draft, &$run): void {
            $run = $draft?->open();
            $runId = $run?->getKey();
            $now = now();

            $validRows
                ->map(fn (array $row): array => [
                    'construction_id' => $row['construction_id'],
                    'block' => ConstructionUnit::normalizeIdentifier($row['block']),
                    'unit' => ConstructionUnit::normalizeIdentifier($row['unit']),
                    /**
                     * Valor base é cadastro da unidade, não atualização: esta
                     * importação só cria unidades, então não há histórico a
                     * escrever. Reajustar o valor de uma unidade existente é o
                     * fluxo de atualização em lote, que grava em
                     * `construction_unit_values`.
                     */
                    'base_value' => $row['base_value'] === null
                        ? null
                        : IntegerMoney::decimalString((int) $row['base_value']),
                    'base_value_reference_date' => $row['base_value_reference_date'] === null
                        ? null
                        : $model->fromDateTime($row['base_value_reference_date']),
                    'import_run_id' => $runId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->chunk(self::CHUNK_SIZE)
                ->each(fn ($chunk) => ConstructionUnit::query()->insert($chunk->all()));

            $run?->forceFill([
                'records_analyzed' => $analysis->totalLines(),
                'records_created' => $validRows->count(),
                'records_warned' => $analysis->warningCount(),
            ])->save();
        });

        return [
            'units' => $validRows->count(),
            'constructions' => $constructionIds->count(),
            'emissions' => Construction::query()
                ->whereKey($constructionIds)
                ->distinct()
                ->count('emission_id'),
            'run' => $run,
        ];
    }
}
