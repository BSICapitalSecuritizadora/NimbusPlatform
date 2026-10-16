<?php

namespace App\Services\Funds;

use App\Exceptions\Funds\ContaAzulBillIngestionException;
use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\User;
use App\Support\BankIdentifierMasker;
use App\Support\Funds\FundAllocationMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Account-level Conta Azul bill ingestion into Phase 3A staging.
 *
 * One ingestion fetches bills ONCE per physical Conta Azul financial
 * account — resolved through a global BankAccount — no matter how many
 * Funds or Emissions share that account. Fetched bills land in staging
 * only: this service never attributes a bill to an Emission, never creates
 * an operational Expense, and never touches allocations, balances,
 * histories or Guarantee inputs.
 *
 * Retrieval is separated from persistence: ingest() consumes an iterable
 * of API page payloads, so the whole pipeline is testable with fixtures
 * and the live HTTP adapter (ContaAzulPaginatedBillAdapter) plugs in
 * without changing this class. Phase 3A wires no schedule and no job —
 * the legacy SyncContaAzulExpensesJob keeps running untouched.
 *
 * Idempotency: the external bill id is globally unique (same scope the
 * legacy integration already uses). Reimporting a bill only advances its
 * `last_seen_batch_id`; a changed fingerprint updates its content and is
 * traced as `updated`. A unique-violation race between two importers
 * degrades to read-and-update instead of duplicating.
 *
 * Completeness: `completed` is written only when the page stream ended
 * without failure and records_received reached the advertised total.
 * Anything else finalizes as `partial` (some pages staged) or `failed`
 * (nothing usable) — both retriable through retry(), which starts a NEW
 * batch. Incomplete retrieval is never presented as completed.
 *
 * Concurrency: batch creation locks the BankAccount row and refuses a
 * second open batch for the same (account, range, context), so two
 * importers racing the same window serialize and exactly one proceeds.
 *
 * Cutover note (Phase 3C): the legacy job and this pipeline must never
 * import the same account concurrently. The cutover migrates one financial
 * account at a time: pause the legacy schedule for the account (remove its
 * funds' conta_azul_account_id only after the staged bills for the
 * equivalent BankAccount are fully reconciled), then enable the new
 * pipeline for that BankAccount. docs/fase-3a-conta-azul-cutover.md keeps
 * the full strategy.
 */
class ContaAzulBillIngestionService
{
    public const AUDIT_LOG = 'conta_azul_imports';

    public const PERMISSION_VIEW = 'conta-azul-imports.view';

    public const PERMISSION_MANAGE = 'conta-azul-imports.manage';

    public const DEFAULT_INTEGRATION_CONTEXT = 'conta_azul';

    /**
     * Ingest one page stream into staging.
     *
     * Each page must mirror the search-endpoint shape:
     * `['itens' => [...], 'itens_totais' => N]`. The iterable may be an
     * array of fixtures or a throwing generator (that is how source
     * failures surface); a failure finalizes the batch as partial/failed
     * instead of propagating, so staged pages are never lost to an
     * exception.
     *
     * @param  iterable<int, array<string, mixed>>  $pages
     * @param  array{from?: string, to?: string, integration_context?: string, retry_of_batch_id?: int}  $options
     */
    public function ingest(User $actor, BankAccount $account, iterable $pages, array $options = []): ContaAzulImportBatch
    {
        $this->requirePermission($actor, self::PERMISSION_MANAGE, 'executar importações Conta Azul');

        $from = $this->normalizeDate($options['from'] ?? null, 'inicial');
        $to = $this->normalizeDate($options['to'] ?? null, 'final');

        if ($from > $to) {
            throw ContaAzulBillIngestionException::invalidDateRange('a data inicial é posterior à data final');
        }

        $context = $this->normalizeContext($options['integration_context'] ?? null);
        $retryOf = $this->resolveRetryOrigin($options['retry_of_batch_id'] ?? null, (int) $account->getKey(), $from, $to, $context);

        $batch = DB::transaction(function () use ($actor, $account, $from, $to, $context, $retryOf): ContaAzulImportBatch {
            $locked = BankAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $accountId = trim((string) $locked->conta_azul_account_id);

            if ($accountId === '') {
                throw ContaAzulBillIngestionException::missingAccountIdentifier();
            }

            $openExists = ContaAzulImportBatch::query()
                ->where('bank_account_id', $locked->getKey())
                ->whereDate('date_from', $from)
                ->whereDate('date_to', $to)
                ->where('integration_context', $context)
                ->whereIn('status', [ContaAzulImportBatch::STATUS_PENDING, ContaAzulImportBatch::STATUS_RUNNING])
                ->exists();

            if ($openExists) {
                throw ContaAzulBillIngestionException::duplicateImport();
            }

            return ContaAzulImportBatch::query()->create([
                'bank_account_id' => $locked->getKey(),
                'conta_azul_account_id' => $accountId,
                'integration_context' => $context,
                'date_from' => $from,
                'date_to' => $to,
                'status' => ContaAzulImportBatch::STATUS_RUNNING,
                'retries_batch_id' => $retryOf?->getKey(),
                'requested_by_user_id' => $actor->getKey(),
                'started_at' => now(),
                'last_heartbeat_at' => now(),
            ]);
        });

        $this->consumePages($actor, $batch, $pages);

        return $batch->refresh();
    }

