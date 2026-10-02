<?php

namespace App\Actions\ContractInstallments;

use App\Enums\ChangeSeverity;
use App\Enums\ImportRowWarningCode;
use App\Enums\ReconciliationOutcome;
use App\Exceptions\UnreadableSpreadsheetException;
use App\Models\Construction;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Services\SalesBoards\RegisteredCompetenceIndex;
use App\Support\BusinessTime;
use App\Support\Dates\SpreadsheetDate;
use App\Support\Imports\SpreadsheetAmount;
use App\Support\Imports\SpreadsheetPlausibility;
use App\Support\Imports\SpreadsheetRows;
use App\Support\Money\IntegerMoney;
use App\Support\Reconciliation\FieldChange;
use App\Support\Reconciliation\ValueComparator;
use Carbon\Carbon;
use DateTimeInterface;
use Generator;
use Illuminate\Support\Str;

/**
 * Reads an import spreadsheet and classifies every row, without writing
 * anything. The result feeds the conference and, once confirmed, the import.
 *
 * Nothing is ever created on the fly. The contract has to already exist, and it
 * is resolved the way the contracts module made it unique -- development plus
 * code, never the code on its own. Contract "A606" of development X and "A606"
 * of development Y are different contracts, and a file that only says "A606"
 * would otherwise book a schedule against the wrong sale.
 *
 * The file is read as a stream ({@see self::open()}): rows are classified chunk
 * by chunk and handed over one at a time, and nothing keeps the whole file in
 * memory. A monthly portfolio runs to hundreds of thousands of rows; holding
 * them all -- each with its hydrated installment -- cost hundreds of megabytes
 * and took the request past its time limit. {@see self::handle()} folds the
 * stream into the bounded conference summary.
 *
 * Lookups are batched per chunk instead of per row, and the installments on
 * record are read as raw rows ({@see StoredInstallment}) rather than models.
 * Most rows of a monthly file are identical to what is on record; that is
 * decided by a quick comparison, and only what differs goes through
 * {@see ContractInstallmentReconciler}, which stays the authority.
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
     * The reader cannot open the file. Reported as a problem of the file, never
     * as an exception on the conference screen.
     */
    public const UNREADABLE_FILE_MESSAGE = SpreadsheetRows::UNREADABLE_FILE_MESSAGE;

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

    public function __construct(
        private readonly ContractInstallmentReconciler $reconciler = new ContractInstallmentReconciler,
    ) {}

    /**
     * The conference summary of the file: the stream folded into counters, a
     * bounded sample and the digest.
     */
    public function handle(string $path, ?int $restrictToContractId = null): ContractInstallmentSpreadsheetAnalysis
    {
        return ContractInstallmentSpreadsheetAnalysis::fold($this->open($path, $restrictToContractId));
    }

    /**
     * Opens the file for one streamed pass.
     *
     * @param  int|null  $restrictToContractId  when set, the file is being imported
     *                                          from inside one contract's page and
     *                                          may only touch that contract
     */
    public function open(string $path, ?int $restrictToContractId = null): ContractInstallmentSpreadsheetReading
    {
        try {
            $firstRow = SpreadsheetRows::first($path);
        } catch (UnreadableSpreadsheetException $exception) {
            return ContractInstallmentSpreadsheetReading::withFileErrors([$exception->getMessage()]);
        }

        if ($firstRow === null) {
            return ContractInstallmentSpreadsheetReading::withFileErrors(['A planilha está vazia.']);
        }

        $resolvedHeaders = ContractInstallmentSpreadsheetColumns::resolve($firstRow);
        $missingHeaders = ContractInstallmentSpreadsheetColumns::missingHeaders($resolvedHeaders);

        if ($missingHeaders !== []) {
            return ContractInstallmentSpreadsheetReading::withFileErrors([
                'A planilha não possui as colunas obrigatórias: '.implode(', ', $missingHeaders).'.',
            ]);
        }

        $this->loadReferenceData();

        return ContractInstallmentSpreadsheetReading::streaming(
            $this->classifiedRows($path, $resolvedHeaders, $restrictToContractId),
        );
    }

    /**
     * The rows of the file, classified chunk by chunk, followed by the absence
     * report once the last one is out.
     *
     * Everything that belongs to one reading lives here, in local variables:
     * which numbers each contract already carried, which contracts the file
     * reached, the registered competences of each development. Two readings
     * never share any of it.
     *
     * @param  array<string, string>  $resolvedHeaders
     * @return Generator<int, array<string, mixed>, mixed, ContractInstallmentAbsenceReport>
     */
    private function classifiedRows(string $path, array $resolvedHeaders, ?int $restrictToContractId): Generator
    {
        $state = new InstallmentReadingState(
            restrictToContractId: $restrictToContractId,
            businessToday: BusinessTime::dateString(),
            registeredCompetences: RegisteredCompetenceIndex::onDemand(),
        );

        $lineNumber = 1;

        foreach (SpreadsheetRows::chunks($path, self::CHUNK_SIZE) as $chunk) {
            $parsedRows = [];

            foreach ($chunk as $row) {
                $lineNumber++;
                $parsedRows[] = $this->parseRow($row, $resolvedHeaders, $lineNumber);
            }

            $context = $this->loadChunkContext($parsedRows);

            foreach ($parsedRows as $parsedRow) {
                yield $this->flagRegisteredCompetence($this->classifyRow($parsedRow, $context, $state), $state);
            }
        }

        return ContractInstallmentAbsenceReport::collect($state->presentContracts, $state->mentionedNumbers(), $state->contractConstructions);
    }

    /**
     * Marks the row that touches a fact of a competence already registered on
     * the Sales Board. The warning never blocks: correcting the source is
     * legitimate, but the registered position does not follow it on its own,
     * and whoever confirms has to know that.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function flagRegisteredCompetence(array $row, InstallmentReadingState $state): array
    {
        if (! $row['outcome']->writesToDatabase()) {
            return $row;
        }

        $dates = $this->affectedDates($row);

        $row['registered_competences'] = $state->registeredCompetences->reachedBy($row['construction_id'], ...$dates);
        $row['registered_competence_notice'] = $state->registeredCompetences->noticeFor(
            $row['construction_id'],
            $this->noticeSubject($row),
            ...$dates,
        );

        return $row;
    }

    /**
     * Como o aviso fala do fato da linha: o pagamento ou o cancelamento da
     * parcela -- os dois decidem a quitação.
     *
     * @param  array<string, mixed>  $row
     */
    private function noticeSubject(array $row): string
    {
        return filled($row['cancellation_date'] ?? null) && blank($row['payment_date'] ?? null)
            ? RegisteredCompetenceIndex::SUBJECT_INSTALLMENT_CANCELLATION
            : (filled($row['payment_date'] ?? null) ? RegisteredCompetenceIndex::SUBJECT_PAYMENT : RegisteredCompetenceIndex::SUBJECT_FACT);
    }

    /**
     * The dates from which the row changes what the Sales Board derives.
     *
     * Settlement is decided by the schedule alone -- valid on the day and paid
     * in full by it -- so the due date moves nothing there. A new installment is
     * a new obligation for the whole life of the contract and reaches back to
     * the sale; a receipt, a paid value or a cancellation reaches back to the
     * earlier of the day on record and the day in the file; a new expected value
     * matters only where the installment already counts as paid.
     *
     * @param  array<string, mixed>  $row
     * @return list<string|null>
     */
    private function affectedDates(array $row): array
    {
        if ($row['outcome'] === ReconciliationOutcome::New) {
            return [$row['contract_sale_date'], $row['payment_date'], $row['cancellation_date']];
        }

        $dates = [];

        foreach ($row['comparison']?->changes ?? [] as $change) {
            /** @var FieldChange $change */
            if ($change->severity === ChangeSeverity::Informative) {
                continue;
            }

            $dates = [...$dates, ...match ($change->field) {
                'payment_date' => [$row['payment_date'], $row['stored_payment_date']],
                'paid_value', 'expected_value', 'discount_value' => [$row['payment_date'] ?? $row['stored_payment_date']],
                'cancellation_date' => [$row['cancellation_date'], $row['stored_cancellation_date']],
                default => [],
            }];
        }

        return $dates;
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
            /**
             * Amounts stay raw: a numeric cell is read for the number it holds.
             * Turned into text first, 1553.919 became "1553.919" and then
             * R$ 1.553.919,00 -- a dot followed by three digits reads as a
             * thousands separator.
             */
            'expected_value_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::EXPECTED_VALUE),
            'payment_date_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::PAYMENT_DATE),
            'paid_value_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::PAID_VALUE),
            'cancellation_date_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::CANCELLATION_DATE),
            'discount_value_raw' => $this->rawCell($row, $resolvedHeaders, ContractInstallmentSpreadsheetColumns::DISCOUNT_VALUE),
            /**
             * Whether the file carries the optional discount column at all. A
             * file without it says nothing about the discount on record, which
             * is then neither compared nor written.
             */
            'discount_in_file' => isset($resolvedHeaders[ContractInstallmentSpreadsheetColumns::DISCOUNT_VALUE]),
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
     * The installments come back as raw rows through `toBase()`, which keeps
     * the soft delete scope: hydrating the model cost about 2 KB per row, and a
     * monthly file is mostly rows that only need to be recognized.
     *
     * @param  list<array<string, mixed>>  $parsedRows
     * @return array{contracts: array<string, Contract>, contractsByCode: array<string, list<Contract>>, numbers: array<string, StoredInstallment>}
     */
    protected function loadChunkContext(array $parsedRows): array
    {
        $codes = array_values(array_unique(array_filter(array_column($parsedRows, 'contract_code_normalized'))));

        /**
         * Resolved on the same normalized identity the contracts module made
         * unique, so a spreadsheet saying "a606" finds the contract registered
         * as "A606". `code` is still selected because the messages quote the
         * code as the incorporadora wrote it, not as the comparison sees it.
         * `sale_value` is the reference of the plausibility rules.
         */
        $contracts = $codes === []
            ? collect()
            : Contract::withTrashed()
                ->whereIn('code_normalized', $codes)
                ->get(['id', 'construction_id', 'code', 'code_normalized', 'sale_date', 'sale_value', 'deleted_at']);

        $contractsByCode = [];
        $contractsByKey = [];

        foreach ($contracts as $contract) {
            $contractsByCode[$contract->code_normalized][] = $contract;
            $contractsByKey[self::contractKey($contract->construction_id, $contract->code_normalized)] = $contract;
        }

        $contractIds = $contracts->pluck('id')->unique()->values()->all();
        $numbers = array_values(array_unique(array_filter(array_column($parsedRows, 'number_normalized'))));

        $registered = [];

        if (($contractIds !== []) && ($numbers !== [])) {
            ContractInstallment::query()
                ->toBase()
                ->whereIn('contract_id', $contractIds)
                ->whereIn('number_normalized', $numbers)
                ->get(StoredInstallment::COLUMNS)
                ->each(function (object $row) use (&$registered): void {
                    $installment = StoredInstallment::fromRow($row);

                    $registered[self::numberKey($installment->contractId, $installment->numberNormalized)] = $installment;
                });
        }

        return [
            'contracts' => $contractsByKey,
            'contractsByCode' => $contractsByCode,
            'numbers' => $registered,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{contracts: array<string, Contract>, contractsByCode: array<string, list<Contract>>, numbers: array<string, StoredInstallment>}  $context
     * @return array<string, mixed>
     */
    private function classifyRow(array $row, array $context, InstallmentReadingState $state): array
    {
        $base = [
            'line' => $row['line'],
            'emission' => $row['emission'],
            'construction' => $row['construction'],
            'contract_code' => $row['contract_code'],
            'number' => $row['number'],
            'number_normalized' => $row['number_normalized'],
            'construction_id' => null,
            'contract_id' => null,
            'contract_sale_date' => null,
            'installment_id' => null,
            'comparison' => null,
            'due_date' => null,
            'expected_value' => null,
            'expected_cents' => null,
            'payment_date' => null,
            'paid_value' => null,
            'paid_cents' => null,
            'discount_value' => null,
            'discount_cents' => null,
            'discount_in_file' => $row['discount_in_file'],
            'cancellation_date' => null,
            'stored_payment_date' => null,
            'stored_cancellation_date' => null,
            'registered_competences' => [],
            'registered_competence_notice' => null,
            'warnings' => [],
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

        if ($construction['ambiguous']) {
            return $this->error($base, 'Há mais de um empreendimento com este nome nesta emissão. Diferencie os nomes antes de importar.');
        }

        $contractError = $this->resolveContract($row, $construction['id'], $context, $state->restrictToContractId);

        if ($contractError['error'] !== null) {
            return $this->error($base, $contractError['error']);
        }

        $contract = $contractError['contract'];
        $contractId = (int) $contract->getKey();

        $base['construction_id'] = $construction['id'];
        $base['contract_id'] = $contractId;
        $base['contract_sale_date'] = ValueComparator::date($contract->sale_date);

        /**
         * From here on the row names a parcela of a contract on record, valid or
         * not: it is in the file, so it is never reported as absent from it.
         */
        $state->reachContract($contractId, (string) $contract->code, (int) $construction['id']);
        $state->mention($contractId, $row['number_normalized'], $row['line']);

        $dueDate = $this->parseDate($row['due_date_raw']);

        if ($dueDate === null) {
            return $this->error($base, 'Data de vencimento inválida. '.SpreadsheetDate::FORMAT_HINT);
        }

        $base['due_date'] = $dueDate;

        $expectedAmount = SpreadsheetAmount::read($row['expected_value_raw']);

        if (($expectedAmount->cents === null) || ($expectedAmount->cents <= 0)) {
            return $this->error($base, 'Valor previsto inválido: informe um valor maior que zero.');
        }

        $base['expected_cents'] = $expectedAmount->cents;
        $base['expected_value'] = self::decimalAmount($expectedAmount->cents);

        $warnings = self::ambiguityWarning($expectedAmount, 'Valor previsto');

        $payment = $this->resolvePayment($row, $state->businessToday);

        if ($payment['error'] !== null) {
            return $this->error($base, $payment['error']);
        }

        $base['payment_date'] = $payment['date'];
        $base['paid_cents'] = $payment['cents'];
        $base['paid_value'] = $payment['cents'] === null ? null : self::decimalAmount($payment['cents']);

        if ($payment['amount'] !== null) {
            $warnings = [...$warnings, ...self::ambiguityWarning($payment['amount'], 'Valor pago')];
        }

        $cancellation = $this->resolveCancellationDate($row);

        if ($cancellation['error'] !== null) {
            return $this->error($base, $cancellation['error']);
        }

        $base['cancellation_date'] = $cancellation['date'];

        $discount = $this->resolveDiscount($row, $payment['date'], $base['expected_cents']);

        if ($discount['error'] !== null) {
            return $this->error($base, $discount['error']);
        }

        $base['discount_cents'] = $discount['cents'];
        $base['discount_value'] = $discount['cents'] === null ? null : self::decimalAmount($discount['cents']);

        if ($discount['amount'] !== null) {
            $warnings = [...$warnings, ...self::ambiguityWarning($discount['amount'], 'Desconto')];
        }

        /**
         * Against the sale value of the contract: the clearly impossible is an
         * error of the row, the doubtful a warning beside it.
         */
        $plausibility = SpreadsheetPlausibility::installment(
            $base['expected_cents'],
            $base['paid_cents'],
            IntegerMoney::cents($contract->sale_value),
        );

        if ($plausibility->isImpossible()) {
            return $this->error($base, (string) $plausibility->error);
        }

        $warnings = [...$warnings, ...self::scalarWarnings($plausibility->warnings)];

        $seenAt = $state->seenAt($contractId, $row['number_normalized']);

        if ($seenAt !== null) {
            return [
                ...$base,
                'outcome' => ReconciliationOutcome::DuplicatedInFile,
                'message' => "Parcela repetida na planilha (linha {$seenAt}). Maiúsculas, minúsculas e espaços não distinguem uma parcela da outra.",
            ];
        }

        $state->see($contractId, $row['number_normalized'], $row['line']);

        $base['warnings'] = $warnings;

        $existing = $context['numbers'][self::numberKey($contractId, $row['number_normalized'])] ?? null;

        if ($existing === null) {
            return [...$base, 'outcome' => ReconciliationOutcome::New, 'message' => null];
        }

        $base['installment_id'] = $existing->id;
        $base['stored_payment_date'] = $existing->paymentDate;
        $base['stored_cancellation_date'] = $existing->cancellationDate;

        /**
         * The row matched an installment already on the schedule. Most of a
         * monthly file is exactly what is on record, and that is decided here in
         * plain strings and cents; only a row that differs is handed to the
         * reconciler, which decides what moved and how much it matters.
         */
        if ($existing->isIdenticalTo(
            $dueDate,
            $base['expected_cents'],
            $base['payment_date'],
            $base['paid_cents'],
            $base['cancellation_date'],
            $base['discount_cents'],
            $base['discount_in_file'],
        )) {
            return [...$base, 'outcome' => ReconciliationOutcome::Unchanged, 'message' => null];
        }

        $comparison = $this->reconciler->compare($existing, $base);

        return [
            ...$base,
            'comparison' => $comparison,
            'outcome' => $comparison->outcome(),
            'message' => $comparison->isUnchanged() ? null : $comparison->summary(),
        ];
    }

    /**
     * The contract the row points at, or the reason it cannot be reached.
     *
     * @param  array<string, mixed>  $row
     * @param  array{contracts: array<string, Contract>, contractsByCode: array<string, list<Contract>>, numbers: array<string, StoredInstallment>}  $context
     * @return array{contract: Contract|null, error: string|null}
     */
    private function resolveContract(array $row, int $constructionId, array $context, ?int $restrictToContractId): array
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

        if (($restrictToContractId !== null) && ((int) $contract->getKey() !== $restrictToContractId)) {
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
     * @param  string  $businessToday  today in the business calendar, computed once per reading
     * @return array{date: ?string, cents: ?int, amount: ?SpreadsheetAmount, error: ?string}
     */
    private function resolvePayment(array $row, string $businessToday): array
    {
        $hasDateCell = $this->isFilled($row['payment_date_raw']);
        $hasValueCell = $this->isFilled($row['paid_value_raw']);

        $none = ['date' => null, 'cents' => null, 'amount' => null, 'error' => null];

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
        if ($hasValueCell && ! $hasDateCell && (SpreadsheetAmount::read($row['paid_value_raw'])->cents === 0)) {
            $hasValueCell = false;
        }

        if (! $hasDateCell && ! $hasValueCell) {
            return $none;
        }

        if (! $hasDateCell) {
            return [...$none, 'error' => 'Informe a data do pagamento junto com o valor pago.'];
        }

        if (! $hasValueCell) {
            return [...$none, 'error' => 'Informe o valor pago junto com a data do pagamento.'];
        }

        $paymentDate = $this->parseDate($row['payment_date_raw']);

        if ($paymentDate === null) {
            return [...$none, 'error' => 'Data do pagamento inválida. '.SpreadsheetDate::FORMAT_HINT];
        }

        // A receipt is a fact: it is recorded after it happens, never ahead.
        if ($paymentDate > $businessToday) {
            return [...$none, 'error' => 'A data do pagamento não pode ser futura: um recebimento só é registrado depois de acontecer.'];
        }

        $paidAmount = SpreadsheetAmount::read($row['paid_value_raw']);

        // No ceiling against the expected value on purpose: juros, multa and
        // correção monetária routinely push a receipt above what was due.
        if (($paidAmount->cents === null) || ($paidAmount->cents <= 0)) {
            return [...$none, 'error' => 'Valor pago inválido: informe um valor maior que zero.'];
        }

        return ['date' => $paymentDate, 'cents' => $paidAmount->cents, 'amount' => $paidAmount, 'error' => null];
    }

    /**
     * The discount given on the receipt, from the optional "Desconto" column.
     *
     * A zero or an empty cell means no discount, as a zero in the paid column
     * means nothing received. A discount only exists on the receipt -- the
     * pontualidade or antecipação abated when the installment was paid --, so it
     * needs the payment in the same row, and it never goes above the expected
     * value. Read by {@see SpreadsheetAmount}, with the same warning for text
     * that reads two ways.
     *
     * @param  array<string, mixed>  $row
     * @return array{cents: ?int, amount: ?SpreadsheetAmount, error: ?string}
     */
    private function resolveDiscount(array $row, ?string $paymentDate, int $expectedCents): array
    {
        $none = ['cents' => null, 'amount' => null, 'error' => null];

        if (! $this->isFilled($row['discount_value_raw'] ?? null)) {
            return $none;
        }

        $amount = SpreadsheetAmount::read($row['discount_value_raw']);

        if ($amount->cents === 0) {
            return $none;
        }

        if (($amount->cents === null) || ($amount->cents < 0)) {
            return [...$none, 'error' => 'Desconto inválido: informe um valor maior que zero.'];
        }

        if ($paymentDate === null) {
            return [...$none, 'error' => 'Informe o pagamento junto com o desconto: desconto só existe na baixa da parcela.'];
        }

        if ($amount->cents > $expectedCents) {
            return [...$none, 'error' => 'O desconto não pode superar o valor previsto da parcela.'];
        }

        return ['cents' => $amount->cents, 'amount' => $amount, 'error' => null];
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
            return ['date' => null, 'error' => 'Data de cancelamento inválida. '.SpreadsheetDate::FORMAT_HINT];
        }

        return ['date' => $cancellationDate, 'error' => null];
    }

    /**
     * The development of the name inside the row's emission. Two homonyms in
     * the same emission are reported as ambiguous rather than picked between:
     * choosing one would book the row against a development nobody pointed at.
     *
     * @param  array<string, mixed>  $row
     * @return array{id: int, belongs_to_emission: bool, ambiguous: bool}|null
     */
    private function resolveConstruction(array $row): ?array
    {
        $candidates = $this->constructions[self::normalizeName((string) ($row['construction'] ?? ''))] ?? [];

        if ($candidates === []) {
            return null;
        }

        $emissionId = $this->emissions[self::normalizeName((string) ($row['emission'] ?? ''))] ?? null;

        $inEmission = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $candidate['emission_id'] === $emissionId,
        ));

        if ($inEmission !== []) {
            return ['id' => $inEmission[0]['id'], 'belongs_to_emission' => true, 'ambiguous' => count($inEmission) > 1];
        }

        return ['id' => $candidates[0]['id'], 'belongs_to_emission' => false, 'ambiguous' => false];
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
     * The amount the row carries for display and for writing. The comparison and
     * the plausibility rules run on the cents, never on this.
     */
    private static function decimalAmount(int $cents): float
    {
        return $cents / 100;
    }

    /**
     * The ambiguity warning of an amount written as text, in the shape the row
     * carries.
     *
     * @return list<array{code: string, message: string}>
     */
    private static function ambiguityWarning(SpreadsheetAmount $amount, string $fieldLabel): array
    {
        $message = $amount->warning($fieldLabel);

        return $message === null
            ? []
            : [['code' => ImportRowWarningCode::AmbiguousAmountText->value, 'message' => $message]];
    }

    /**
     * @param  list<array{code: ImportRowWarningCode, message: string}>  $warnings
     * @return list<array{code: string, message: string}>
     */
    private static function scalarWarnings(array $warnings): array
    {
        return array_map(
            static fn (array $warning): array => ['code' => $warning['code']->value, 'message' => $warning['message']],
            $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach (['emission', 'construction', 'contract_code', 'number', 'due_date_raw', 'expected_value_raw', 'payment_date_raw', 'paid_value_raw', 'cancellation_date_raw', 'discount_value_raw'] as $field) {
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
     * for a real date cell -- strictly, through {@see SpreadsheetDate}: a
     * two-digit year, an American reading or a bare serial number would book
     * the installment in the wrong century or the wrong month.
     */
    private function parseDate(mixed $value): ?string
    {
        return SpreadsheetDate::parse($value);
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
