<?php

namespace App\Actions\ContractInstallments;

use App\Concerns\MoneyFormatter;
use App\Enums\ReconciliationOutcome;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\ContractInstallment;
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
 * Nothing is ever created on the fly. The contract has to already exist, and it
 * is resolved the way the contracts module made it unique -- development plus
 * code, never the code on its own. Contract "A606" of development X and "A606"
 * of development Y are different contracts, and a file that only says "A606"
 * would otherwise book a schedule against the wrong sale.
 *
 * Lookups are batched per chunk instead of per row, so a file with hundreds of
 * installments costs a handful of queries rather than thousands.
 *
 * Reported line numbers count the header plus the data rows returned by the
 * reader. Fully blank rows are dropped by the reader itself, so a file with
 * blank rows in the middle reports the lines below them shifted up.
 */
class AnalyzeContractInstallmentSpreadsheet
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
     * "contract id|number" => line of the earlier row that already carries it.
     *
     * @var array<string, int>
     */
    private array $seenNumbers = [];

    /**
     * When set, the file is being imported from inside one contract's page and
     * may only touch that contract. The spreadsheet keeps the very same shape --
     * a row pointing somewhere else is reported, not silently redirected.
     */
    private ?int $restrictToContractId = null;

    public function __construct(
        private readonly ContractInstallmentReconciler $reconciler = new ContractInstallmentReconciler,
    ) {}

    public function handle(string $path, ?int $restrictToContractId = null): ContractInstallmentSpreadsheetAnalysis
    {
        $this->restrictToContractId = $restrictToContractId;

        $firstRow = SimpleExcelReader::create($path)->getRows()->first();

        if ($firstRow === null) {
            return ContractInstallmentSpreadsheetAnalysis::emptyFile();
        }

        $resolvedHeaders = ContractInstallmentSpreadsheetColumns::resolve($firstRow);
        $missingHeaders = ContractInstallmentSpreadsheetColumns::missingHeaders($resolvedHeaders);

        if ($missingHeaders !== []) {
            return ContractInstallmentSpreadsheetAnalysis::invalidHeaders($missingHeaders);
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

        return new ContractInstallmentSpreadsheetAnalysis($analyzedRows);
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
        return [
            'line' => $lineNumber,
            'emission' => $this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::EMISSION),
            'construction' => $this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::CONSTRUCTION),
            'contract_code' => Contract::normalizeCode($this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::CONTRACT)),
            'contract_code_normalized' => Contract::normalizeCodeForComparison($this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::CONTRACT)),
            'number' => ContractInstallment::normalizeNumber($this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::NUMBER)),
            'number_normalized' => ContractInstallment::normalizeNumberForComparison($this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::NUMBER)),
            'due_date_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::DUE_DATE),
            'expected_value_raw' => $this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::EXPECTED_VALUE),
            'payment_date_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::PAYMENT_DATE),
            'paid_value_raw' => $this->cell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::PAID_VALUE),
            'cancellation_date_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::CANCELLATION_DATE),
        ];
    }

    /**
     * Contracts and already registered numbers for the whole chunk, in one query
     * each.
     *
     * Contracts are looked up by code alone -- which the `code` index serves --
     * and matched to a development afterwards. That costs nothing extra and is
     * what lets a row whose code exists somewhere else be told apart from a row
     * whose code does not exist at all.
     *
     * @param  list<array<string, mixed>>  $parsedRows
     * @return array{contracts: array<string, Contract>, contractsByCode: array<string, list<Contract>>, numbers: array<string, ContractInstallment>}
     */
    private function loadChunkContext(array $parsedRows): array
    {
        $rows = collect($parsedRows);

        $codes = $rows->pluck('contract_code_normalized')->filter()->unique()->values();

        /**
         * Resolved on the same normalized identity the contracts module made
         * unique, so a spreadsheet saying "a606" finds the contract registered
         * as "A606". `code` is still selected because the messages quote the
         * code as the incorporadora wrote it, not as the comparison sees it.
         */
        $contracts = $codes->isEmpty()
            ? collect()
            : Contract::withTrashed()
                ->whereIn('code_normalized', $codes->all())
                ->get(['id', 'construction_id', 'code', 'code_normalized', 'deleted_at']);

        $contractsByCode = [];
        $contractsByKey = [];

        foreach ($contracts as $contract) {
            $contractsByCode[$contract->code_normalized][] = $contract;
            $contractsByKey[self::contractKey($contract->construction_id, $contract->code_normalized)] = $contract;
        }

        $contractIds = $contracts->pluck('id')->unique()->values();
        $numbers = $rows->pluck('number_normalized')->filter()->unique()->values();

        /**
         * The whole record, not just its key: a monthly file is mostly rows that
         * already exist, and each one has to be compared field by field rather
         * than merely recognized.
         */
        $registered = ($contractIds->isEmpty() || $numbers->isEmpty())
            ? collect()
            : ContractInstallment::query()
                ->whereIn('contract_id', $contractIds->all())
                ->whereIn('number_normalized', $numbers->all())
                ->get();

        return [
            'contracts' => $contractsByKey,
            'contractsByCode' => $contractsByCode,
            'numbers' => $registered
                ->mapWithKeys(fn (ContractInstallment $installment): array => [
                    self::numberKey($installment->contract_id, $installment->number_normalized) => $installment,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{contracts: array<string, Contract>, contractsByCode: array<string, list<Contract>>, numbers: array<string, ContractInstallment>}  $context
     * @return array<string, mixed>
     */
    private function classifyRow(array $row, array $context): array
    {
        $base = [
            'line' => $row['line'],
            'emission' => $row['emission'],
            'construction' => $row['construction'],
            'contract_code' => $row['contract_code'],
            'number' => $row['number'],
            'number_normalized' => $row['number_normalized'],
            'contract_id' => null,
            'installment_id' => null,
            'comparison' => null,
            'due_date' => null,
            'expected_value' => null,
            'payment_date' => null,
            'paid_value' => null,
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

        $contractError = $this->resolveContract($row, $construction['id'], $context);

        if ($contractError['error'] !== null) {
            return $this->error($base, $contractError['error']);
        }

        $contract = $contractError['contract'];
        $base['contract_id'] = (int) $contract->getKey();

        $dueDate = $this->parseDate($row['due_date_raw']);

        if ($dueDate === null) {
            return $this->error($base, 'Data de vencimento inválida. Utilize o formato dd/mm/aaaa.');
        }

        $base['due_date'] = $dueDate;

        $expectedValue = $this->parseAmount($row['expected_value_raw']);

        if (($expectedValue === null) || ($expectedValue <= 0)) {
            return $this->error($base, 'Valor previsto inválido: informe um valor maior que zero.');
        }

        $base['expected_value'] = $expectedValue;

        $payment = $this->resolvePayment($row);

        if ($payment['error'] !== null) {
            return $this->error($base, $payment['error']);
        }

        $base['payment_date'] = $payment['date'];
        $base['paid_value'] = $payment['value'];

        $cancellation = $this->resolveCancellationDate($row);

        if ($cancellation['error'] !== null) {
            return $this->error($base, $cancellation['error']);
        }

        $base['cancellation_date'] = $cancellation['date'];

        $numberKey = self::numberKey($base['contract_id'], $row['number_normalized']);

        if (isset($this->seenNumbers[$numberKey])) {
            return [
                ...$base,
                'outcome' => ReconciliationOutcome::DuplicatedInFile,
                'message' => "Parcela repetida na planilha (linha {$this->seenNumbers[$numberKey]}). Maiúsculas, minúsculas e espaços não distinguem uma parcela da outra.",
            ];
        }

        $this->seenNumbers[$numberKey] = $row['line'];

        $existing = $context['numbers'][$numberKey] ?? null;

        if ($existing === null) {
            return [...$base, 'outcome' => ReconciliationOutcome::New, 'message' => null];
        }

        /**
         * The row matched an installment already on the schedule. What happens
         * next depends on whether anything about it actually moved -- which is
         * what turns a re-import of the same position into a no-op.
         */
        $comparison = $this->reconciler->compare($existing, $base);

        return [
            ...$base,
            'installment_id' => (int) $existing->getKey(),
            'comparison' => $comparison,
            'outcome' => $comparison->outcome(),
            'message' => $comparison->isUnchanged() ? null : $comparison->summary(),
        ];
    }

    /**
     * The contract the row points at, or the reason it cannot be reached.
     *
     * @param  array<string, mixed>  $row
     * @param  array{contracts: array<string, Contract>, contractsByCode: array<string, list<Contract>>, numbers: array<string, ContractInstallment>}  $context
     * @return array{contract: Contract|null, error: string|null}
     */
    private function resolveContract(array $row, int $constructionId, array $context): array
    {
        $contract = $context['contracts'][self::contractKey($constructionId, $row['contract_code_normalized'])] ?? null;

        if ($contract === null) {
            $elsewhere = $context['contractsByCode'][(string) $row['contract_code_normalized']] ?? [];

            return [
                'contract' => null,
                'error' => $elsewhere === []
                    ? 'Contrato não encontrado.'
                    : 'Contrato não encontrado neste empreendimento. Este código pertence a outro empreendimento.',
            ];
        }

        if ($contract->trashed()) {
            return [
                'contract' => null,
                'error' => 'O contrato está excluído e não pode receber novas parcelas.',
            ];
        }

        if (($this->restrictToContractId !== null) && ((int) $contract->getKey() !== $this->restrictToContractId)) {
            return [
                'contract' => null,
                'error' => 'Esta linha pertence a outro contrato. Importe a partir da listagem geral de parcelas.',
            ];
        }

        return ['contract' => $contract, 'error' => null];
    }

    /**
     * Payment date and value travel together: either both are filled or neither
     * is. A date without a value would create an installment that looks received
     * and owes everything; a value without a date would leave a receipt nobody
     * can place in time.
     *
     * @param  array<string, mixed>  $row
     * @return array{date: ?string, value: ?float, error: ?string}
     */
    private function resolvePayment(array $row): array
    {
        $hasDateCell = $this->isFilled($row['payment_date_raw']);
        $hasValueCell = $this->isFilled($row['paid_value_raw']);

        /**
         * A paid value of zero with no payment date beside it means the same as
         * an empty cell: nothing received yet.
         *
         * The files the operators actually produce fill the column for every
         * installment and carry 0 until money arrives, so reading a zero as a
         * receipt would reject almost every row of a perfectly normal schedule.
         *
         * Only a parsed zero collapses this way. Text that is not a number stays
         * an inconsistency worth reporting, and a zero *with* a payment date
         * falls through to the amount check below -- a settlement of zero on a
         * given day is a real thing to decide about, not something to swallow.
         */
        if ($hasValueCell && ! $hasDateCell && ($this->parseAmount($row['paid_value_raw']) === 0.0)) {
            $hasValueCell = false;
        }

        if (! $hasDateCell && ! $hasValueCell) {
            return ['date' => null, 'value' => null, 'error' => null];
        }

        if (! $hasDateCell) {
            return ['date' => null, 'value' => null, 'error' => 'Informe a data do pagamento junto com o valor pago.'];
        }

        if (! $hasValueCell) {
            return ['date' => null, 'value' => null, 'error' => 'Informe o valor pago junto com a data do pagamento.'];
        }

        $paymentDate = $this->parseDate($row['payment_date_raw']);

        if ($paymentDate === null) {
            return ['date' => null, 'value' => null, 'error' => 'Data do pagamento inválida. Utilize o formato dd/mm/aaaa.'];
        }

        $paidValue = $this->parseAmount($row['paid_value_raw']);

        // No ceiling against the expected value on purpose: juros, multa and
        // correção monetária routinely push a receipt above what was due.
        if (($paidValue === null) || ($paidValue <= 0)) {
            return ['date' => null, 'value' => null, 'error' => 'Valor pago inválido: informe um valor maior que zero.'];
        }

        return ['date' => $paymentDate, 'value' => $paidValue, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{date: ?string, error: ?string}
     */
    private function resolveCancellationDate(array $row): array
    {
        if (! $this->isFilled($row['cancellation_date_raw'])) {
            return ['date' => null, 'error' => null];
        }

        $cancellationDate = $this->parseDate($row['cancellation_date_raw']);

        if ($cancellationDate === null) {
            return ['date' => null, 'error' => 'Data de cancelamento inválida. Utilize o formato dd/mm/aaaa.'];
        }

        return ['date' => $cancellationDate, 'error' => null];
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
        foreach (['emission', 'construction', 'contract_code', 'number', 'due_date_raw', 'expected_value_raw', 'payment_date_raw', 'paid_value_raw', 'cancellation_date_raw'] as $field) {
            if ($this->isFilled($row[$field] ?? null)) {
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
            ContractInstallmentSpreadsheetColumns::EMISSION => $row['emission'],
            ContractInstallmentSpreadsheetColumns::CONSTRUCTION => $row['construction'],
            ContractInstallmentSpreadsheetColumns::CONTRACT => $row['contract_code'],
            ContractInstallmentSpreadsheetColumns::NUMBER => $row['number'],
            ContractInstallmentSpreadsheetColumns::DUE_DATE => $row['due_date_raw'],
            ContractInstallmentSpreadsheetColumns::EXPECTED_VALUE => $row['expected_value_raw'],
        ];

        return array_values(array_keys(array_filter(
            $required,
            fn (mixed $value): bool => ! $this->isFilled($value),
        )));
    }

    private function isFilled(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        return filled(is_string($value) ? trim($value) : $value);
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
        // "03/10/2026" as an American date and book the installment in the wrong
        // month. The warning check rejects dates that only exist after a
        // rollover, such as 31/02/2026 silently becoming 02/03/2026.
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

    /**
     * Identity of a contract inside a development, from
     * {@see Contract::normalizeCodeForComparison()} -- the same function the
     * contracts module's unique index, form and import resolve through.
     *
     * This is the join between the two modules: a schedule is attached to a
     * contract by exactly the rule that decides which contracts are the same.
     */
    private static function contractKey(mixed $constructionId, ?string $code): string
    {
        return $constructionId.'|'.Contract::normalizeCodeForComparison($code);
    }

    /**
     * Identity of an installment inside a contract, from the same function the
     * model and the manual form use. The spreadsheet cannot have a looser -- or
     * a stricter -- notion of "the same parcela" than the rest of the module.
     *
     * Idempotent, so an already normalized value coming back from the database
     * keys to itself.
     */
    private static function numberKey(mixed $contractId, ?string $number): string
    {
        return $contractId.'|'.ContractInstallment::normalizeNumberForComparison($number);
    }

    private static function normalizeName(string $value): string
    {
        return Str::lower(trim($value));
    }
}
