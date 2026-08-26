<?php

namespace App\Actions\Contracts;

use App\Concerns\MoneyFormatter;
use App\Enums\ContractStatus;
use App\Enums\ReconciliationOutcome;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\Emission;
use App\Support\Reconciliation\ValueComparator;
use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads an import spreadsheet and classifies every row, without writing
 * anything. The result feeds the preview and, once confirmed, the import.
 *
 * Nothing is ever created on the fly: emission, development, unit and client
 * must already exist, and a row that fails to resolve any of them is invalid.
 * That is what keeps a typo from silently forking a duplicate cadastro.
 *
 * Lookups are batched per chunk instead of per row, so a file with hundreds of
 * contracts costs a handful of queries rather than thousands.
 *
 * The classification happens in two passes. The first judges each row on its
 * own -- fields, references, dates, and the comparison against the contract it
 * matched. The second, {@see ContractBatchProjection}, judges the file as a
 * whole, because whether a unit ends up with exactly one contract holding it is
 * not a property of any single line: a distrato on one row is what makes room
 * for the new contract on another, wherever in the file the two happen to be.
 *
 * Reported line numbers count the header plus the data rows returned by the
 * reader. Fully blank rows are dropped by the reader itself, so a file with
 * blank rows in the middle reports the lines below them shifted up.
 */
class AnalyzeContractSpreadsheet
{
    /**
     * Rows are read in chunks so large files do not sit in memory at once.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Emission name (normalized) => id.
     *
     * @var array<string, int>
     */
    private array $emissions = [];

    /**
     * Development name (normalized) => list of {id, emission_id}.
     *
     * @var array<string, list<array{id: int, emission_id: int}>>
     */
    private array $constructions = [];

    /**
     * Development id => "block|unit" => unit id.
     *
     * @var array<int, array<string, int>>
     */
    private array $unitMaps = [];

    /**
     * Client id => name, collected as the rows resolve their documents. The
     * grouping messages name buyers, and asking the database again for a name
     * already in hand would be a query per contract.
     *
     * @var array<int, string>
     */
    private array $clientNames = [];

    /**
     * Unit id => every contract of that unit, read once per unit and kept for the
     * whole file.
     *
     * All of them, not only the ones holding the unit: the projection needs the
     * position of every unit at once -- whether a distrato on line 900 frees the
     * unit a new contract on line 3 wants has nothing to do with where the chunk
     * boundary fell -- and the timeline check needs the periods that have already
     * closed, which the holders alone cannot tell it.
     *
     * @var array<int, list<array{id: int, code: string, status: ContractStatus, client: string|null, sale_date: string|null, cancellation_date: string|null}>>
     */
    private array $unitContracts = [];

    /**
     * Units already looked up, including the ones with no contract at all -- an
     * empty answer is an answer and must not be asked for again.
     *
     * @var array<int, true>
     */
    private array $loadedUnitContracts = [];

    public function __construct(
        private readonly ContractReconciler $reconciler = new ContractReconciler,
        private readonly ContractBatchProjection $projection = new ContractBatchProjection,
        private readonly ContractBuyerGrouping $grouping = new ContractBuyerGrouping,
    ) {}

