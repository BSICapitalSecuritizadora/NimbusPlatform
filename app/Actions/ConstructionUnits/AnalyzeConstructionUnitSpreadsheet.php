<?php

namespace App\Actions\ConstructionUnits;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Money\IntegerMoney;
use DateTimeInterface;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;
use Stringable;

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

        return new ConstructionUnitSpreadsheetAnalysis($this->flagRegisteredCompetences($analyzedRows));
    }

    /**
     * Marca as unidades novas de empreendimento que já tem posição registrada no
     * Quadro de Vendas. A derivação conta toda unidade do empreendimento, então
     * a unidade nova entra no estoque de todas as competências registradas
     * quando forem recalculadas -- e a posição registrada não acompanha sozinha.
     * O aviso não bloqueia.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function flagRegisteredCompetences(array $rows): array
    {
        $index = RegisteredCompetenceIndex::forConstructions(array_column($rows, 'construction_id'));

        return array_map(fn (array $row): array => $row['status'] === self::STATUS_VALID
            ? [...$row, 'registered_competences' => $index->allOf($row['construction_id'])]
            : $row, $rows);
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
        $rawBaseValue = $this->rawCell($row, $resolvedHeaders, ConstructionUnitSpreadsheetColumns::BASE_VALUE);
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
            'registered_competences' => [],
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

        $construction = $this->findConstruction($constructionName, $emission);

        if (is_string($construction)) {
            return [...$base, 'status' => self::STATUS_ERROR, 'message' => $construction];
        }

        $base['construction_id'] = $construction->id;

        $baseValueResult = $this->resolveBaseValue($baseValue, $rawBaseValue, $baseValueReferenceDate);

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
    private function resolveBaseValue(?string $baseValue, mixed $rawBaseValue, ?string $referenceDate): array|string
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

        $cents = $this->parseAmount($rawBaseValue);

        if ($cents === null) {
            return 'Valor base inválido.';
        }

        if ($cents < 0) {
            return 'O valor base não pode ser negativo.';
        }

        /**
         * Zero não é valor informado: usado como marcador de "sem preço", fazia
         * toda venda da unidade sair conforme e o estoque sair a R$ 0,00 sem
         * nenhum achado. Sem valor, deixe os dois campos em branco.
         */
        if ($cents === 0) {
            return 'O valor base precisa ser maior que zero. Sem valor conhecido, deixe o valor base e a data de referência em branco.';
        }

        $date = $this->parseDate($referenceDate);

        if ($date === null) {
            return 'Data de referência do valor base inválida. '.SpreadsheetDate::FORMAT_HINT;
        }

        return ['base_value' => $cents, 'base_value_reference_date' => $date];
    }

    /**
     * Leitura estrita, a mesma dos importadores de contratos e parcelas: ano com
     * quatro dígitos, hora opcional e nenhum `Carbon::parse()` de reserva, que
     * lia "03/09/26" como 9 de março e deslocava o valor base em seis meses.
     */
    private function parseDate(string $value): ?string
    {
        return SpreadsheetDate::parse($value);
    }

    /**
     * Centavos do valor. A célula numérica é lida pelo número que carrega:
     * convertida antes em texto, `153.919` virava "153.919" e o parser de
     * texto, com razão, lê ponto seguido de três dígitos como milhar.
     */
    private function parseAmount(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        return IntegerMoney::cents($value);
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
     * A célula como o leitor a entregou, sem virar texto.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     */
    private function rawCell(array $row, array $resolvedHeaders, string $column): mixed
    {
        $header = $resolvedHeaders[$column] ?? null;

        return $header === null ? null : ($row[$header] ?? null);
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

        /**
         * Célula formatada como data no Excel volta do leitor como objeto, e não
         * como texto: converter direto para string quebraria a importação inteira.
         * O formato brasileiro é o que o resto desta classe já sabe interpretar.
         */
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('d/m/Y');
        }

        /** Qualquer outro objeto não é dado de planilha: a linha reclama do campo, e não estoura. */
        if (is_object($value) && ! $value instanceof Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function findEmission(string $name): ?Emission
    {
        return Emission::query()->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower(trim($name))])->first();
    }

    /**
     * O empreendimento do nome dentro da emissão da linha, ou o motivo da recusa.
     *
     * Procurar primeiro pelo nome em todas as emissões e só depois conferir a
     * emissão recusava a linha certa sempre que outro empreendimento homônimo,
     * de outra série, aparecesse antes na consulta. Dois homônimos na mesma
     * emissão são recusados: escolher um deles seria cadastrar a unidade num
     * empreendimento que ninguém apontou.
     */
    private function findConstruction(string $name, Emission $emission): Construction|string
    {
        $candidates = Construction::query()
            ->whereRaw('LOWER(TRIM(development_name)) = ?', [Str::lower(trim($name))])
            ->get();

        $inEmission = $candidates->where('emission_id', $emission->getKey());

        return match (true) {
            $inEmission->count() === 1 => $inEmission->first(),
            $inEmission->count() > 1 => 'Há mais de um empreendimento com este nome nesta emissão. Diferencie os nomes antes de importar.',
            $candidates->isNotEmpty() => 'O empreendimento informado não pertence à emissão selecionada.',
            default => 'Empreendimento não encontrado.',
        };
    }
}
