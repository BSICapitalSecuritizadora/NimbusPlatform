<?php

namespace App\Actions\ConstructionUnits;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Support\Money\IntegerMoney;
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
                    'base_value_reference_date' => $row['base_value_reference_date'],
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