    public function handle(string $path): ContractSpreadsheetAnalysis
    {
        $firstRow = SimpleExcelReader::create($path)->getRows()->first();

        if ($firstRow === null) {
            return ContractSpreadsheetAnalysis::emptyFile();
        }

        $resolvedHeaders = ContractSpreadsheetColumns::resolve($firstRow);
        $missingHeaders = ContractSpreadsheetColumns::missingHeaders($resolvedHeaders);

        if ($missingHeaders !== []) {
            return ContractSpreadsheetAnalysis::invalidHeaders($missingHeaders);
        }

        $this->loadReferenceData();

        $analyzedRows = [];
        $lineNumber = 1;

        SimpleExcelReader::create($path)
            ->getRows()
            ->chunk(self::CHUNK_SIZE)
            ->each(function (mixed $chunk) use (&$analyzedRows, &$lineNumber, $resolvedHeaders): void {
                $parsedRows = collect($chunk)
                    ->map(function (array $row) use (&$lineNumber, $resolvedHeaders): array {
                        $lineNumber++;

                        return $this->parseRow($row, $resolvedHeaders, $lineNumber);
                    })
                    ->all();

                $context = $this->loadChunkContext($parsedRows);

                foreach ($parsedRows as $parsedRow) {
                    $analyzedRows[] = $this->classifyRow($parsedRow, $context);
                }
            });

        /**
         * The lines of one contract become one entry before anything else looks
         * at them: the projection below counts contracts against units, and two
         * lines for one contract would read as two contracts on one unit.
         */
        $analyzedRows = $this->grouping->collapse(
            $analyzedRows,
            $this->currentBuyers($analyzedRows),
            $this->clientNames,
        );

        /**
         * Only now, with every row classified, can occupancy be decided: the
         * file is a position, not a sequence, and a distrato anywhere in it
         * frees the unit for a new contract anywhere else.
         */
        ['rows' => $analyzedRows, 'occupancies' => $occupancies] = $this->projection
            ->resolve($analyzedRows, $this->unitContracts);

        return new ContractSpreadsheetAnalysis($analyzedRows, unitOccupancies: $occupancies);
    }

    /**
     * The buyers the touched contracts hold today, in one query for the whole
     * file rather than one per contract.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, list<int>>
     */
    private function currentBuyers(array $rows): array
    {
        $contractIds = collect($rows)
            ->pluck('contract_id')
            ->filter()
            ->unique()
            ->values();

        if ($contractIds->isEmpty()) {
            return [];
        }

        $buyers = DB::table('contract_clients')
            ->whereIn('contract_id', $contractIds->all())
            ->get(['contract_id', 'client_id']);

        $names = Client::withTrashed()
            ->whereIn('id', $buyers->pluck('client_id')->unique()->all())
            ->pluck('name', 'id');

        foreach ($names as $id => $name) {
            $this->clientNames[(int) $id] = $name;
        }

        return $buyers
            ->groupBy('contract_id')
            ->map(fn (Collection $rows): array => $rows->pluck('client_id')->map('intval')->values()->all())
            ->all();
    }

