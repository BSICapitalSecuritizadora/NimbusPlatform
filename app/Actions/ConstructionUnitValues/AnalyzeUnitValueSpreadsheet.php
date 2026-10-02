<?php

namespace App\Actions\ConstructionUnitValues;

use App\DTOs\SalesBoards\ResolvedUnitValue;
use App\Enums\ContractStatus;
use App\Enums\ImportRowWarningCode;
use App\Enums\ReconciliationOutcome;
use App\Enums\ResolvedUnitValueSource;
use App\Exceptions\UnreadableSpreadsheetException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitRetirement;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\Emission;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Services\SalesBoards\UnitValueResolver;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\PlausibilityVerdict;
use App\Support\Imports\SpreadsheetAmount;
use App\Support\Imports\SpreadsheetPlausibility;
use App\Support\Imports\SpreadsheetRows;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardPlausibility;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
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
 *
 * The classification happens in two phases. The first resolves and validates
 * each row on its own. The second walks each unit in the order of the effective
 * dates and judges every row against the projected history: what is on record
 * plus the rows of this same file already accepted before it. That is what lets
 * one file carry a compensating line and the corrected one together -- judged
 * against the record alone, the second line read "sem alteração" and the
 * correction was lost.
 *
 * A row whose value is already in force from another date is never written. When
 * the date on record carries the signature of the old parser -- day and month
 * swapped, or a year before 1990 --, the row becomes an informative divergence
 * that says how to fix the history with a compensating line: the history is
 * append-only, and the file alone cannot tell a legitimate re-send from a wrong
 * effective date.
 */
