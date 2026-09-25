<?php

namespace App\Actions\ConstructionUnitValues;

use App\Enums\ReconciliationOutcome;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Services\SalesBoards\UnitValueResolver;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;
use Stringable;

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

        return new UnitValueSpreadsheetAnalysis($this->flagRegisteredCompetences($analyzedRows));
    }

    /**
     * Marca as linhas cuja vigência alcança competência já registrada no Quadro
     * de Vendas. O aviso não bloqueia: reprecificar com vigência passada é
     * legítimo, mas a posição registrada não acompanha a correção sozinha, e
     * quem confirma precisa saber disso.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function flagRegisteredCompetences(array $rows): array
    {
        $index = RegisteredCompetenceIndex::forConstructions(array_column($rows, 'construction_id'));

        return array_map(fn (array $row): array => $row['outcome']->writesToDatabase()
            ? [...$row, 'registered_competences' => $index->reachedBy($row['construction_id'], $row['effective_from_date'])]
            : $row, $rows);
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
        $rawValue = $this->rawCell($row, $resolvedHeaders, UnitValueSpreadsheetColumns::VALUE);
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
            'construction_id' => null,
            'construction_unit_id' => null,
            'value_cents' => null,
            'effective_from_date' => null,
            'current_value_cents' => null,
            'registered_competences' => [],
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

        $construction = $this->findConstruction($constructionName, $emission);

        if (is_string($construction)) {
            return $this->error($base, $construction);
        }

        $base['construction_id'] = $construction->getKey();

        $valueCents = $this->parseAmount($rawValue);

        if ($valueCents === null) {
            return $this->error($base, 'Valor atualizado inválido.');
        }

        /**
         * Zero não é preço de tabela: usado como marcador de "sem preço", fazia
         * toda venda da unidade sair conforme e o estoque sair a R$ 0,00 sem
         * nenhum achado.
         */
        if ($valueCents <= 0) {
            return $this->error($base, 'O valor atualizado precisa ser maior que zero.');
        }

        $effectiveFromDate = $this->parseDate($effectiveFrom);

        if ($effectiveFromDate === null) {
            return $this->error($base, 'Vigência inválida. '.SpreadsheetDate::FORMAT_HINT);
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

    /**
     * Leitura estrita, a mesma dos importadores de contratos e parcelas: ano com
     * quatro dígitos, hora opcional e nenhum `Carbon::parse()` de reserva, que
     * lia "03/09/26" como 9 de março e antecipava o reajuste em seis meses.
     */
    private function parseDate(string $value): ?string
    {
        return SpreadsheetDate::parse($value);
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
     * emissão são recusados: escolher um deles seria gravar valores numa unidade
     * que ninguém apontou.
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

    private function findUnit(Construction $construction, string $block, string $unit): ?ConstructionUnit
    {
        return ConstructionUnit::query()
            ->where('construction_id', $construction->getKey())
            ->where('block', ConstructionUnit::normalizeIdentifier($block))
            ->where('unit', ConstructionUnit::normalizeIdentifier($unit))
            ->first();
    }
}