    /**
     * Emissions and developments are few and referenced by almost every row, so
     * they are read once for the whole file.
     */
    private function loadReferenceData(): void
    {
        $this->emissions = Emission::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name): array => [self::normalizeName($name) => $id])
            ->all();

        $this->constructions = [];

        Construction::query()
            ->get(['id', 'emission_id', 'development_name'])
            ->each(function (Construction $construction): void {
                $key = self::normalizeName((string) $construction->development_name);

                $this->constructions[$key][] = [
                    'id' => (int) $construction->id,
                    'emission_id' => (int) $construction->emission_id,
                ];
            });
    }

    /**
     * Raw cells turned into normalized values, plus the emission/development
     * resolution, which only needs the maps already in memory.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     * @return array<string, mixed>
     */
    private function parseRow(array $row, array $resolvedHeaders, int $lineNumber): array
    {
        $emissionName = $this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::EMISSION);
        $constructionName = $this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::CONSTRUCTION);
        $block = ConstructionUnit::normalizeIdentifier($this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::BLOCK));
        $unit = ConstructionUnit::normalizeIdentifier($this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::UNIT));
        $document = Client::normalizeDocument($this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::DOCUMENT));
        $code = Contract::normalizeCode($this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::CODE));

        return [
            'line' => $lineNumber,
            'emission' => $emissionName,
            'construction' => $constructionName,
            'block' => $block,
            'unit' => $unit,
            'document' => $document,
            'code' => $code,
            'code_normalized' => Contract::normalizeCodeForComparison($code),
            'sale_date_raw' => $this->rawCell($row, $resolvedHeaders, ContractSpreadsheetColumns::SALE_DATE),
            'sale_value_raw' => $this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::SALE_VALUE),
            'status_raw' => $this->cell($row, $resolvedHeaders, ContractSpreadsheetColumns::STATUS),
            'cancellation_date_raw' => $this->rawCell($row, $resolvedHeaders, ContractSpreadsheetColumns::CANCELLATION_DATE),
        ];
    }

    /**
     * Units, clients and taken codes for the whole chunk, in one query each.
     *
     * The contracts of each unit are read here too, but they are kept on the
     * instance rather than returned: they belong to the file, not to the chunk,
     * and the projection at the end needs all of them at once.
     *
     * @param  list<array<string, mixed>>  $parsedRows
     * @return array{clients: array<string, Client>, codes: array<string, Contract>}
     */
    private function loadChunkContext(array $parsedRows): array
    {
        $rows = collect($parsedRows);

        $constructionIds = $rows
            ->map(fn (array $row): ?int => $this->resolveConstructionId($row))
            ->filter()
            ->unique()
            ->values();

        foreach ($constructionIds as $constructionId) {
            $this->loadUnitsOf($constructionId);
        }

        $documents = $rows->pluck('document')->filter()->unique()->values();

        $clients = $documents->isEmpty()
            ? collect()
            : Client::withTrashed()->whereIn('document', $documents->all())->get();

        $codes = $rows->pluck('code_normalized')->filter()->unique()->values();

        /**
         * Matched on the normalized identity and including the soft deleted
         * ones: a code belongs to its development for good, so "A606" is taken
         * whether the contract holding it is live, distratado or deleted.
         */
        $takenCodes = ($constructionIds->isEmpty() || $codes->isEmpty())
            ? collect()
            : Contract::withTrashed()
                ->with(['clients', 'constructionUnit'])
                ->whereIn('construction_id', $constructionIds->all())
                ->whereIn('code_normalized', $codes->all())
                ->get();

        $this->loadUnitContracts($rows
            ->map(fn (array $row): ?int => $this->findUnitId($this->resolveConstructionId($row), $row['block'], $row['unit']))
            ->filter()
            ->unique()
            ->values()
            ->all());

        return [
            'clients' => $clients->keyBy('document')->all(),
            'codes' => $takenCodes
                ->mapWithKeys(fn (Contract $contract): array => [
                    self::codeKey($contract->construction_id, $contract->code_normalized) => $contract,
                ])
                ->all(),
        ];
    }

    /**
     * Every contract of each unit, kept as a list. Which of them holds the unit
     * is derived by the projection from the status, so this reads the position
     * once and answers both questions it has to answer -- who holds the unit now,
     * and who held it before.
     *
     * @param  list<int>  $unitIds
     */
    private function loadUnitContracts(array $unitIds): void
    {
        $pending = array_values(array_filter(
            $unitIds,
            fn (int $unitId): bool => ! isset($this->loadedUnitContracts[$unitId]),
        ));

        if ($pending === []) {
            return;
        }

        foreach ($pending as $unitId) {
            $this->loadedUnitContracts[$unitId] = true;
        }

        Contract::query()
            ->with('clients:id,name')
            ->whereIn('construction_unit_id', $pending)
            ->get(['id', 'construction_unit_id', 'code', 'status', 'sale_date', 'cancellation_date'])
            ->each(function (Contract $contract): void {
                $this->unitContracts[(int) $contract->construction_unit_id][] = [
                    'id' => (int) $contract->getKey(),
                    'code' => (string) $contract->code,
                    'status' => $contract->status,
                    'client' => $contract->buyersLabel(),
                    'sale_date' => ValueComparator::date($contract->sale_date),
                    'cancellation_date' => ValueComparator::date($contract->cancellation_date),
                ];
            });
    }

    /**
     * Units of a development, read once per file and reused by every chunk that
     * mentions it. Keyed case-insensitively so "01a" and "01A" find each other.
     */
    private function loadUnitsOf(int $constructionId): void
    {
        if (array_key_exists($constructionId, $this->unitMaps)) {
            return;
        }

        $this->unitMaps[$constructionId] = ConstructionUnit::query()
            ->where('construction_id', $constructionId)
            ->get(['id', 'block', 'unit'])
            ->mapWithKeys(fn (ConstructionUnit $unit): array => [
                self::unitKey($unit->block, $unit->unit) => (int) $unit->id,
            ])
            ->all();
    }

    private function findUnitId(?int $constructionId, ?string $block, ?string $unit): ?int
    {
        if ($constructionId === null) {
            return null;
        }

        $this->loadUnitsOf($constructionId);

        return $this->unitMaps[$constructionId][self::unitKey($block, $unit)] ?? null;
    }

    /**
     * Every check that can be made on the row alone. Whether the unit ends up
     * with one holder is deliberately not one of them -- that depends on the
     * rest of the file and is settled by {@see ContractBatchProjection} once
     * every row has been read.
     *
     * @param  array<string, mixed>  $row
     * @param  array{clients: array<string, Client>, codes: array<string, Contract>}  $context
     * @return array<string, mixed>
     */
    private function classifyRow(array $row, array $context): array
    {
        $base = [
            'line' => $row['line'],
            'emission' => $row['emission'],
            'construction' => $row['construction'],
            'block' => $row['block'],
            'unit' => $row['unit'],
            'unit_label' => trim(sprintf('%s - %s', (string) $row['block'], (string) $row['unit']), ' -'),
            'code' => $row['code'],
            'code_normalized' => $row['code_normalized'],
            'client_label' => null,
            'client_id' => null,
            'client_ids' => [],
            'buyer_comparison' => null,
            'lines' => [$row['line']],
            'construction_unit_id' => null,
            'construction_id' => null,
            'contract_id' => null,
            'comparison' => null,
            'sale_date' => null,
            'sale_value' => null,
            'contract_status' => null,
            'cancellation_date' => null,
            'releases_unit' => false,
        ];

        if ($this->isBlankRow($row)) {
            return [...$base, 'outcome' => ReconciliationOutcome::Empty, 'message' => 'Linha vazia (ignorada).'];
        }

        $missingFields = $this->missingFields($row);

        if ($missingFields !== []) {
            return $this->error($base, 'Campos obrigatórios não preenchidos: '.implode(', ', $missingFields).'.');
        }

        if (! isset($this->emissions[self::normalizeName((string) $row['emission'])])) {
            return $this->error($base, 'Emissão não encontrada.');
        }

        $construction = $this->resolveConstruction($row);

        if ($construction === null) {
            return $this->error($base, 'Empreendimento não encontrado.');
        }

        if (! $construction['belongs_to_emission']) {
            return $this->error($base, 'O empreendimento informado não pertence à emissão selecionada.');
        }

        $base['construction_id'] = $construction['id'];

        $unitId = $this->findUnitId($construction['id'], $row['block'], $row['unit']);

        if ($unitId === null) {
            return $this->error($base, sprintf(
                'Unidade %s do bloco %s não encontrada no empreendimento %s.',
                $row['unit'],
                $row['block'],
                $row['construction'],
            ));
        }

        $base['construction_unit_id'] = $unitId;

        $documentLength = strlen((string) $row['document']);

        if (! in_array($documentLength, [11, 14], true)) {
            return $this->error($base, 'CPF/CNPJ inválido: informe 11 dígitos para CPF ou 14 para CNPJ.');
        }

        $client = $context['clients'][$row['document']] ?? null;

        if ($client === null) {
            return $this->error($base, sprintf(
                'Cliente com o CPF/CNPJ %s não encontrado.',
                Client::formatDocument($row['document']),
            ));
        }

        if ($client->trashed()) {
            return $this->error($base, sprintf('O cliente %s está excluído e não pode receber novos contratos.', $client->name));
        }

        $base['client_id'] = (int) $client->getKey();
        $base['client_label'] = $client->name;

        $this->clientNames[(int) $client->getKey()] = $client->name;

        $status = ContractStatus::tryFromLabel($row['status_raw']);

        if ($status === null) {
            return $this->error($base, sprintf('Status "%s" não reconhecido. Utilize: %s.', $row['status_raw'], implode(', ', ContractStatus::options())));
        }

        $base['contract_status'] = $status;

        $saleDate = $this->parseDate($row['sale_date_raw']);

        if ($saleDate === null) {
            return $this->error($base, 'Data da venda inválida. Utilize o formato dd/mm/aaaa.');
        }

        $base['sale_date'] = $saleDate;

        $saleValue = $this->parseAmount($row['sale_value_raw']);

        if (($saleValue === null) || ($saleValue <= 0)) {
            return $this->error($base, 'Valor da venda inválido: informe um valor maior que zero.');
        }

        $base['sale_value'] = $saleValue;

        ['date' => $cancellationDate, 'error' => $cancellationError] = $this->resolveCancellationDate($row, $status, $saleDate);

        if ($cancellationError !== null) {
            return $this->error($base, $cancellationError);
        }

        $base['cancellation_date'] = $cancellationDate;

        /**
         * A repeated contract code is no longer a defect: one line per buyer is
         * the format, so the same contract legitimately appears as many times as
         * it has buyers. What the lines must agree on, and whether a buyer was
         * listed twice, is decided once the whole file is read --
         * see {@see ContractBuyerGrouping}.
         */
        $codeKey = self::codeKey($construction['id'], $row['code_normalized']);

        $existing = $context['codes'][$codeKey] ?? null;

        /**
         * The row is about a contract already on record. It is compared rather
         * than refused -- and the unit checks below are skipped, because the
         * contract legitimately holds the unit it is already sold on.
         */
        if ($existing !== null) {
            if ($existing->trashed()) {
                return [
                    ...$base,
                    'outcome' => ReconciliationOutcome::Conflict,
                    'message' => 'Existe um contrato excluído com este código neste empreendimento. Restaure-o ou utilize outro código.',
                ];
            }

            $comparison = $this->reconciler->compare($existing, $base);

            return [
                ...$base,
                'contract_id' => (int) $existing->getKey(),
                'comparison' => $comparison,
                'outcome' => $comparison->outcome(),
                'message' => $comparison->isUnchanged() ? null : $comparison->summary(),
            ];
        }

        return [...$base, 'outcome' => ReconciliationOutcome::New, 'message' => null];
    }

    /**
     * The distrato date, or the reason the row cannot be imported with the date
     * it carries. Both are returned so a valid date is never mistaken for a
     * message.
     *
     * @param  array<string, mixed>  $row
     * @return array{date: ?string, error: ?string}
     */
    private function resolveCancellationDate(array $row, ContractStatus $status, string $saleDate): array
    {
        $rawCancellationDate = $row['cancellation_date_raw'];
        $hasCancellationDate = ($rawCancellationDate instanceof DateTimeInterface)
            || filled(is_string($rawCancellationDate) ? trim($rawCancellationDate) : $rawCancellationDate);

        if (! $status->requiresCancellationDate()) {
            return $hasCancellationDate
                ? ['date' => null, 'error' => sprintf('Contratos com status %s não podem ter data de distrato.', $status->label())]
                : ['date' => null, 'error' => null];
        }

        if (! $hasCancellationDate) {
            return ['date' => null, 'error' => 'Contratos distratados exigem a data do distrato.'];
        }

        $cancellationDate = $this->parseDate($rawCancellationDate);

        if ($cancellationDate === null) {
            return ['date' => null, 'error' => 'Data do distrato inválida. Utilize o formato dd/mm/aaaa.'];
        }

        if ($cancellationDate < $saleDate) {
            return ['date' => null, 'error' => 'A data do distrato não pode ser anterior à data da venda.'];
        }

        if (! Contract::cancellationDateHasTakenEffect($cancellationDate)) {
            return ['date' => null, 'error' => 'A data do distrato não pode ser futura: enquanto o distrato não ocorrer, o contrato permanece ativo.'];
        }

        return ['date' => $cancellationDate, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveConstructionId(array $row): ?int
    {
        return $this->resolveConstruction($row)['id'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, belongs_to_emission: bool}|null
     */
    private function resolveConstruction(array $row): ?array
    {
        $candidates = $this->constructions[self::normalizeName((string) ($row['construction'] ?? ''))] ?? [];

        if ($candidates === []) {
            return null;
        }

        $emissionId = $this->emissions[self::normalizeName((string) ($row['emission'] ?? ''))] ?? null;

        foreach ($candidates as $candidate) {
            if ($candidate['emission_id'] === $emissionId) {
                return ['id' => $candidate['id'], 'belongs_to_emission' => true];
            }
        }

        return ['id' => $candidates[0]['id'], 'belongs_to_emission' => false];
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
     * @param  array<string, mixed>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach (['emission', 'construction', 'block', 'unit', 'document', 'code', 'sale_date_raw', 'sale_value_raw', 'status_raw'] as $field) {
            $value = $row[$field] ?? null;

            if ($value instanceof DateTimeInterface) {
                return false;
            }

            if (filled(is_string($value) ? trim($value) : $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function missingFields(array $row): array
    {
        $required = [
            ContractSpreadsheetColumns::EMISSION => $row['emission'],
            ContractSpreadsheetColumns::CONSTRUCTION => $row['construction'],
            ContractSpreadsheetColumns::BLOCK => $row['block'],
            ContractSpreadsheetColumns::UNIT => $row['unit'],
            ContractSpreadsheetColumns::DOCUMENT => $row['document'],
            ContractSpreadsheetColumns::CODE => $row['code'],
            ContractSpreadsheetColumns::SALE_DATE => $row['sale_date_raw'],
            ContractSpreadsheetColumns::SALE_VALUE => $row['sale_value_raw'],
            ContractSpreadsheetColumns::STATUS => $row['status_raw'],
        ];

        return array_values(array_keys(array_filter(
            $required,
            static fn (mixed $value): bool => ! ($value instanceof DateTimeInterface)
                && blank(is_string($value) ? trim($value) : $value),
        )));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     */
    private function cell(array $row, array $resolvedHeaders, string $column): ?string
    {
        $value = $this->rawCell($row, $resolvedHeaders, $column);

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format('d/m/Y');
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $resolvedHeaders
     */
    private function rawCell(array $row, array $resolvedHeaders, string $column): mixed
    {
        $header = $resolvedHeaders[$column] ?? null;

        return $header === null ? null : ($row[$header] ?? null);
    }

    /**
     * Accepts what the operators actually type, plus what the reader hands over
     * for a real date cell.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalizedValue = trim((string) $value);

        if ($normalizedValue === '') {
            return null;
        }

        // No loose Carbon::parse() fallback on purpose: it would read
        // "03/10/2024" as an American date and book the sale in the wrong month.
        // The warning check rejects dates that only exist after a rollover, such
        // as 31/02/2024 silently becoming 02/03/2024.
        foreach (['d/m/Y', 'd/m/Y H:i:s', 'Y-m-d', 'Y-m-d H:i:s'] as $format) {
            $date = DateTime::createFromFormat($format, $normalizedValue);
            $errors = DateTime::getLastErrors();

            if ($date === false) {
                continue;
            }

            if (is_array($errors) && ((($errors['error_count'] ?? 0) > 0) || (($errors['warning_count'] ?? 0) > 0))) {
                continue;
            }

            return Carbon::instance($date)->toDateString();
        }

        return null;
    }

    private function parseAmount(?string $value): ?float
    {
        if (blank($value)) {
            return null;
        }

        if (preg_match('/\d/', $value) !== 1) {
            return null;
        }

        return MoneyFormatter::normalizeDecimalValue($value);
    }

    private static function unitKey(?string $block, ?string $unit): string
    {
        return Str::lower((string) $block).'|'.Str::lower((string) $unit);
    }

    /**
     * Identity of a contract inside a development, from the same function the
     * model and the form use. The spreadsheet cannot have a looser -- or a
     * stricter -- notion of "the same contract" than the rest of the system.
     *
     * Idempotent, so an already normalized value coming back from the database
     * keys to itself.
     */
    private static function codeKey(mixed $constructionId, ?string $code): string
    {
        return $constructionId.'|'.Contract::normalizeCodeForComparison($code);
    }

    private static function normalizeName(string $value): string
    {
        return Str::lower(trim($value));
    }
}