class AnalyzeUnitValueSpreadsheet
{
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly UnitValueResolver $unitValueResolver) {}

    public function handle(string $path): UnitValueSpreadsheetAnalysis
    {
        try {
            return $this->analyze($path);
        } catch (UnreadableSpreadsheetException $exception) {
            return new UnitValueSpreadsheetAnalysis(fileErrors: [$exception->getMessage()]);
        }
    }

    private function analyze(string $path): UnitValueSpreadsheetAnalysis
    {
        $firstRow = SpreadsheetRows::first($path);

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

        foreach (SpreadsheetRows::chunks($path, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $row) {
                $lineNumber++;
                $analyzedRows[] = $this->analyzeRow($row, $resolvedHeaders, $lineNumber, $seenUnits);
            }
        }

        $analyzedRows = $this->flagRetiredUnits($this->judgeAgainstProjectedHistory($analyzedRows));

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
            ? [
                ...$row,
                'registered_competences' => $index->reachedBy($row['construction_id'], $row['effective_from_date']),
                'registered_competence_notice' => $index->noticeFor(
                    $row['construction_id'],
                    RegisteredCompetenceIndex::SUBJECT_UNIT_VALUE,
                    $row['effective_from_date'],
                ),
            ]
            : $row, $rows);
    }

    /**
     * Avisa as linhas que gravam valor para unidade baixada. O valor é aceito --
     * é inofensivo enquanto a unidade estiver baixada e volta a contar se ela
     * for reativada --, mas quem confirma precisa saber que ele não muda o
     * Quadro agora. Uma consulta para o arquivo inteiro.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function flagRetiredUnits(array $rows): array
    {
        $unitIds = collect($rows)
            ->filter(fn (array $row): bool => ($row['outcome'] instanceof ReconciliationOutcome) && $row['outcome']->writesToDatabase())
            ->pluck('construction_unit_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($unitIds === []) {
            return $rows;
        }

        $retiredSince = ConstructionUnitRetirement::query()
            ->toBase()
            ->whereIn('construction_unit_id', $unitIds)
            ->whereNull('reactivated_on')
            ->pluck('retired_on', 'construction_unit_id')
            ->map(fn (mixed $retiredOn): string => SpreadsheetDate::display(substr((string) $retiredOn, 0, 10)))
            ->all();

        if ($retiredSince === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            $since = $retiredSince[(int) ($row['construction_unit_id'] ?? 0)] ?? null;

            if (($since === null) || ! $row['outcome']->writesToDatabase()) {
                continue;
            }

            $rows[$index]['warnings'] = [
                ...$row['warnings'],
                [
                    'code' => ImportRowWarningCode::RetiredUnit->value,
                    'message' => "A unidade está baixada desde {$since}: o valor é gravado, mas só conta se ela for reativada.",
                ],
            ];
        }

        return $rows;
    }

    /**
     * Segunda fase: cada unidade, em ordem de vigência (e de linha), contra o
     * histórico projetado -- o vigente no cadastro mais as linhas do próprio
     * arquivo já aceitas. Linha do arquivo vence o empate de data com o
     * cadastro, porque será gravada depois e terá id maior: é a mesma regra de
     * desempate do {@see UnitValueResolver}.
     *
     * A linha gravada antes de qualquer valor cadastrado da unidade é
     * conferida depois, contra as vendas de que ela passaria a ser a
     * referência ({@see self::judgeUnrecordedValuesAgainstSales()}): isso
     * depende das linhas posteriores do arquivo que serão gravadas, e elas só
     * se conhecem no fim desta passada.
     *
     * Duas consultas pelo histórico do arquivo inteiro, outras duas só quando
     * alguma linha precisa do valor anterior a uma vigência registrada, e duas
     * -- vendas e vigências cadastradas -- só quando alguma linha é gravada
     * antes de qualquer valor cadastrado da unidade.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function judgeAgainstProjectedHistory(array $rows): array
    {
        $pending = array_filter($rows, fn (array $row): bool => ($row['outcome'] === null));

        if ($pending === []) {
            return $rows;
        }

        $units = ConstructionUnit::query()
            ->whereKey(array_values(array_unique(array_column($pending, 'construction_unit_id'))))
            ->get(['id', 'construction_id', 'base_value', 'base_value_reference_date']);

        $onRecord = $this->unitValueResolver->forUnitDates($units, array_values(array_map(
            fn (array $row): array => ['unit_id' => $row['construction_unit_id'], 'date' => CarbonImmutable::parse($row['effective_from_date'])],
            $pending,
        )));

        uasort($pending, fn (array $a, array $b): int => [$a['construction_unit_id'], $a['effective_from_date'], $a['line']]
            <=> [$b['construction_unit_id'], $b['effective_from_date'], $b['line']]);

        /** @var array<int, list<array{date: string, cents: int}>> $accepted */
        $accepted = [];
        $divergences = [];
        $unrecorded = [];

        foreach ($pending as $index => $row) {
            $unitId = (int) $row['construction_unit_id'];
            $date = (string) $row['effective_from_date'];
            $recorded = $onRecord[$unitId.'@'.$date] ?? null;

            $current = $this->projectedValue($recorded, $accepted[$unitId] ?? [], $date);

            $rows[$index] = $this->judge($row, $current);

            if ($rows[$index]['outcome']->writesToDatabase()) {
                $accepted[$unitId][] = ['date' => $date, 'cents' => (int) $row['value_cents']];

                if (($recorded === null) || $recorded->isAbsent()) {
                    $unrecorded[] = $index;
                }
            }

            if ($rows[$index]['outcome'] === ReconciliationOutcome::InformativeDivergence) {
                $divergences[$index] = $current;
            }
        }

        [$rows, $accepted] = $this->judgeUnrecordedValuesAgainstSales($rows, $unrecorded, $accepted);

        return $this->explainDivergences($rows, $divergences, $units, $accepted);
    }

    /**
     * As linhas gravadas antes de qualquer valor cadastrado da unidade contra
     * as vendas de que elas passariam a ser a referência no Quadro de Vendas.
     *
     * Antes do primeiro valor cadastrado, o histórico da unidade é só o que o
     * arquivo grava: a primeira dessas linhas não tem vigente, e as seguintes
     * têm como vigente outra linha do próprio arquivo. A régua "contra o
     * vigente" não alcança a venda, e a derivação mede a venda contra o valor
     * da unidade na data da venda e, sem ele, contra o da data da posição
     * ({@see SalesBoardPlausibility::saleScaleReference()}). Gravada, a linha
     * vale da vigência até o próximo valor da unidade, cadastrado ou de outra
     * linha que o arquivo grava. Por isso ela é a referência:
     *
     * - da venda com data igual ou posterior à vigência, se nenhum outro valor
     *   começa entre a vigência e a data da venda. Fora de escala é erro; a duas
     *   vezes, aviso;
     * - da venda anterior à vigência (ou sem data) que fica sem tabela na data
     *   dela -- nenhuma linha gravada até a data da venda --, se a linha
     *   continua valendo na data da posição ({@see self::positionDatesFor()}).
     *   Só o erro, como na importação de contratos.
     *
     * A linha histórica, seguida por outro valor antes da venda e antes da
     * posição, não é referência de venda nenhuma e não é medida contra elas:
     * medida contra todas as vendas da unidade, uma tabela antiga virava erro
     * falso diante de uma venda que a derivação mede pela tabela vigente. A
     * conferência vai para a linha que a derivação de fato usa.
     *
     * A linha gravada depois de um valor cadastrado segue só a régua "contra o
     * vigente", como antes.
     *
     * A linha que vira erro deixa o histórico projetado, para a explicação das
     * divergências não citar um valor que não será gravado. As outras não são
     * julgadas de novo sem ela: com um erro, o arquivo não é importado.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $unrecorded  índices das linhas gravadas sem valor cadastrado na data
     * @param  array<int, list<array{date: string, cents: int}>>  $accepted
     * @return array{0: list<array<string, mixed>>, 1: array<int, list<array{date: string, cents: int}>>}
     */
    private function judgeUnrecordedValuesAgainstSales(array $rows, array $unrecorded, array $accepted): array
    {
        if ($unrecorded === []) {
            return [$rows, $accepted];
        }

        $sales = $this->salesOfUnits(array_values(array_unique(array_map(
            fn (int $index): int => (int) $rows[$index]['construction_unit_id'],
            $unrecorded,
        ))));

        if ($sales === []) {
            return [$rows, $accepted];
        }

        $recordedDates = $this->recordedValueDates(array_keys($sales));
        $verdicts = [];

        foreach ($unrecorded as $index) {
            $row = $rows[$index];
            $unitId = (int) $row['construction_unit_id'];

            if (! isset($sales[$unitId])) {
                continue;
            }

            $date = (string) $row['effective_from_date'];
            $fileDates = array_column($accepted[$unitId] ?? [], 'date');
            $nextValueDate = $this->nextValueDate($date, [...($recordedDates[$unitId] ?? []), ...$fileDates]);

            $inForceOn = static fn (string $day): bool => ($date <= $day) && (($nextValueDate === null) || ($day < $nextValueDate));

            $verdicts[$index] = array_reduce(
                $sales[$unitId],
                function (PlausibilityVerdict $verdict, array $sale) use ($row, $date, $fileDates, $inForceOn): PlausibilityVerdict {
                    return $verdict->and($this->judgeAgainstSale((int) $row['value_cents'], $sale, $date, $fileDates, $inForceOn));
                },
                PlausibilityVerdict::plausible(),
            );
        }

        foreach ($verdicts as $index => $verdict) {
            $row = $rows[$index];

            if ($verdict->isImpossible()) {
                $unitId = (int) $row['construction_unit_id'];

                $rows[$index] = [...$row, 'warnings' => [], 'outcome' => ReconciliationOutcome::Error, 'message' => $verdict->error];
                $accepted[$unitId] = array_values(array_filter(
                    $accepted[$unitId],
                    static fn (array $entry): bool => $entry['date'] !== $row['effective_from_date'],
                ));

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

        return [$rows, $accepted];
    }

    /**
     * Uma linha gravada antes de qualquer valor cadastrado contra uma venda da
     * unidade, nos dois casos em que ela seria a referência da venda.
     *
     * Na venda anterior à vigência não há valor cadastrado até a data da venda
     * -- a linha vem antes de todos --, então a tabela da data da venda só pode
     * vir de outra linha do arquivo.
     *
     * @param  array{code: string, sale_date: string|null, sale_cents: int}  $sale
     * @param  list<string>  $fileDates  vigências das linhas que o arquivo grava para a unidade
     * @param  Closure(string): bool  $inForceOn
     */
    private function judgeAgainstSale(int $valueCents, array $sale, string $date, array $fileDates, Closure $inForceOn): PlausibilityVerdict
    {
        $saleDate = $sale['sale_date'];

        if (($saleDate !== null) && ($saleDate >= $date)) {
            return $inForceOn($saleDate)
                ? SpreadsheetPlausibility::unitValueAgainstSale($valueCents, $sale['sale_cents'], $sale['code'], $saleDate)
                : PlausibilityVerdict::plausible();
        }

        if (($saleDate !== null) && collect($fileDates)->contains(fn (string $fileDate): bool => $fileDate <= $saleDate)) {
            return PlausibilityVerdict::plausible();
        }

        $positionDate = collect($this->positionDatesFor($date))->first(fn (string $day): bool => $inForceOn($day));

        return $positionDate === null
            ? PlausibilityVerdict::plausible()
            : SpreadsheetPlausibility::unitValueAgainstSaleAtPosition($valueCents, $sale['sale_cents'], $sale['code'], $saleDate, $positionDate);
    }

    /**
     * O primeiro valor da unidade com vigência depois da data: a linha vale da
     * data até a véspera dele, ou para sempre quando não há.
     *
     * @param  list<string>  $dates  vigências (`Y-m-d`) cadastradas e das linhas gravadas pelo arquivo
     */
    private function nextValueDate(string $date, array $dates): ?string
    {
        $later = array_filter($dates, static fn (string $other): bool => $other > $date);

        return $later === [] ? null : min($later);
    }

    /**
     * As datas da posição em que a linha pode passar a ser a referência de uma
     * venda sem tabela na data dela: as das próximas apurações, que a
     * importação de contratos também confere
     * ({@see SpreadsheetPlausibility::positionReferenceDates()}), e, para a
     * vigência posterior a elas, o fim do mês da própria vigência -- a primeira
     * posição em que a linha vai valer.
     *
     * @return list<string> datas `Y-m-d`, em ordem crescente
     */
    private function positionDatesFor(string $effectiveFrom): array
    {
        $dates = SpreadsheetPlausibility::positionReferenceDates();
        sort($dates);

        if ($effectiveFrom > $dates[count($dates) - 1]) {
            $dates[] = CarbonImmutable::parse($effectiveFrom)->endOfMonth()->toDateString();
        }

        return $dates;
    }

    /**
     * As vigências cadastradas no histórico de cada unidade, numa consulta. O
     * valor base fica de fora: ele só responde pela data em que a unidade não
     * tem histórico ({@see UnitValueResolver}), e a linha gravada passa a ser
     * esse histórico.
     *
     * @param  list<int>  $unitIds
     * @return array<int, list<string>> unidade => vigências `Y-m-d`
     */
    private function recordedValueDates(array $unitIds): array
    {
        $dates = [];

        ConstructionUnitValue::query()
            ->toBase()
            ->whereIn('construction_unit_id', $unitIds)
            ->get(['construction_unit_id', 'effective_from'])
            ->each(function (object $value) use (&$dates): void {
                $dates[(int) $value->construction_unit_id][] = substr((string) $value->effective_from, 0, 10);
            });

        return $dates;
    }

    /**
     * The value in force on the date: the latest accepted row of the file up to
     * the date, or what is on record, whichever is later -- the file winning
     * the tie.
     *
     * @param  list<array{date: string, cents: int}>  $accepted
     * @return array{cents: int|null, date: string|null, source: string}
     */
    private function projectedValue(?ResolvedUnitValue $onRecord, array $accepted, string $date): array
    {
        $fromFile = null;

        foreach ($accepted as $entry) {
            if (($entry['date'] <= $date) && (($fromFile === null) || ($entry['date'] >= $fromFile['date']))) {
                $fromFile = $entry;
            }
        }

        $recordDate = $onRecord?->effectiveFromDate();

        if (($fromFile !== null) && (($recordDate === null) || ($fromFile['date'] >= $recordDate) || $onRecord->isAbsent())) {
            return ['cents' => $fromFile['cents'], 'date' => $fromFile['date'], 'source' => 'arquivo'];
        }

        if (($onRecord === null) || $onRecord->isAbsent()) {
            return ['cents' => null, 'date' => null, 'source' => ResolvedUnitValueSource::Absent->value];
        }

        return ['cents' => $onRecord->valueCents, 'date' => $recordDate, 'source' => $onRecord->source->value];
    }

    /**
     * As vendas que a derivação mede contra o valor de cada unidade: os
     * contratos ativos e quitados, sem os excluídos. O permutado fica de fora --
     * contrato de permuta não tem preço de tabela -- e o distratado não ocupa a
     * unidade. Uma consulta para o arquivo inteiro, sem hidratar models.
     *
     * @param  list<int>  $unitIds
     * @return array<int, list<array{code: string, sale_date: string|null, sale_cents: int}>> unidade => vendas
     */
    private function salesOfUnits(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $sales = [];

        Contract::query()
            ->toBase()
            ->whereIn('construction_unit_id', $unitIds)
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Settled->value])
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get(['id', 'construction_unit_id', 'code', 'sale_date', 'sale_value'])
            ->each(function (object $contract) use (&$sales): void {
                $saleCents = IntegerMoney::cents($contract->sale_value);

                if (($saleCents === null) || ($saleCents <= 0)) {
                    return;
                }

                $sales[(int) $contract->construction_unit_id][] = [
                    'code' => (string) $contract->code,
                    'sale_date' => blank($contract->sale_date) ? null : substr((string) $contract->sale_date, 0, 10),
                    'sale_cents' => $saleCents,
                ];
            });

        return $sales;
    }

    /**
     * The verdict of one row against the value in force on its date.
     *
     * Só a régua "contra o vigente". A conferência contra as vendas da linha
     * gravada antes de qualquer valor cadastrado vem depois, quando já se sabe
     * de quais vendas ela passaria a ser a referência
     * ({@see self::judgeUnrecordedValuesAgainstSales()}).
     *
     * @param  array<string, mixed>  $row
     * @param  array{cents: int|null, date: string|null, source: string}  $current
     * @return array<string, mixed>
     */
    private function judge(array $row, array $current): array
    {
        $row['current_value_cents'] = $current['cents'];
        $row['current_effective_from'] = $current['date'];
        $row['current_source'] = $current['source'];

        $valueCents = (int) $row['value_cents'];

        if (($current['cents'] !== null) && ($current['cents'] === $valueCents)) {
            if ($current['date'] === $row['effective_from_date']) {
                return [...$row, 'outcome' => ReconciliationOutcome::Unchanged, 'message' => 'O valor informado já é o vigente nesta data.'];
            }

            if (($current['source'] !== 'arquivo') && $this->carriesOldParserSignature((string) $current['date'], (string) $row['effective_from_date'])) {
                return [...$row, 'warnings' => [], 'outcome' => ReconciliationOutcome::InformativeDivergence, 'message' => null];
            }

            return [
                ...$row,
                'outcome' => ReconciliationOutcome::Unchanged,
                'message' => 'Já vigora desde '.SpreadsheetDate::display($current['date']).'.',
            ];
        }

        /**
         * Contra o valor vigente: a uma ordem de grandeza é erro da linha, a
         * duas vezes ou à metade é aviso.
         */
        $plausibility = SpreadsheetPlausibility::unitValue($valueCents, $current['cents']);

        if ($plausibility->isImpossible()) {
            return [...$row, 'warnings' => [], 'outcome' => ReconciliationOutcome::Error, 'message' => $plausibility->error];
        }

        return [
            ...$row,
            'warnings' => [
                ...$row['warnings'],
                ...array_map(
                    static fn (array $warning): array => ['code' => $warning['code']->value, 'message' => $warning['message']],
                    $plausibility->warnings,
                ),
            ],
            'outcome' => $current['cents'] === null ? ReconciliationOutcome::New : ReconciliationOutcome::Update,
            'message' => null,
        ];
    }

    /**
     * The effective date on record looks like a reading of the old parser: the
     * informed date with day and month swapped (and earlier than it), or a year
     * before 1990.
     */
    private function carriesOldParserSignature(string $recordedDate, string $informedDate): bool
    {
        if ((int) substr($recordedDate, 0, 4) < SpreadsheetDate::MINIMUM_YEAR) {
            return true;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $informedDate));

        if (($day > 12) || ($month > 12) || ($day === $month)) {
            return false;
        }

        return ($recordedDate === sprintf('%04d-%02d-%02d', $year, $day, $month))
            && ($recordedDate < $informedDate);
    }

    /**
     * The message of each informative divergence, with the value the unit had
     * the day before the effective date on record -- the value a compensating
     * line has to restore.
     *
     * Uma vigência registrada antes de 1990 não pode ser repetida numa planilha
     * -- a leitura recusa o ano --, então a linha compensatória começa em
     * 01/01/1990, a primeira data que a importação aceita.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{cents: int|null, date: string|null, source: string}>  $divergences
     * @param  EloquentCollection<int, ConstructionUnit>  $units
     * @param  array<int, list<array{date: string, cents: int}>>  $accepted
     * @return list<array<string, mixed>>
     */
    private function explainDivergences(array $rows, array $divergences, EloquentCollection $units, array $accepted): array
    {
        if ($divergences === []) {
            return $rows;
        }

        $requests = [];

        foreach ($divergences as $index => $current) {
            $requests[$index] = [
                'unit_id' => (int) $rows[$index]['construction_unit_id'],
                'date' => CarbonImmutable::parse((string) $current['date'])->subDay(),
            ];
        }

        $before = $this->unitValueResolver->forUnitDates($units, array_values($requests));
        $minimumDate = sprintf('%04d-01-01', SpreadsheetDate::MINIMUM_YEAR);

        foreach ($divergences as $index => $current) {
            $row = $rows[$index];
            $recordedDate = (string) $current['date'];
            $displayRecorded = SpreadsheetDate::display($recordedDate);
            $request = $requests[$index];

            $previous = $this->projectedValue(
                $before[$request['unit_id'].'@'.$request['date']->toDateString()] ?? null,
                $accepted[(int) $row['construction_unit_id']] ?? [],
                $request['date']->toDateString(),
            );

            $beforeMinimumYear = $recordedDate < $minimumDate;

            $reason = $beforeMinimumYear
                ? sprintf('O valor já vigora desde %s, data anterior a %d (leitura antiga de planilha).', $displayRecorded, SpreadsheetDate::MINIMUM_YEAR)
                : sprintf(
                    'O valor já vigora desde %s, que é %s com dia e mês trocados (leitura antiga de planilha).',
                    $displayRecorded,
                    SpreadsheetDate::display((string) $row['effective_from_date']),
                );

            $instruction = $current['source'] === ResolvedUnitValueSource::Base->value
                ? 'Se a data estiver errada, corrija a data de referência do valor base no cadastro da unidade.'
                : sprintf(
                    'Se a vigência registrada estiver errada, inclua nesta planilha uma linha da mesma unidade com o valor anterior (%s) e vigência %s.',
                    $previous['cents'] === null ? 'o valor que vigorava antes' : 'R$ '.IntegerMoney::format((int) $previous['cents']),
                    SpreadsheetDate::display($beforeMinimumYear ? $minimumDate : $recordedDate),
                );

            $rows[$index]['message'] = $reason.' Nada será gravado por esta linha. '.$instruction;
        }

        return $rows;
    }

    /**
     * Primeira fase: a linha resolvida e validada sozinha. O veredito contra o
     * valor vigente fica para a segunda fase -- `outcome` nulo quer dizer que a
     * linha chegou até lá.
     *
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
            'current_effective_from' => null,
            'current_source' => null,
            'registered_competences' => [],
            'registered_competence_notice' => null,
            'warnings' => [],
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

        $amount = SpreadsheetAmount::read($rawValue);
        $valueCents = $amount->cents;

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

        $ambiguity = $amount->warning('Valor atualizado');

        if ($ambiguity !== null) {
            $base['warnings'] = [['code' => ImportRowWarningCode::AmbiguousAmountText->value, 'message' => $ambiguity]];
        }

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
                    'warnings' => [],
                    'outcome' => ReconciliationOutcome::Conflict,
                    'message' => "A mesma unidade e vigência aparecem na linha {$previous['line']} com outro valor.",
                ];
            }

            return [
                ...$base,
                'warnings' => [],
                'outcome' => ReconciliationOutcome::DuplicatedInFile,
                'message' => "Unidade e vigência repetidas na planilha (linha {$previous['line']}).",
            ];
        }

        $seenUnits[$key] = ['line' => $lineNumber, 'cents' => $valueCents];

        return [...$base, 'outcome' => null, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function error(array $base, string $message): array
    {
        return [...$base, 'warnings' => [], 'outcome' => ReconciliationOutcome::Error, 'message' => $message];
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
