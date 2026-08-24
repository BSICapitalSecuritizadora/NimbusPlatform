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
use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
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
     * "construction id|code" already registered, seen inside the file.
     *
     * @var array<string, int>
     */
    private array $seenCodes = [];

    /**
     * Unit id => line of the earlier row that already sells it.
     *
     * @var array<int, int>
     */
    private array $seenLiveUnits = [];

    public function __construct(
        private readonly ContractReconciler $reconciler = new ContractReconciler,
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

        return new ContractSpreadsheetAnalysis($analyzedRows);
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
     * Units, clients, taken codes and live contracts for the whole chunk, in one
     * query each.
     *
     * @param  list<array<string, mixed>>  $parsedRows
     * @return array{clients: array<string, Client>, codes: array<string, Contract>, occupied: array<int, Contract>}
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
                ->with(['client', 'constructionUnit'])
                ->whereIn('construction_id', $constructionIds->all())
                ->whereIn('code_normalized', $codes->all())
                ->get();

        $unitIds = $rows
            ->map(fn (array $row): ?int => $this->findUnitId($this->resolveConstructionId($row), $row['block'], $row['unit']))
            ->filter()
            ->unique()
            ->values();

        $occupied = $unitIds->isEmpty()
            ? collect()
            : Contract::query()
                ->whereIn('construction_unit_id', $unitIds->all())
                ->whereIn('status', ContractStatus::occupyingValues())
                ->get(['id', 'construction_unit_id', 'code', 'status']);

        return [
            'clients' => $clients->keyBy('document')->all(),
            'codes' => $takenCodes
                ->mapWithKeys(fn (Contract $contract): array => [
                    self::codeKey($contract->construction_id, $contract->code_normalized) => $contract,
                ])
                ->all(),
            'occupied' => $occupied->keyBy('construction_unit_id')->all(),
        ];
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
     * @param  array<string, mixed>  $row
     * @param  array{clients: array<string, Client>, codes: array<string, Contract>, occupied: array<int, Contract>}  $context
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
            'construction_unit_id' => null,
            'construction_id' => null,
            'contract_id' => null,
            'comparison' => null,
            'sale_date' => null,
            'sale_value' => null,
            'contract_status' => null,
            'cancellation_date' => null,
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

        $codeKey = self::codeKey($construction['id'], $row['code_normalized']);

        if (isset($this->seenCodes[$codeKey])) {
            return [
                ...$base,
                'outcome' => ReconciliationOutcome::DuplicatedInFile,
                'message' => "Contrato repetido na planilha (linha {$this->seenCodes[$codeKey]}). Maiúsculas, minúsculas e espaços não distinguem um código do outro.",
            ];
        }

        $this->seenCodes[$codeKey] = $row['line'];

        $existing = $context['codes'][$codeKey] ?? null;

        /**
         * The row is about a contract already on record. It is compared rather
         * than refused -- and the unit checks below are skipped, because the
         * contract legitimately holds the unit it is already sold on.
         */
        if ($existing !== null) {
            if ($status->occupiesUnit()) {
                $this->seenLiveUnits[$unitId] = $row['line'];
            }

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

        if ($status->occupiesUnit()) {
            if (isset($this->seenLiveUnits[$unitId])) {
                return [
                    ...$base,
                    'outcome' => ReconciliationOutcome::DuplicatedInFile,
                    'message' => "Esta unidade já recebe um contrato ativo na linha {$this->seenLiveUnits[$unitId]} da planilha.",
                ];
            }

            $occupying = $context['occupied'][$unitId] ?? null;

            if ($occupying !== null) {
                return [
                    ...$base,
                    'outcome' => ReconciliationOutcome::Conflict,
                    'message' => sprintf(
                        'Esta unidade já possui um contrato %s (%s). Registre o distrato antes de importar um novo contrato.',
                        mb_strtolower($occupying->status->label()),
                        $occupying->code,
                    ),
                ];
            }

            $this->seenLiveUnits[$unitId] = $row['line'];
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
