<?php

namespace App\Actions\ConstructionUnits;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
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
        $baseValue = $this->cell($row, $resolvedHeaders, ConstructionUnitSpreadsheetColumns::BASE_VALUE);
        $baseValueReferenceDate = $this->cell($row, $resolvedHeaders, ConstructionUnitSpreadsheetColumns::BASE_VALUE_REFERENCE_DATE);

        $base = [
            'line' => $lineNumber,
            'emission' => $emissionName,
            'construction' => $constructionName,
            'block' => $block,
            'unit' => $unit,
            'construction_id' => null,
            'base_value' => null,
            'base_value_reference_date' => null,
        ];

        if (blank($emissionName) && blank($constructionName) && blank($block) && blank($unit)
            && blank($baseValue) && blank($baseValueReferenceDate)) {
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

        $baseValueResult = $this->resolveBaseValue($baseValue, $baseValueReferenceDate);

        if (is_string($baseValueResult)) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => $baseValueResult];
        }

        $base['base_value'] = $baseValueResult['base_value'];
        $base['base_value_reference_date'] = $baseValueResult['base_value_reference_date'];

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
     * Reads the optional base value pair.
     *
     * Both halves or neither: a value with no reference date cannot be placed
     * in time, and a date with no value places nothing. Returning the message
     * instead of the pair is how the caller turns it into a row error.
     *
     * @return array{base_value: int|null, base_value_reference_date: string|null}|string
     */
    private function resolveBaseValue(?string $baseValue, ?string $referenceDate): array|string
    {
        if (blank($baseValue) && blank($referenceDate)) {
            return ['base_value' => null, 'base_value_reference_date' => null];
        }

        if (blank($baseValue)) {
            return 'A data de referência foi informada sem o valor base. Informe os dois campos ou nenhum.';
        }

        if (blank($referenceDate)) {
            return 'O valor base foi informado sem a data de referência. Informe os dois campos ou nenhum.';
        }

        $cents = IntegerMoney::cents($baseValue);

        if ($cents === null) {
            return 'Valor base inválido.';
        }

        if ($cents < 0) {
            return 'O valor base não pode ser negativo.';
        }

        $date = $this->parseDate($referenceDate);

        if ($date === null) {
            return 'Data de referência do valor base inválida.';
        }

        return ['base_value' => $cents, 'base_value_reference_date' => $date];
    }

    /**
     * Brazilian day-first dates are matched before anything else: `Carbon::parse`
     * reads "03/09/2026" as the 9th of March, which would silently place a
     * value nearly six months away from where the operator put it.
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matches) === 1) {
            [, $day, $month, $year] = $matches;

            return checkdate((int) $month, (int) $day, (int) $year)
                ? sprintf('%04d-%02d-%02d', $year, $month, $day)
                : null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
        $header = $resolvedHeaders[$column] ?? null;

        /** An optional column the file simply does not have. */
        if ($header === null) {
            return null;
        }

        $value = $row[$header] ?? null;

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
