<?php

namespace App\Actions\ConstructionUnitValues;

use App\Enums\ReconciliationOutcome;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Services\SalesBoards\UnitValueResolver;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads a batch repricing spreadsheet and classifies every row, writing
 * nothing.
 *
 * The verdict of a row is what the confirmation will do to it, and it is decided
 * against the value that would already be in force on the informed date -- not
 * against the newest value of the unit. Re-sending the same file therefore
 * reports "sem alteração" for every row and inserts nothing: an append-only
 * history that grew a duplicate on every re-run would stop being a history and
 * become a log of who clicked confirm.
 */
class AnalyzeUnitValueSpreadsheet
{
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly UnitValueResolver $unitValueResolver) {}

    public function handle(string $path): UnitValueSpreadsheetAnalysis
    {
        $firstRow = SimpleExcelReader::create($path)->getRows()->first();

        if ($firstRow === null) {
            return UnitValueSpreadsheetAnalysis::emptyFile();
        }

        $resolvedHeaders = UnitValueSpreadsheetColumns::resolve($firstRow);
        $missingHeaders = UnitValueSpreadsheetColumns::missingHeaders($resolvedHeaders);

        if ($missingHeaders !== []) {
            return UnitValueSpreadsheetAnalysis::invalidHeaders($missingHeaders);
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

        return new UnitValueSpreadsheetAnalysis($analyzedRows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     * @param  array<string, array{line: int, cents: int|null}>  $seenUnits
     * @return array<string, mixed>
     */
    private function analyzeRow(array $row, array $resolvedHeaders, int $lineNumber, array &$seenUnits): array
    {
        $emissionName = $this->cell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::EMISSION);
        $constructionName = $this->cell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::CONSTRUCTION);
        $block = $this->cell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::BLOCK);
        $unit = $this->cell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::UNIT);
        $value = $this->cell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::VALUE);
        $effectiveFrom = $this->cell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::EFFECTIVE_FROM);
        $reason = $this->cell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::REASON);

        $base = [
            'line' => $lineNumber,
            'emission' => $emissionName,
            'construction' => $constructionName,
            'block' => $block,
            'unit' => $unit,
            'value' => $value,
            'effective_from' => $effectiveFrom,
            'reason' => $reason,
            'construction_unit_id' => null,
            'value_cents' => null,
            'effective_from_date' => null,
            'current_value_cents' => null,
        ];

        if (blank($emissionName) && blank($constructionName) && blank($block) && blank($unit) && blank($value) && blank($effectiveFrom)) {
            return [...$base, 'outcome' => ReconciliationOutcome::Empty, 'message' => 'Linha vazia (ignorada).'];
        }

        $missingFields = $this->missingFields($emissionName, $constructionName, $block, $unit, $value, $effectiveFrom);

        if ($missingFields !== []) {
            return $this->error($base, 'Campos obrigatórios não preenchidos: '.implode(', ', $missingFields).'.');
        }

        $emission = $this->findEmission($emissionName);

        if ($emission === null) {
            return $this->error($base, 'Emissão não encontrada.');
        }

        $construction = $this->findConstruction($constructionName);

        if ($construction === null) {
            return $this->error($base, 'Empreendimento não encontrado.');
        }

        if ($construction->emission_id !== $emission->id) {
            return $this->error($base, 'O empreendimento informado não pertence à emissão selecionada.');
        }

        $valueCents = IntegerMoney::cents($value);

        if ($valueCents === null) {
            return $this->error($base, 'Valor atualizado inválido.');
        }

        if ($valueCents < 0) {
            return $this->error($base, 'O valor atualizado não pode ser negativo.');
        }

        $effectiveFromDate = $this->parseDate($effectiveFrom);

        if ($effectiveFromDate === null) {
            return $this->error($base, 'Vigência inválida.');
        }

        $base['value_cents'] = $valueCents;
        $base['effective_from_date'] = $effectiveFromDate;

        /**
         * A unidade não é criada aqui. Reprecificar o que não existe seria
         * inventar cadastro a partir de um arquivo de preços; o cadastro tem
         * fluxo próprio.
         */
        $constructionUnit = $this->findUnit($construction, $block, $unit);

        if ($constructionUnit === null) {
            return $this->error($base, "A unidade {$unit} do bloco {$block} não está cadastrada neste empreendimento.");
        }

        $base['construction_unit_id'] = $constructionUnit->getKey();

        $key = $constructionUnit->getKey().'|'.$effectiveFromDate;

        if (isset($seenUnits[$key])) {
            $previous = $seenUnits[$key];

            if ($previous['cents'] !== $valueCents) {
                return [
                    ...$base,
                    'outcome' => ReconciliationOutcome::Conflict,
                    'message' => "A mesma unidade e vigência aparecem na linha {$previous['line']} com outro valor.",
                ];
            }

            return [
                ...$base,
                'outcome' => ReconciliationOutcome::DuplicatedInFile,
                'message' => "Unidade e vigência repetidas na planilha (linha {$previous['line']}).",
            ];
        }

        $seenUnits[$key] = ['line' => $lineNumber, 'cents' => $valueCents];

        $currentValue = $this->unitValueResolver->forUnit($constructionUnit, CarbonImmutable::parse($effectiveFromDate));
        $base['current_value_cents'] = $currentValue->valueCents;

        if ($currentValue->isPresent() && ($currentValue->valueCents === $valueCents)) {
            return [
                ...$base,
                'outcome' => ReconciliationOutcome::Unchanged,
                'message' => 'O valor informado já é o vigente nesta data.',
            ];
        }

        return [
            ...$base,
            'outcome' => $currentValue->isAbsent() ? ReconciliationOutcome::New : ReconciliationOutcome::Update,
            'message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function error(array $base, string $message): array
    {
        return [...$base, 'outcome' => ReconciliationOutcome::Error, 'message' => $message];
    }

    /**
     * @return list<string>
     */
    private function missingFields(?string $emission, ?string $construction, ?string $block, ?string $unit, ?string $value, ?string $effectiveFrom): array
    {
        return array_values(array_filter([
            blank($emission) ? UnitValueSpreadsheetColumns::EMISSION : null,
            blank($construction) ? UnitValueSpreadsheetColumns::CONSTRUCTION : null,
            blank($block) ? UnitValueSpreadsheetColumns::BLOCK : null,
            blank($unit) ? UnitValueSpreadsheetColumns::UNIT : null,
            blank($value) ? UnitValueSpreadsheetColumns::VALUE : null,
            blank($effectiveFrom) ? UnitValueSpreadsheetColumns::EFFECTIVE_FROM : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     */
    private function cell(array $row, array $resolvedHeaders, string $column): ?string
    {
        $header = $resolvedHeaders[$column] ?? null;

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

    /**
     * Brazilian day-first dates are matched before anything else: `Carbon::parse`
     * would read "03/09/2026" as the 9th of March.
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

    private function findEmission(string $name): ?Emission
    {
        return Emission::query()->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower(trim($name))])->first();
    }

    private function findConstruction(string $name): ?Construction
    {
        return Construction::query()->whereRaw('LOWER(TRIM(development_name)) = ?', [Str::lower(trim($name))])->first();
    }

    private function findUnit(Construction $construction, string $block, string $unit): ?ConstructionUnit
    {
        return ConstructionUnit::query()
            ->where('construction_id', $construction->getKey())
            ->where('block', ConstructionUnit::normalizeIdentifier($block))
            ->where('unit', ConstructionUnit::normalizeIdentifier($unit))
            ->first();
    }
}
