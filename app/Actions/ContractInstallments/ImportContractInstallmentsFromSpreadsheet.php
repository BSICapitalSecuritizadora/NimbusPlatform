<?php

namespace App\Actions\ContractInstallments;

use App\Exceptions\ImportConferenceOutdatedException;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\ImportRun;
use App\Support\Imports\ImportRunDraft;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Confirms an installment spreadsheet: one streamed pass that classifies and
 * writes, inside one transaction.
 *
 * The confirmation reads the file again rather than applying a plan kept from
 * the conference: a plan would write the position as it was then. The pass that
 * writes folds the same summary the conference showed, and the digests have to
 * match -- otherwise everything is rolled back and the new conference goes back
 * to the operator ({@see ImportConferenceOutdatedException}). A monthly position
 * is reconciled completely or not at all.
 *
 * Inside the transaction, in this order:
 *
 *   1. the {@see ImportRun} is opened, so its id stamps what is created;
 *   2. new installments are inserted in bulk, 500 at a time, with
 *      `import_run_id` and the derived `number_normalized` written explicitly --
 *      a bulk insert bypasses model events -- and the discount of the receipt,
 *      when the file carries one;
 *   3. updates go through the model, one at a time: they are the small set of a
 *      monthly file, and saving through the model is what lets
 *      {@see ContractInstallment} log which field held which value before the
 *      import changed it;
 *   4. the open installments the file left out are cancelled, only when that was
 *      explicitly asked for -- through the model as well, with the date chosen,
 *      each one read again under a row lock, so one paid or cancelled in the
 *      meantime is left alone;
 *   5. the counters are closed on the run.
 *
 * Rows that came back unchanged are not touched at all: no write, no
 * `updated_at`, no activity entry.
 */
class ImportContractInstallmentsFromSpreadsheet
{
    private const CHUNK_SIZE = 500;

    /**
     * How the database names the violation of "one number per contract among
     * the live installments": MySQL quotes the index, SQLite the columns.
     *
     * @var list<string>
     */
    private const NUMBER_UNIQUE_MARKERS = [
        'contract_installments_number_unique',
        'contract_installments.active_contract_id',
    ];

    public function __construct(
        private readonly AnalyzeContractInstallmentSpreadsheet $analyzer,
    ) {}

    /**
     * @param  string|null  $expectedDigest  the digest of the conference the operator saw.
     *                                       Null only when the conference could not be read
     *                                       back (the cache was unavailable): the import then
     *                                       goes ahead without the guard, as it did before it
     *                                       existed, and the file still has to be importable.
     *
     * @throws ImportConferenceOutdatedException when the position on record moved since the conference
     * @throws RuntimeException when the file, read again, cannot be imported
     */
    public function handle(
        string $path,
        ?int $scope,
        ImportRunDraft $draft,
        ?string $expectedDigest,
        ?AbsentInstallmentCancellation $cancellation = null,
    ): ContractInstallmentImportResult {
        try {
            return DB::transaction(fn (): ContractInstallmentImportResult => $this->write($path, $scope, $draft, $expectedDigest, $cancellation));
        } catch (QueryException $exception) {
            if (! self::isNumberUniqueViolation($exception)) {
                throw $exception;
            }

            /**
             * Another write created one of the same installments after this pass
             * classified it. Everything was rolled back; the operator gets the
             * conference of the position as it is now.
             */
            throw ImportConferenceOutdatedException::uniqueIndex($this->analyzer->handle($path, $scope)->toArray());
        }
    }