    /**
     * Retry a partial/failed batch with a fresh page stream.
     *
     * The retry is a NEW batch linked through `retries_batch_id`; bill
     * identity makes restaging idempotent, so pages the failed attempt
     * already staged are simply re-observed.
     *
     * @param  iterable<int, array<string, mixed>>  $pages
     */
    public function retry(User $actor, ContaAzulImportBatch $batch, iterable $pages): ContaAzulImportBatch
    {
        if (! $batch->isRetriable()) {
            throw ContaAzulBillIngestionException::notRetriable($batch->status);
        }

        return $this->ingest($actor, $batch->bankAccount()->firstOrFail(), $pages, [
            'from' => $batch->date_from->toDateString(),
            'to' => $batch->date_to->toDateString(),
            'integration_context' => $batch->integration_context,
            'retry_of_batch_id' => $batch->getKey(),
        ]);
    }

    /**
     * Future Phase 3B read seam: staged bills of one account.
     *
     * Permission is enforced here, at the service layer — never only in UI
     * visibility. Scoping stays at the BankAccount level: viewing staged
     * bills never implies access to any Emission's operational data.
     *
     * @return Builder<ContaAzulImportedBill>
     */
    public function viewableBills(User $actor, BankAccount $account): Builder
    {
        $this->requirePermission($actor, self::PERMISSION_VIEW, 'visualizar títulos importados');

        return ContaAzulImportedBill::query()
            ->where('bank_account_id', $account->getKey())
            ->orderBy('due_date')
            ->orderBy('id');
    }

