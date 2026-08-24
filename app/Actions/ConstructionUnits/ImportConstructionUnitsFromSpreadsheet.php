<?php

namespace App\Actions\ConstructionUnits;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists the rows approved by {@see AnalyzeConstructionUnitSpreadsheet}.
 *
 * Nothing is written unless the whole spreadsheet is importable, and the rows
 * are inserted in chunks inside a single transaction: either every unit is
 * created or none is.
 */
class ImportConstructionUnitsFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{units: int, emissions: int, constructions: int}
     */
    public function handle(ConstructionUnitSpreadsheetAnalysis $analysis): array
    {
        if (! $analysis->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $validRows = $analysis->validRows();
        $constructionIds = $validRows->pluck('construction_id')->unique()->values();

        DB::transaction(function () use ($validRows): void {
            $now = now();

            $validRows
                ->map(fn (array $row): array => [
                    'construction_id' => $row['construction_id'],
                    'block' => ConstructionUnit::normalizeIdentifier($row['block']),
                    'unit' => ConstructionUnit::normalizeIdentifier($row['unit']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->chunk(self::CHUNK_SIZE)
                ->each(fn ($chunk) => ConstructionUnit::query()->insert($chunk->all()));
        });

        return [
            'units' => $validRows->count(),
            'constructions' => $constructionIds->count(),
            'emissions' => Construction::query()
                ->whereKey($constructionIds)
                ->distinct()
                ->count('emission_id'),
        ];
    }
}