    private function write(
        string $path,
        ?int $scope,
        ImportRunDraft $draft,
        ?string $expectedDigest,
        ?AbsentInstallmentCancellation $cancellation,
    ): ContractInstallmentImportResult {
        $run = $draft->open();
        $runId = (int) $run->getKey();

        $toCreate = [];
        $toUpdate = [];
        $created = 0;
        $updated = 0;

        $reading = $this->analyzer->open($path, $scope);

        $summary = ContractInstallmentSpreadsheetAnalysis::fold($reading, function (array $row) use (&$toCreate, &$toUpdate, &$created, &$updated, $runId): void {
            if (! $row['outcome']->writesToDatabase()) {
                return;
            }

            if ($row['outcome']->isUpdate()) {
                $toUpdate[] = ['installment_id' => $row['installment_id'], 'attributes' => $row['comparison']->attributes()];

                if (count($toUpdate) >= self::CHUNK_SIZE) {
                    $updated += $this->update($toUpdate);
                    $toUpdate = [];
                }

                return;
            }

            $toCreate[] = $row;

            if (count($toCreate) >= self::CHUNK_SIZE) {
                $created += $this->create($toCreate, $runId);
                $toCreate = [];
            }
        });

        $created += $this->create($toCreate, $runId);
        $updated += $this->update($toUpdate);

        if (($expectedDigest !== null) && ! hash_equals($expectedDigest, $summary->digest())) {
            throw ImportConferenceOutdatedException::positionChanged($summary->toArray());
        }

        if (! $summary->canImport()) {
            throw new RuntimeException('A planilha possui inconsistências e não pode ser importada.');
        }

        $openIds = $reading->absences()->openIds();

        ['cancelled' => $cancelled, 'construction_ids' => $cancelledConstructionIds] = $cancellation === null
            ? ['cancelled' => 0, 'construction_ids' => []]
            : $this->cancelAbsentOpen($openIds, $cancellation);

        $unchanged = $summary->unchangedCount() + $summary->informativeDivergenceCount();

        $run->forceFill([
            'records_analyzed' => $summary->totalLines(),
            'records_created' => $created,
            'records_updated' => $updated,
            // An informative divergence writes nothing either: for the run it is
            // a row left as it was.
            'records_unchanged' => $unchanged,
            'records_critical' => $summary->criticalUpdateCount(),
            'records_warned' => $summary->warningCount(),
            'records_absent' => $summary->absentCount(),
            'records_cancelled' => $cancelled,
            'absence_cancellation_date' => $cancelled > 0 ? $cancellation?->date : null,
            'absence_cancellation_reason' => $cancelled > 0 ? $cancellation?->reason : null,
        ])->save();

        return new ContractInstallmentImportResult(
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            cancelled: $cancelled,
            contracts: $summary->writtenContractCount(),
            warned: $summary->warningCount(),
            absent: $summary->absentCount(),
            digest: $summary->digest(),
            run: $run,
            warningsByCode: $summary->warningsByCode(),
            cancellationSkipped: $cancellation === null ? 0 : count($openIds) - $cancelled,
            cancelledConstructionIds: $cancelledConstructionIds,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function create(array $rows, int $runId): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = now();

        ContractInstallment::query()->insert(array_map(fn (array $row): array => [
            'contract_id' => $row['contract_id'],
            'number' => $row['number'],
            'number_normalized' => $row['number_normalized'],
            'due_date' => $row['due_date'],
            'expected_value' => $row['expected_value'],
            'payment_date' => $row['payment_date'],
            'paid_value' => $row['paid_value'],
            'discount_value' => $row['discount_value'] ?? null,
            'cancellation_date' => $row['cancellation_date'],
            'import_run_id' => $runId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));

        return count($rows);
    }

    /**
     * One query per chunk, then saves through the model so the activity log sees
     * each change.
     *
     * @param  list<array{installment_id: int, attributes: array<string, mixed>}>  $rows
     */
    private function update(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $installments = ContractInstallment::query()
            ->whereKey(array_column($rows, 'installment_id'))
            ->get()
            ->keyBy('id');

        $updated = 0;

        foreach ($rows as $row) {
            $installment = $installments->get($row['installment_id']);

            if ($installment === null) {
                continue;
            }

            $installment->fill($row['attributes']);

            // Belt and braces: the comparison already found a difference, so
            // this only guards against a row whose value the casts consider
            // identical after all.
            if (! $installment->isDirty()) {
                continue;
            }

            $installment->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * Cancels, through the model, the open installments the file left out.
     *
     * Only the ones still open: an installment that received a payment or was
     * cancelled in the meantime is left as it is. Each save writes the activity
     * of the installment inside the batch of the run, which is how the
     * cancellation shows up among the changes of the import.
     *
     * "Ainda em aberto" é decidido numa leitura travada (`FOR UPDATE`), em
     * ordem de id. A leitura comum, no REPEATABLE READ do MySQL, via a foto do
     * começo do Confirmar -- que relê o arquivo inteiro e leva dezenas de
     * segundos numa carteira grande --, e o pagamento ou o cancelamento
     * gravado por outra tela nesse meio-tempo não aparecia: a parcela
     * terminava paga e cancelada, e a data manual do cancelamento era
     * sobrescrita. A leitura travada vê a última versão confirmada e reconfere
     * as duas condições; a edição ainda em curso faz o Confirmar esperar. As
     * que mudaram ficam fora e são contadas, para o aviso ao operador.
     *
     * @param  list<int>  $installmentIds
     * @return array{cancelled: int, construction_ids: list<int>}
     */
    private function cancelAbsentOpen(array $installmentIds, AbsentInstallmentCancellation $cancellation): array
    {
        $cancelled = 0;
        $contractIds = [];

        foreach (array_chunk($installmentIds, self::CHUNK_SIZE) as $chunk) {
            ContractInstallment::query()
                ->whereKey($chunk)
                ->whereNull('payment_date')
                ->whereNull('cancellation_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (ContractInstallment $installment) use ($cancellation, &$cancelled, &$contractIds): void {
                    $installment->cancellation_date = $cancellation->date;
                    $installment->save();

                    $contractIds[(int) $installment->contract_id] = true;
                    $cancelled++;
                });
        }

        /**
         * As obras das parcelas canceladas, para o aviso de competência já
         * registrada no Quadro de Vendas depois de gravar.
         */
        $constructionIds = $contractIds === []
            ? []
            : Contract::withTrashed()
                ->whereKey(array_keys($contractIds))
                ->distinct()
                ->orderBy('construction_id')
                ->pluck('construction_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

        return ['cancelled' => $cancelled, 'construction_ids' => $constructionIds];
    }

    /**
     * Matched on what names the index in the message -- the SQLSTATE alone
     * (23000) covers every other constraint too.
     */
    private static function isNumberUniqueViolation(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();

        $message = $exception->getMessage()
            .($previous instanceof Throwable ? ' '.$previous->getMessage() : '');

        foreach (self::NUMBER_UNIQUE_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }
}
