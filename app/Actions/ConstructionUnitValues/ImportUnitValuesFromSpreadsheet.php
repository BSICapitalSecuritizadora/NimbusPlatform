<?php

namespace App\Actions\ConstructionUnitValues;

use App\Enums\UnitValueSource;
use App\Models\ConstructionUnitValue;
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
 */
class ImportUnitValuesFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  string|null  $batchReason  Motivo do lote, usado nas linhas que não
     *                                    trouxeram motivo próprio.
     * @return array{created: int, unchanged: int, units: int, constructions: int}
     */
    public function handle(UnitValueSpreadsheetAnalysis $analysis, ?string $batchReason = null): array
    {
        if (! $analysis->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $writableRows = $analysis->writableRows();
        $userId = auth()->id();

        DB::transaction(function () use ($writableRows, $batchReason, $userId): void {
            $now = now();

            $writableRows
                ->map(fn (array $row): array => [
                    'construction_unit_id' => $row['construction_unit_id'],
                    'value' => IntegerMoney::decimalString((int) $row['value_cents']),
                    'effective_from' => $row['effective_from_date'],
                    'source' => UnitValueSource::SpreadsheetImport->value,
                    'reason' => $row['reason'] ?? $batchReason,
                    'created_by_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->chunk(self::CHUNK_SIZE)
                ->each(fn ($chunk) => ConstructionUnitValue::query()->insert($chunk->all()));
        });

        return [
            'created' => $writableRows->count(),
            'unchanged' => $analysis->unchangedCount(),
            'units' => $writableRows->pluck('construction_unit_id')->unique()->count(),
            'constructions' => $analysis->collect()
                ->pluck('construction')
                ->filter()
                ->unique()
                ->count(),
        ];
    }
}
