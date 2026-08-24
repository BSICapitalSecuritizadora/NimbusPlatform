<?php

namespace App\Actions\ConstructionUnits;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads an import spreadsheet and classifies every row, without writing
 * anything. The result feeds the preview and, once confirmed, the import.
 *
 * Reported line numbers count the header plus the data rows returned by the
 * reader. Fully blank rows are dropped by the reader itself, so a file with
 * blank rows in the middle reports the lines below them shifted up.
 */
class AnalyzeConstructionUnitSpreadsheet
{
    public const STATUS_VALID = 'valida';

    public const STATUS_EMPTY = 'vazia';

    public const STATUS_ERROR = 'erro';

    public const STATUS_ALREADY_REGISTERED = 'ja_cadastrada';

    public const STATUS_DUPLICATED_IN_FILE = 'duplicada_na_planilha';

    /**
     * Rows are read in chunks so large files do not sit in memory at once.
     */
    private const CHUNK_SIZE = 500;

    public function handle(string $path): ConstructionUnitSpreadsheetAnalysis
    {
        $reader = SimpleExcelReader::create($path);
        $rows = $reader->getRows();

        $firstRow = $rows->first();

        if ($firstRow === null) {
            return ConstructionUnitSpreadsheetAnalysis::emptyFile();
        }

        $resolvedHeaders = ConstructionUnitSpreadsheetColumns::resolve($firstRow);
        $missingHeaders = ConstructionUnitSpreadsheetColumns::missingHeaders($resolvedHeaders);

        if ($missingHeaders !== []) {
            return ConstructionUnitSpreadsheetAnalysis::invalidHeaders($missingHeaders);
        }

        $analyzedRows = [];
        $seenUnits = [];
        $lineNumber = 1;

        SimpleExcelReader::create($path)
            ->getRows()
            ->chunk(self::CHUNK_SIZE)
            ->each(function ($chunk) use (&$analyzedRows, &$seenUnits, &$lineNumber, $resolvedHeaders): void {
                foreach ($chunk as $row) {
                    $lineNumber++;
                    $analyzedRows[] = $this->analyzeRow($row, $resolvedHeaders, $lineNumber, $seenUnits);
                }
            });

        return new ConstructionUnitSpreadsheetAnalysis($analyzedRows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     * @param  array<string, int>  $seenUnits
     * @return array<string, mixed>
     */
    private function analyzeRow(array $row, array $resolvedHeaders, int $lineNumber, array &$seenUnits): array
    {
        $emissionName = $this->cell($row, $resolvedHeaders, ConstructionUnitSpreadsheetColumns::EMISSION);
        $constructionName = $this->cell($row, $resolvedHeaders, ConstructionUnitSpreadsheetColumns::CONSTRUCTION);
        $block = $this->cell($row, $resolvedHeaders, ConstructionUnitSpreadsheetColumns::BLOCK);
        $unit = $this->cell($row, $resolvedHeaders, ConstructionUnitSpreadsheetColumns::UNIT);

        $base = [
            'line' => $lineNumber,
            'emission' => $emissionName,
            'construction' => $constructionName,
            'block' => $block,
            'unit' => $unit,
            'construction_id' => null,
        ];

        if (blank($emissionName) && blank($constructionName) && blank($block) && blank($unit)) {
            return [...$base, 'status' => self::STATUS_EMPTY, 'message' => 'Linha vazia (ignorada).'];
        }

        $missingFields = $this->missingFields($emissionName, $constructionName, $block, $unit);

        if ($missingFields !== []) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => 'Campos obrigatórios não preenchidos: '.implode(', ', $missingFields).'.'];
        }

        $emission = $this->findEmission($emissionName);

        if ($emission === null) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => 'Emissão não encontrada.'];
        }

        $construction = $this->findConstruction($constructionName);

        if ($construction === null) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => 'Empreendimento não encontrado.'];
        }

        if ($construction->emission_id !== $emission->id) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => 'O empreendimento informado não pertence à emissão selecionada.'];
        }

        $base['construction_id'] = $construction->id;

        $key = $construction->id.'|'.Str::lower($block).'|'.Str::lower($unit);

        if (isset($seenUnits[$key])) {
            return [
                ...$base,
                'status' => self::STATUS_DUPLICATED_IN_FILE,
                'message' => "Unidade repetida na planilha (linha {$seenUnits[$key]}).",
            ];
        }

        $seenUnits[$key] = $lineNumber;

        if (ConstructionUnit::isDuplicate($construction->id, $block, $unit)) {
            return [
                ...$base,
                'status' => self::STATUS_ALREADY_REGISTERED,
                'message' => "A unidade {$unit} do bloco {$block} já está cadastrada para este empreendimento.",
            ];
        }

        return [...$base, 'status' => self::STATUS_VALID, 'message' => null];
    }

    /**
     * @return list<string>
     */
    private function missingFields(?string $emission, ?string $construction, ?string $block, ?string $unit): array
    {
        return array_values(array_filter([
            blank($emission) ? ConstructionUnitSpreadsheetColumns::EMISSION : null,
            blank($construction) ? ConstructionUnitSpreadsheetColumns::CONSTRUCTION : null,
            blank($block) ? ConstructionUnitSpreadsheetColumns::BLOCK : null,
            blank($unit) ? ConstructionUnitSpreadsheetColumns::UNIT : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     */
    private function cell(array $row, array $resolvedHeaders, string $column): ?string
    {
        $value = $row[$resolvedHeaders[$column]] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function findEmission(string $name): ?Emission
    {
        return Emission::query()->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower(trim($name))])->first();
    }

    private function findConstruction(string $name): ?Construction
    {
        return Construction::query()->whereRaw('LOWER(TRIM(development_name)) = ?', [Str::lower(trim($name))])->first();
    }
}
