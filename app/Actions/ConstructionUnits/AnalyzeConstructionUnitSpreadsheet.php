<?php

namespace App\Actions\ConstructionUnits;

use App\Enums\ImportRowWarningCode;
use App\Exceptions\UnreadableSpreadsheetException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitRetirement;
use App\Models\Emission;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\SpreadsheetAmount;
use App\Support\Imports\SpreadsheetPlausibility;
use App\Support\Imports\SpreadsheetRows;
use App\Support\Money\IntegerMoney;
use DateTimeInterface;
use Illuminate\Support\Str;
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

    /**
     * Baixas abertas por empreendimento, indexadas por `bloco|unidade` em
     * minúsculas -- a chave do arquivo --, carregadas uma vez por empreendimento
     * na primeira linha já cadastrada que o alcança.
     *
     * @var array<int, array<string, string>>
     */
    private array $openRetirementsByConstruction = [];

    public function handle(string $path): ConstructionUnitSpreadsheetAnalysis
    {
        try {
            return $this->analyze($path);
        } catch (UnreadableSpreadsheetException $exception) {
            return new ConstructionUnitSpreadsheetAnalysis(fileErrors: [$exception->getMessage()]);
        }
    }

    private function analyze(string $path): ConstructionUnitSpreadsheetAnalysis
    {
        $firstRow = SpreadsheetRows::first($path);

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

        foreach (SpreadsheetRows::chunks($path, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $row) {
                $lineNumber++;
                $analyzedRows[] = $this->analyzeRow($row, $resolvedHeaders, $lineNumber, $seenUnits);
            }
        }

        return new ConstructionUnitSpreadsheetAnalysis(
            $this->flagRegisteredCompetences($this->checkBaseValuesAgainstPeers($analyzedRows)),
        );
    }

    /**
     * Valor base de cada unidade nova contra a mediana do empreendimento: as
     * unidades já cadastradas com valor e as linhas válidas do próprio arquivo.
     *
     * Só depois de ler o arquivo inteiro, porque a mediana depende de todas as
     * linhas. Com menos de {@see SpreadsheetPlausibility::UNIT_BASE_MIN_PEERS}
     * valores não há mediana, e nada é comparado. Muito além dela é erro da linha
     * -- um zero a mais ou a leitura do milhar --; além, mas plausível, é aviso.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function checkBaseValuesAgainstPeers(array $rows): array
    {
        $valuesByConstruction = [];

        foreach ($rows as $row) {
            if (($row['status'] === self::STATUS_VALID) && ($row['base_value'] !== null)) {
                $valuesByConstruction[(int) $row['construction_id']][] = (int) $row['base_value'];
            }
        }

        if ($valuesByConstruction === []) {
            return $rows;
        }

        ConstructionUnit::query()
            ->toBase()
            ->whereIn('construction_id', array_keys($valuesByConstruction))
            ->whereNotNull('base_value')
            ->get(['construction_id', 'base_value'])
            ->each(function (object $unit) use (&$valuesByConstruction): void {
                $cents = IntegerMoney::cents($unit->base_value);

                if ($cents !== null) {
                    $valuesByConstruction[(int) $unit->construction_id][] = $cents;
                }
            });

        $medians = array_map(
            static fn (array $values): ?int => SpreadsheetPlausibility::median($values),
            $valuesByConstruction,
        );

        foreach ($rows as $index => $row) {
            if (($row['status'] !== self::STATUS_VALID) || ($row['base_value'] === null)) {
                continue;
            }

            $verdict = SpreadsheetPlausibility::unitBaseValue((int) $row['base_value'], $medians[(int) $row['construction_id']] ?? null);

            if ($verdict->isImpossible()) {
                $rows[$index] = [...$row, 'warnings' => [], 'status' => self::STATUS_ERROR, 'message' => $verdict->error];

                continue;
            }

            $rows[$index]['warnings'] = [
                ...$row['warnings'],
                ...array_map(
                    static fn (array $warning): array => ['code' => $warning['code']->value, 'message' => $warning['message']],
                    $verdict->warnings,
                ),
            ];
        }

        return $rows;
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
            'warnings' => [],
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

        if ($baseValueResult['warning'] !== null) {
            $base['warnings'] = [['code' => ImportRowWarningCode::AmbiguousAmountText->value, 'message' => $baseValueResult['warning']]];
        }

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
            $retiredOn = $this->openRetirementsOf((int) $construction->id)[Str::lower($block).'|'.Str::lower($unit)] ?? null;

            /**
             * A unidade baixada continua bloqueando a linha: a importação só
             * cria unidades, e reativar é decisão da Gestão com data e motivo.
             * A mensagem diz onde fica a saída.
             */
            return [
                ...$base,
                'status' => self::STATUS_ALREADY_REGISTERED,
                'message' => $retiredOn === null
                    ? "A unidade {$unit} do bloco {$block} já está cadastrada para este empreendimento."
                    : "A unidade {$unit} do bloco {$block} já está cadastrada neste empreendimento e está baixada desde {$retiredOn}. "
                        .'Para voltar a contá-la, reative-a na aba "Baixas" da unidade; a importação não reativa unidades.',
            ];
        }

        return [...$base, 'status' => self::STATUS_VALID, 'message' => null];
    }

    /**
     * As baixas abertas das unidades do empreendimento, pela chave do arquivo,
     * com a data da baixa já formatada. Uma consulta por empreendimento.
     *
     * @return array<string, string>
     */
    private function openRetirementsOf(int $constructionId): array
    {
        if (! array_key_exists($constructionId, $this->openRetirementsByConstruction)) {
            $this->openRetirementsByConstruction[$constructionId] = ConstructionUnitRetirement::query()
                ->toBase()
                ->join('construction_units', 'construction_units.id', '=', 'construction_unit_retirements.construction_unit_id')
                ->where('construction_units.construction_id', $constructionId)
                ->whereNull('construction_unit_retirements.reactivated_on')
                ->get(['construction_units.block', 'construction_units.unit', 'construction_unit_retirements.retired_on'])
                ->mapWithKeys(fn (object $row): array => [
                    Str::lower((string) $row->block).'|'.Str::lower((string) $row->unit) => SpreadsheetDate::display(substr((string) $row->retired_on, 0, 10)),
                ])
                ->all();
        }

        return $this->openRetirementsByConstruction[$constructionId];
    }

    /**
     * Reads the optional base value pair.
     *
     * Both halves or neither: a value with no reference date cannot be placed
     * in time, and a date with no value places nothing. Returning the message
     * instead of the pair is how the caller turns it into a row error.
     *
     * @return array{base_value: int|null, base_value_reference_date: string|null, warning: string|null}|string
     */
    private function resolveBaseValue(?string $baseValue, mixed $rawBaseValue, ?string $referenceDate): array|string
    {
        if (blank($baseValue) && blank($referenceDate)) {
            return ['base_value' => null, 'base_value_reference_date' => null, 'warning' => null];
        }

        if (blank($baseValue)) {
            return 'A data de referência foi informada sem o valor base. Informe os dois campos ou nenhum.';
        }

        if (blank($referenceDate)) {
            return 'O valor base foi informado sem a data de referência. Informe os dois campos ou nenhum.';
        }

        $amount = SpreadsheetAmount::read($rawBaseValue);
        $cents = $amount->cents;

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

        return ['base_value' => $cents, 'base_value_reference_date' => $date, 'warning' => $amount->warning('Valor base')];
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