    /**
     * Future Phase 3B read seam: import batches of one account.
     *
     * @return Builder<ContaAzulImportBatch>
     */
    public function viewableBatches(User $actor, BankAccount $account): Builder
    {
        $this->requirePermission($actor, self::PERMISSION_VIEW, 'visualizar importações Conta Azul');

        return ContaAzulImportBatch::query()
            ->where('bank_account_id', $account->getKey())
            ->orderByDesc('id');
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $pages
     */
    private function consumePages(User $actor, ContaAzulImportBatch $batch, iterable $pages): void
    {
        $expectedTotal = null;
        $received = 0;
        $pagesCompleted = 0;
        $failure = null;

        try {
            foreach ($pages as $page) {
                $this->incrementRequested($batch);

                if (! is_array($page)) {
                    throw ContaAzulBillIngestionException::invalidPayload('página fora do formato esperado');
                }

                $items = $page['itens'] ?? null;
                $total = $page['itens_totais'] ?? null;

                if (! is_array($items) || ! is_int($total) || $total < 0) {
                    throw ContaAzulBillIngestionException::invalidPayload('página sem itens ou total de itens');
                }

                if ($expectedTotal === null) {
                    $expectedTotal = $total;
                }

                $counts = $this->storePage($actor, $batch, $items);
                $received += $counts['received'];
                $pagesCompleted++;

                $this->addPageCounters($batch, $counts);
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->finalize($actor, $batch, $expectedTotal, $received, $pagesCompleted, $failure);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{received: int, staged: int, updated: int, skipped: int, reobserved: int}
     */
    private function storePage(User $actor, ContaAzulImportBatch $batch, array $items): array
    {
        return DB::transaction(function () use ($actor, $batch, $items): array {
            $counts = ['received' => 0, 'staged' => 0, 'updated' => 0, 'skipped' => 0, 'reobserved' => 0];

            foreach ($items as $record) {
                $counts['received']++;

                if (! is_array($record)) {
                    $counts['skipped']++;

                    continue;
                }

                $billId = isset($record['id']) && is_scalar($record['id']) ? trim((string) $record['id']) : '';

                if ($billId === '') {
                    $counts['skipped']++;

                    continue;
                }

                $outcome = $this->upsertBill($actor, $batch, $billId, $record);
                $counts[$outcome]++;
            }

            return $counts;
        });
    }

    /**
     * Idempotent bill upsert. Returns the counter to bump.
     *
     * The row lock serializes reimports against governed Phase 3B
     * attribution decisions: a correction and a reimport racing the same
     * bill commit in order, never interleaved.
     *
     * @param  array<string, mixed>  $record
     */
    private function upsertBill(User $actor, ContaAzulImportBatch $batch, string $billId, array $record): string
    {
        $normalized = $this->normalizeBill($record);

        $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', $billId)->lockForUpdate()->first();

        if (! $bill instanceof ContaAzulImportedBill) {
            try {
                ContaAzulImportedBill::query()->create(array_merge($normalized, [
                    'conta_azul_import_batch_id' => $batch->getKey(),
                    'last_seen_batch_id' => $batch->getKey(),
                    'content_batch_id' => $batch->getKey(),
                    'bank_account_id' => $batch->bank_account_id,
                    'conta_azul_bill_id' => $billId,
                    'conta_azul_account_id' => $batch->conta_azul_account_id,
                ]));

                return $normalized['import_status'] === ContaAzulImportedBill::IMPORT_ERROR ? 'skipped' : 'staged';
            } catch (QueryException $exception) {
                // Lost a create race against a concurrent importer holding
                // the same bill: fall through to the update path instead of
                // duplicating.
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', $billId)->lockForUpdate()->first();

                if (! $bill instanceof ContaAzulImportedBill) {
                    throw $exception;
                }
            }
        }

        // Update path (existing row, or create race loser): parentage is
        // immutable first-seen provenance and is never rewritten, even when
        // the bill is re-observed through another batch.
        if ($normalized['import_status'] === ContaAzulImportedBill::IMPORT_ERROR) {
            $bill->update([
                'last_seen_batch_id' => $batch->getKey(),
                'import_status' => ContaAzulImportedBill::IMPORT_ERROR,
                'last_import_error' => $normalized['last_import_error'],
            ]);

            return 'skipped';
        }

        if ($bill->payload_fingerprint === $normalized['payload_fingerprint'] && $bill->import_status !== ContaAzulImportedBill::IMPORT_ERROR) {
            $bill->update(['last_seen_batch_id' => $batch->getKey()]);

            return 'reobserved';
        }

        return $this->applyContentUpdate($actor, $batch, $bill, $normalized);
    }

    /**
     * Apply externally changed content to an existing bill.
     *
     * Staging mirrors the source, but an authorized attribution is never
     * silently invalidated: attribution columns and the decision history
     * are untouched here, and attribution-relevant changes on an attributed
     * bill raise renewed review with the previous values preserved in the
     * audit trail. Harmless metadata changes update quietly.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function applyContentUpdate(User $actor, ContaAzulImportBatch $batch, ContaAzulImportedBill $bill, array $normalized): string
    {
        $previousFingerprint = $bill->payload_fingerprint;
        $attributed = $bill->attributed_emission_id !== null;

        $diff = $attributed
            ? ContaAzulExternalChangePolicy::diff($bill, $normalized)
            : ['relevant' => [], 'harmless_only' => true];

        $previousValues = $attributed && ! $diff['harmless_only'] ? [
            'total_amount' => $bill->total_amount,
            'due_date' => $bill->due_date?->toDateString(),
            'payment_date' => $bill->payment_date?->toDateString(),
            'external_status' => $bill->external_status,
            'mapped_category' => $bill->mapped_category,
            'supplier_name' => $bill->supplier_name,
        ] : null;

        $update = array_merge($normalized, [
            'last_seen_batch_id' => $batch->getKey(),
            'content_batch_id' => $batch->getKey(),
        ]);

        if ($previousFingerprint !== $normalized['payload_fingerprint']) {
            $update['previous_payload_fingerprint'] = $previousFingerprint;
        }

        $bill->update($update);

        // A bill that changed externally is traced as updated; a bill
        // recovering from an earlier record error returns to staged.
        if ($bill->wasChanged('payload_fingerprint')) {
            $bill->update(['import_status' => ContaAzulImportedBill::IMPORT_UPDATED]);

            if ($attributed && ! $diff['harmless_only']) {
                $bill->update([
                    'needs_re_review' => true,
                    're_review_reason' => 'Mudança externa em: '.implode(', ', $diff['relevant']),
                ]);

                activity(self::AUDIT_LOG)
                    ->performedOn($bill)
                    ->causedBy($actor)
                    ->withProperties([
                        'event' => 'bill_source_changed',
                        'imported_bill_id' => $bill->getKey(),
                        'conta_azul_bill_id' => $bill->maskedBillId(),
                        'bank_account_id' => $bill->bank_account_id,
                        'attributed_emission_id' => $bill->attributed_emission_id,
                        'attribution_source_fingerprint' => $bill->attribution_source_fingerprint,
                        'previous_payload_fingerprint' => $previousFingerprint,
                        'payload_fingerprint' => $bill->payload_fingerprint,
                        'changed_fields' => $diff['relevant'],
                        'previous_values' => $previousValues,
                        'import_batch_id' => $batch->getKey(),
                    ])
                    ->log('bill_source_changed');
            }

            return 'updated';
        }

        return 'staged';
    }

    /**
     * Normalize one raw bill record into storable fields.
     *
     * Lenient fields (dates, description, statuses, supplier) normalize or
     * fall back to NULL — the raw record is always preserved encrypted, so
     * nothing is lost. Money is strict: an invalid total rejects the
     * financial content (import_status = error) while keeping the identity
     * row, so the bill is visible for reconciliation instead of silently
     * dropped. All money math runs in the string domain; binary floating
     * point never touches a persisted total.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeBill(array $record): array
    {
        $totalCents = $this->toCentsOrNull($record['total'] ?? null);
        $paidCents = $this->toCentsOrNull($record['pago'] ?? null);
        $unpaidCents = $this->toCentsOrNull($record['nao_pago'] ?? null);

        $error = null;

        if ($totalCents === null || $totalCents < 0) {
            $error = 'Total do título inválido ou ausente.';
        }

        $normalized = [
            'description' => $this->normalizeText($record['descricao'] ?? null, 255),
            'total_amount' => $totalCents !== null && $totalCents >= 0 ? FundAllocationMoney::fromCents($totalCents) : '0.00',
            'paid_amount' => $paidCents !== null && $paidCents >= 0 ? FundAllocationMoney::fromCents($paidCents) : null,
            'unpaid_amount' => $unpaidCents !== null && $unpaidCents >= 0 ? FundAllocationMoney::fromCents($unpaidCents) : null,
            'due_date' => $this->normalizeDateOnly($record['data_vencimento'] ?? null),
            'payment_date' => $this->extractListingPaymentDate($record),
            'competence_date' => $this->normalizeDateOnly($record['data_competencia'] ?? null),
            'external_created_at' => $this->normalizeDateTime($record['data_criacao'] ?? null),
            'external_updated_at' => $this->normalizeDateTime($record['data_alteracao'] ?? null),
            'external_status' => $this->normalizeText($record['status'] ?? null, 60),
            'external_status_translated' => $this->normalizeText($record['status_traduzido'] ?? null, 60),
            'categories' => $this->normalizeList($record['categorias'] ?? null),
            'mapped_category' => $this->resolveCategory($record['categorias'] ?? null),
            'cost_centers' => $this->normalizeList($record['centros_de_custo'] ?? null),
            'supplier_name' => $this->normalizeText(is_array($record['fornecedor'] ?? null) ? ($record['fornecedor']['nome'] ?? null) : null, 255),
            'supplier_external_id' => $this->normalizeText(is_array($record['fornecedor'] ?? null) ? ($record['fornecedor']['id'] ?? null) : null, 255),
            'source_payload' => $record,
            'last_import_error' => $error,
        ];

        $normalized['payload_fingerprint'] = $this->fingerprint($normalized);
        $normalized['import_status'] = $error === null
            ? ContaAzulImportedBill::IMPORT_STAGED
            : ContaAzulImportedBill::IMPORT_ERROR;

        return $normalized;
    }

    /**
     * Payment date from the listing payload only.
     *
     * Same field precedence as the legacy job's resolver
     * (data_pagamento → payment_date → data_baixa → baixas[] latest), kept
     * as an independent implementation on purpose: staging must survive the
     * legacy job's eventual Phase 3C removal. Staging never calls the
     * installment endpoint and never falls back to the due date — unknown
     * stays NULL.
     *
     * @param  array<string, mixed>  $record
     */
    private function extractListingPaymentDate(array $record): ?string
    {
        $date = $record['data_pagamento'] ?? $record['payment_date'] ?? $record['data_baixa'] ?? null;
        $normalized = $this->normalizeDateOnly($date);

        if ($normalized !== null) {
            return $normalized;
        }

        $baixas = $record['baixas'] ?? null;

        if (! is_array($baixas) || $baixas === []) {
            return null;
        }

        $valid = [];

        foreach ($baixas as $baixa) {
            if (! is_array($baixa)) {
                continue;
            }

            $candidate = $this->normalizeDateOnly($baixa['data_pagamento'] ?? $baixa['data_baixa'] ?? $baixa['payment_date'] ?? null);

            if ($candidate !== null) {
                $valid[] = $candidate;
            }
        }

        if ($valid === []) {
            return null;
        }

        sort($valid);

        return end($valid);
    }

    /**
     * Category mapping follows the legacy rule (first matching `nome`
     * wins) but never skips: unmapped stays NULL with the raw categories
     * preserved for review.
     */
    private function resolveCategory(mixed $categorias): ?string
    {
        if (! is_array($categorias)) {
            return null;
        }

        $categoryMap = config('conta-azul.category_map', []);

        foreach ($categorias as $categoria) {
            if (! is_array($categoria)) {
                continue;
            }

            $nome = trim((string) ($categoria['nome'] ?? ''));

            if ($nome !== '' && isset($categoryMap[$nome])) {
                return $categoryMap[$nome];
            }
        }

        return null;
    }

    /**
     * sha256 over the canonical normalized content: detects meaningful
     * external changes while ignoring API envelope churn.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function fingerprint(array $normalized): string
    {
        $content = $normalized;
        unset($content['source_payload'], $content['payload_fingerprint'], $content['last_import_error'], $content['import_status']);

        ksort($content);

        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function finalize(
        User $actor,
        ContaAzulImportBatch $batch,
        ?int $expectedTotal,
        int $received,
        int $pagesCompleted,
        ?Throwable $failure,
    ): void {
        $status = ContaAzulImportBatch::STATUS_COMPLETED;
        $errorCode = null;
        $errorMessage = null;

        if ($failure !== null) {
            $status = $pagesCompleted > 0
                ? ContaAzulImportBatch::STATUS_PARTIAL
                : ContaAzulImportBatch::STATUS_FAILED;
            $errorCode = $failure instanceof ContaAzulBillIngestionException ? 'source_failed' : 'unexpected_error';
            $errorMessage = mb_substr($failure->getMessage(), 0, 2000);
        } elseif ($expectedTotal === null) {
            // The stream ended without yielding a single page: no advertised
            // total exists, so completeness is unprovable. Never completed.
            $status = ContaAzulImportBatch::STATUS_FAILED;
            $errorCode = 'empty_stream';
            $errorMessage = 'A fonte não retornou nenhuma página.';
        } elseif ($received < $expectedTotal) {
            $status = ContaAzulImportBatch::STATUS_PARTIAL;
            $errorCode = 'incomplete_stream';
            $errorMessage = "A fonte encerrou o fluxo com {$received} de {$expectedTotal} registros.";
        }

        DB::transaction(function () use ($actor, $batch, $status, $errorCode, $errorMessage): void {
            $batch->update([
                'status' => $status,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'finished_at' => now(),
            ]);

            activity(ContaAzulBillIngestionService::AUDIT_LOG)
                ->performedOn($batch)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'import_'.$status,
                    'bank_account_id' => $batch->bank_account_id,
                    'conta_azul_account_id' => BankIdentifierMasker::maskContaAzulId($batch->conta_azul_account_id),
                    'integration_context' => $batch->integration_context,
                    'date_from' => $batch->date_from->toDateString(),
                    'date_to' => $batch->date_to->toDateString(),
                    'import_batch_id' => $batch->getKey(),
                    'retries_batch_id' => $batch->retries_batch_id,
                    'status' => $status,
                    'pages_requested' => $batch->pages_requested,
                    'pages_completed' => $batch->pages_completed,
                    'records_received' => $batch->records_received,
                    'bills_staged' => $batch->bills_staged,
                    'bills_updated' => $batch->bills_updated,
                    'bills_skipped' => $batch->bills_skipped,
                    'bills_reobserved' => $batch->bills_reobserved,
                    'error_code' => $errorCode,
                ])
                ->log('import_'.$status);
        });
    }

    private function incrementRequested(ContaAzulImportBatch $batch): void
    {
        ContaAzulImportBatch::query()->whereKey($batch->getKey())->increment('pages_requested');
        $batch->pages_requested++;
    }

    /**
     * @param  array{received: int, staged: int, updated: int, skipped: int, reobserved: int}  $counts
     */
    private function addPageCounters(ContaAzulImportBatch $batch, array $counts): void
    {
        ContaAzulImportBatch::query()->whereKey($batch->getKey())->update([
            'pages_completed' => $batch->pages_completed + 1,
            'records_received' => $batch->records_received + $counts['received'],
            'bills_staged' => $batch->bills_staged + $counts['staged'],
            'bills_updated' => $batch->bills_updated + $counts['updated'],
            'bills_skipped' => $batch->bills_skipped + $counts['skipped'],
            'bills_reobserved' => $batch->bills_reobserved + $counts['reobserved'],
            'last_heartbeat_at' => now(),
        ]);

        $batch->refresh();
    }

    private function resolveRetryOrigin(?int $retryOfId, int $bankAccountId, string $from, string $to, string $context): ?ContaAzulImportBatch
    {
        if ($retryOfId === null) {
            return null;
        }

        $origin = ContaAzulImportBatch::query()->whereKey($retryOfId)->first();

        if (! $origin instanceof ContaAzulImportBatch || ! $origin->isRetriable()) {
            throw ContaAzulBillIngestionException::notRetriable($origin->status ?? 'desconhecido');
        }

        if ((int) $origin->bank_account_id !== $bankAccountId
            || $origin->date_from->toDateString() !== $from
            || $origin->date_to->toDateString() !== $to
            || $origin->integration_context !== $context) {
            throw ContaAzulBillIngestionException::invalidPayload('a retomada pertence a outra conta ou período');
        }

        return $origin;
    }

    private function normalizeContext(mixed $value): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return self::DEFAULT_INTEGRATION_CONTEXT;
        }

        if (! is_string($value) || mb_strlen(trim($value)) > 60) {
            throw ContaAzulBillIngestionException::invalidPayload('contexto de integração inválido');
        }

        return trim($value);
    }

    private function normalizeDate(?string $value, string $label): string
    {
        $normalized = $this->normalizeDateOnly($value);

        if ($normalized === null) {
            throw ContaAzulBillIngestionException::invalidDateRange("data {$label} ausente ou inválida (use AAAA-MM-DD)");
        }

        return $normalized;
    }

    private function normalizeDateOnly(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) !== 1) {
            return null;
        }

        $candidate = $matches[1];
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $candidate);

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $candidate) {
            return null;
        }

        return $candidate;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeText(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    /**
     * @return ?array<int, mixed>
     */
    private function normalizeList(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_values($value);
    }

    private function toCentsOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        try {
            return FundAllocationMoney::toCents($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [1062, 19], true)
            || ($exception->getCode() === '23000');
    }

    private function requirePermission(User $actor, string $permission, string $action): void
    {
        if (! $actor->can($permission)) {
            throw ContaAzulBillIngestionException::forbidden($action);
        }
    }
}
