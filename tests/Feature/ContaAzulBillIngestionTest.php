<?php

use App\Enums\AccessPermission;
use App\Enums\BusinessArea;
use App\Exceptions\Funds\ContaAzulBillIngestionException;
use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\ContaAzulToken;
use App\Models\Emission;
use App\Models\User;
use App\Services\Funds\ContaAzulBillIngestionService;
use App\Services\Funds\ContaAzulPaginatedBillAdapter;
use App\Support\BankIdentifierMasker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

/*
 * Phase 3A: account-level Conta Azul bill staging. One ingestion fetches
 * bills once per physical financial account (via its BankAccount) into
 * staging only — no Emission attribution, no operational Expense, no
 * allocation or balance side effects. Runs on SQLite normally and on MySQL
 * through scripts/parity-check.sh; every assertion below is driver
 * agnostic (engine-specific behavior lives in
 * ContaAzulBillIngestionMysqlTest).
 */

pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function contaAzulStagingBill(array $overrides = []): array
{
    return array_merge([
        'id' => 'bill-'.fake()->uuid(),
        'status' => 'PENDING',
        'status_traduzido' => null,
        'total' => 1200.00,
        'pago' => 0,
        'nao_pago' => 1200.00,
        'descricao' => 'DESPESA OPERACIONAL',
        'data_vencimento' => '2026-10-20',
        'data_competencia' => '2026-10-20',
        'data_criacao' => '2026-10-01T10:00:00.000000',
        'data_alteracao' => '2026-10-02T10:00:00.000000',
        'categorias' => [
            ['id' => 'cat-uuid-sistema', 'nome' => 'SISTEMA'],
        ],
        'centros_de_custo' => [],
        'fornecedor' => ['id' => null, 'nome' => null],
    ], $overrides);
}

function contaAzulStagingPage(array $bills, ?int $total = null): array
{
    return [
        'itens' => $bills,
        'itens_totais' => $total ?? count($bills),
    ];
}

function contaAzulStagingAccount(array $overrides = []): BankAccount
{
    return BankAccount::factory()->create(array_merge([
        'conta_azul_account_id' => 'ca-acc-'.fake()->uuid(),
    ], $overrides));
}

function contaAzulStagingService(): ContaAzulBillIngestionService
{
    return app(ContaAzulBillIngestionService::class);
}

function contaAzulStagingIngest(User $actor, BankAccount $account, array $pages, array $options = []): ContaAzulImportBatch
{
    return contaAzulStagingService()->ingest($actor, $account, $pages, array_merge([
        'from' => '2026-01-01',
        'to' => '2026-01-31',
    ], $options));
}

// ── Items 1–2: account with / without identifier ───────────────────────────

it('ingests bills for a bank account holding a Conta Azul identifier', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-uuid-1'])]),
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($batch->bank_account_id)->toBe($account->id)
        ->and($batch->conta_azul_account_id)->toBe($account->conta_azul_account_id)
        ->and($batch->bills_staged)->toBe(1)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-uuid-1')->exists())->toBeTrue();
});

it('refuses ingestion for a bank account without a Conta Azul identifier', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = BankAccount::factory()->create(['conta_azul_account_id' => null]);

    expect(fn () => contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill()]),
    ]))->toThrow(ContaAzulBillIngestionException::class, 'identificador Conta Azul');

    expect(ContaAzulImportBatch::query()->count())->toBe(0)
        ->and(ContaAzulImportedBill::query()->count())->toBe(0);
});

// ── Items 3–5: shared account, single account-level import ──────────────────

it('imports once per physical account when several funds share it', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $application = FundAllocationFixture::application();
    $account = $application->bankAccount()->firstOrFail();
    $account->update(['conta_azul_account_id' => 'ca-acc-shared-funds']);
    [$emission] = FundAllocationFixture::classification();
    FundAllocationFixture::linkedFund($application, $emission);
    FundAllocationFixture::linkedFund($application, $emission);
    FundAllocationFixture::linkedFund($application, $emission);

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-shared-funds'])]),
    ]);

    expect(ContaAzulImportBatch::query()->where('bank_account_id', $account->id)->count())->toBe(1)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-shared-funds')->count())->toBe(1)
        ->and($batch->records_received)->toBe(1);
});

it('imports once per physical account when several emissions share it', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $application = FundAllocationFixture::application();
    $account = $application->bankAccount()->firstOrFail();
    $account->update(['conta_azul_account_id' => 'ca-acc-shared-emissions']);
    FundAllocationFixture::linkedFund($application, Emission::factory()->create());
    FundAllocationFixture::linkedFund($application, Emission::factory()->create());

    expect($account->linkedEmissions())->toHaveCount(2);

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill(['id' => 'bill-shared-e1']),
            contaAzulStagingBill(['id' => 'bill-shared-e2']),
        ]),
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and(ContaAzulImportBatch::query()->where('bank_account_id', $account->id)->count())->toBe(1)
        ->and(ContaAzulImportedBill::query()->whereIn('conta_azul_bill_id', ['bill-shared-e1', 'bill-shared-e2'])->count())->toBe(2)
        ->and(ContaAzulImportedBill::query()->whereNotNull('attributed_emission_id')->count())->toBe(0);
});

// ── Items 6–10: identity, idempotency, updates ──────────────────────────────

it('treats the external bill id as the global canonical identity', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-global-identity', 'total' => 500.00])]),
    ]);

    $second = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-global-identity', 'total' => 500.00])]),
    ], ['from' => '2026-02-01', 'to' => '2026-02-28']);

    $bills = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-global-identity')->get();

    expect($bills)->toHaveCount(1)
        ->and($bills->sole()->last_seen_batch_id)->toBe($second->id)
        ->and($bills->sole()->conta_azul_import_batch_id)->not->toBe($second->id);
});

it('rejects duplicate bills instead of creating a second financial event', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $payload = contaAzulStagingBill(['id' => 'bill-duplicate']);

    contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([$payload])]);
    contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([$payload])], [
        'from' => '2026-02-01', 'to' => '2026-02-28',
    ]);

    expect(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-duplicate')->count())->toBe(1);
});

it('reimports idempotently without changing stored content', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();
    $payload = contaAzulStagingBill(['id' => 'bill-idempotent', 'total' => 8103.55]);

    $first = contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([$payload])]);
    $before = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-idempotent')->sole();

    $second = contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([$payload])], [
        'from' => '2026-02-01', 'to' => '2026-02-28',
    ]);
    $after = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-idempotent')->sole();

    expect(ContaAzulImportedBill::query()->count())->toBe(1)
        ->and($after->payload_fingerprint)->toBe($before->payload_fingerprint)
        ->and($after->import_status)->toBe(ContaAzulImportedBill::IMPORT_STAGED)
        ->and($after->last_seen_batch_id)->toBe($second->id)
        ->and($after->conta_azul_import_batch_id)->toBe($first->id)
        ->and($second->bills_updated)->toBe(0)
        ->and($second->bills_staged)->toBe(0)
        ->and($second->bills_reobserved)->toBe(1);
});

it('detects and traces modified external bill data', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-modified', 'total' => 1000.00, 'pago' => 0])]),
    ]);
    $before = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-modified')->sole();

    $second = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-modified', 'total' => 1000.00, 'pago' => 400.00])]),
    ], ['from' => '2026-02-01', 'to' => '2026-02-28']);

    $after = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-modified')->sole();

    expect(ContaAzulImportedBill::query()->count())->toBe(1)
        ->and($after->payload_fingerprint)->not->toBe($before->payload_fingerprint)
        ->and($after->import_status)->toBe(ContaAzulImportedBill::IMPORT_UPDATED)
        ->and($after->paid_amount)->toBe('400.00')
        ->and($after->last_seen_batch_id)->toBe($second->id)
        ->and($after->conta_azul_import_batch_id)->toBe($before->conta_azul_import_batch_id)
        ->and($second->bills_updated)->toBe(1);
});

it('keeps first-seen parentage when a bill is re-observed through another batch', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $firstAccount = contaAzulStagingAccount(['conta_azul_account_id' => 'ca-acc-first-seen']);
    $secondAccount = contaAzulStagingAccount(['conta_azul_account_id' => 'ca-acc-second-seen']);

    contaAzulStagingIngest($admin, $firstAccount, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-cross-account'])]),
    ]);
    $second = contaAzulStagingIngest($admin, $secondAccount, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-cross-account'])]),
    ]);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-cross-account')->sole();

    // One bill, one row: the second observation does not fork the identity
    // and does not rewrite first-seen provenance.
    expect(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-cross-account')->count())->toBe(1)
        ->and($bill->bank_account_id)->toBe($firstAccount->id)
        ->and($bill->conta_azul_account_id)->toBe('ca-acc-first-seen')
        ->and($bill->last_seen_batch_id)->toBe($second->id);
});

it('stages multiple bills across multiple pages', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill(['id' => 'bill-multi-1']),
            contaAzulStagingBill(['id' => 'bill-multi-2']),
        ], 3),
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-multi-3'])], 3),
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($batch->pages_requested)->toBe(2)
        ->and($batch->pages_completed)->toBe(2)
        ->and($batch->records_received)->toBe(3)
        ->and($batch->bills_staged)->toBe(3)
        ->and(ContaAzulImportedBill::query()->count())->toBe(3);
});

// ── Items 11–15: pagination, failure, retry, batch status ───────────────────

it('completes only when the stream reaches the advertised total', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-complete-1'])], 2),
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-complete-2'])], 2),
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($batch->records_received)->toBe(2)
        ->and($batch->error_code)->toBeNull()
        ->and($batch->started_at)->not->toBeNull()
        ->and($batch->finished_at)->not->toBeNull();
});

it('marks the batch partial when pagination is interrupted early', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-interrupted-1'])], 5),
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_PARTIAL)
        ->and($batch->error_code)->toBe('incomplete_stream')
        ->and($batch->pages_completed)->toBe(1)
        ->and($batch->records_received)->toBe(1)
        ->and($batch->bills_staged)->toBe(1)
        ->and($batch->isRetriable())->toBeTrue()
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-interrupted-1')->exists())->toBeTrue();
});

it('marks the batch partial when the source fails after staging pages', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $stream = (function () {
        yield contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-failure-1'])], 4);

        throw new RuntimeException('HTTP 500 do Conta Azul');
    })();

    $batch = contaAzulStagingService()->ingest($admin, $account, $stream, [
        'from' => '2026-01-01', 'to' => '2026-01-31',
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_PARTIAL)
        ->and($batch->error_code)->toBe('unexpected_error')
        ->and($batch->error_message)->toContain('HTTP 500')
        ->and($batch->pages_completed)->toBe(1)
        ->and($batch->bills_staged)->toBe(1)
        ->and($batch->isRetriable())->toBeTrue();
});

it('marks the batch failed when the source fails before any usable page', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $stream = (function () {
        throw new RuntimeException('connection timeout');
        yield contaAzulStagingPage([]);
    })();

    $batch = contaAzulStagingService()->ingest($admin, $account, $stream, [
        'from' => '2026-01-01', 'to' => '2026-01-31',
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_FAILED)
        ->and($batch->pages_completed)->toBe(0)
        ->and($batch->bills_staged)->toBe(0)
        ->and($batch->isRetriable())->toBeTrue()
        ->and(ContaAzulImportedBill::query()->count())->toBe(0);
});

it('fails a stream that yields no page instead of completing it', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, []);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_FAILED)
        ->and($batch->error_code)->toBe('empty_stream');
});

it('completes an empty result expressed as one empty page', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([], 0)]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($batch->records_received)->toBe(0)
        ->and(ContaAzulImportedBill::query()->count())->toBe(0);
});

it('fails a malformed page instead of silently completing', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [['unexpected' => 'shape']]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_FAILED)
        ->and($batch->error_code)->toBe('source_failed')
        ->and(ContaAzulImportedBill::query()->count())->toBe(0);
});

it('retries a partial batch into a new completing batch without duplicates', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();
    $payload = contaAzulStagingBill(['id' => 'bill-retry']);

    $partial = contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([$payload], 2)]);

    expect($partial->status)->toBe(ContaAzulImportBatch::STATUS_PARTIAL);

    $retry = contaAzulStagingService()->retry($admin, $partial->fresh(), [
        contaAzulStagingPage([$payload], 1),
    ]);

    expect($retry->id)->not->toBe($partial->id)
        ->and($retry->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($retry->retries_batch_id)->toBe($partial->id)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-retry')->count())->toBe(1)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-retry')->sole()->last_seen_batch_id)->toBe($retry->id);
});

it('refuses to retry a completed batch', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $completed = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill()]),
    ]);

    expect(fn () => contaAzulStagingService()->retry($admin, $completed, []))
        ->toThrow(ContaAzulBillIngestionException::class, 'retomadas');
});

it('refuses a second open batch for the same account and range', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    ContaAzulImportBatch::factory()->create([
        'bank_account_id' => $account->id,
        'conta_azul_account_id' => $account->conta_azul_account_id,
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
        'integration_context' => 'conta_azul',
        'status' => ContaAzulImportBatch::STATUS_RUNNING,
    ]);

    expect(fn () => contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill()]),
    ]))->toThrow(ContaAzulBillIngestionException::class, 'em andamento');

    expect(ContaAzulImportBatch::query()->count())->toBe(1);
});

it('allows a new batch for the same account over a different range', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([contaAzulStagingBill()])]);

    $second = contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([], 0)], [
        'from' => '2026-02-01', 'to' => '2026-02-28',
    ]);

    expect($second->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and(ContaAzulImportBatch::query()->count())->toBe(2);
});

it('validates the requested date range', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    expect(fn () => contaAzulStagingService()->ingest($admin, $account, [], [
        'from' => '2026-02-01', 'to' => '2026-01-31',
    ]))->toThrow(ContaAzulBillIngestionException::class, 'Período de importação inválido');

    expect(fn () => contaAzulStagingService()->ingest($admin, $account, [], [
        'from' => 'not-a-date', 'to' => '2026-01-31',
    ]))->toThrow(ContaAzulBillIngestionException::class, 'Período de importação inválido');

    expect(ContaAzulImportBatch::query()->count())->toBe(0);
});

// ── Item 16: exact monetary persistence ────────────────────────────────────

it('persists monetary values with exact decimal precision', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill(['id' => 'bill-money-float', 'total' => 8103.55, 'pago' => 8103.55, 'nao_pago' => 0.0]),
            contaAzulStagingBill(['id' => 'bill-money-string', 'total' => '1234.56', 'pago' => '100.10', 'nao_pago' => '1134.46']),
            contaAzulStagingBill(['id' => 'bill-money-artifact', 'total' => 0.1 + 0.2, 'pago' => 0, 'nao_pago' => 0.1 + 0.2]),
        ]),
    ]);

    $float = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-money-float')->sole();
    $string = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-money-string')->sole();
    $artifact = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-money-artifact')->sole();

    expect($float->total_amount)->toBe('8103.55')
        ->and($float->paid_amount)->toBe('8103.55')
        ->and($float->unpaid_amount)->toBe('0.00')
        ->and($string->total_amount)->toBe('1234.56')
        ->and($string->paid_amount)->toBe('100.10')
        ->and($string->unpaid_amount)->toBe('1134.46')
        ->and($artifact->total_amount)->toBe('0.30')
        ->and($artifact->unpaid_amount)->toBe('0.30');
});

// ── Items 17–18: due dates and payment dates ───────────────────────────────

it('normalizes due dates and never invents one', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill(['id' => 'bill-due-valid', 'data_vencimento' => '2026-06-10']),
            contaAzulStagingBill(['id' => 'bill-due-datetime', 'data_vencimento' => '2026-06-11T00:00:00']),
            contaAzulStagingBill(['id' => 'bill-due-invalid', 'data_vencimento' => 'not-a-date']),
            contaAzulStagingBill(['id' => 'bill-due-missing', 'data_vencimento' => null]),
        ]),
    ]);

    $valid = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-due-valid')->sole();
    $datetime = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-due-datetime')->sole();
    $invalid = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-due-invalid')->sole();
    $missing = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-due-missing')->sole();

    expect($valid->due_date->toDateString())->toBe('2026-06-10')
        ->and($datetime->due_date->toDateString())->toBe('2026-06-11')
        ->and($invalid->due_date)->toBeNull()
        ->and($missing->due_date)->toBeNull();
});

it('resolves payment dates from the listing payload only', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill(['id' => 'bill-pay-direct', 'data_pagamento' => '2026-06-15', 'data_vencimento' => '2026-06-10']),
            contaAzulStagingBill([
                'id' => 'bill-pay-baixas',
                'data_vencimento' => '2026-06-10',
                'baixas' => [
                    ['data_pagamento' => '2026-06-12'],
                    ['data_pagamento' => '2026-06-15'],
                ],
            ]),
            // Mirrors the audited real payload: ACQUITTED with no date in
            // the listing — staging keeps NULL, never the due date.
            contaAzulStagingBill([
                'id' => 'bill-pay-unknown',
                'status' => 'ACQUITTED',
                'status_traduzido' => 'RECEBIDO',
                'total' => 8103.55,
                'pago' => 8103.55,
                'nao_pago' => 0,
                'data_vencimento' => '2026-06-10',
            ]),
        ]),
    ]);

    $direct = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-pay-direct')->sole();
    $baixas = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-pay-baixas')->sole();
    $unknown = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-pay-unknown')->sole();

    expect($direct->payment_date->toDateString())->toBe('2026-06-15')
        ->and($baixas->payment_date->toDateString())->toBe('2026-06-15')
        ->and($unknown->payment_date)->toBeNull();
});

it('matches the legacy payment-date resolution for identical payloads', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();
    $legacy = new SyncContaAzulExpensesJob;

    $payloads = [
        contaAzulStagingBill(['id' => 'bill-parity-1', 'data_pagamento' => '2026-07-12']),
        contaAzulStagingBill(['id' => 'bill-parity-2', 'data_baixa' => '2026-07-13T10:00:00']),
        contaAzulStagingBill(['id' => 'bill-parity-3']),
    ];

    contaAzulStagingIngest($admin, $account, [contaAzulStagingPage($payloads)]);

    foreach ($payloads as $payload) {
        $staged = ContaAzulImportedBill::query()->where('conta_azul_bill_id', $payload['id'])->sole();

        expect($staged->payment_date?->toDateString())->toBe($legacy->resolveBillPaymentDate($payload));
    }
});

// ── Items 19–20: category preservation, unmapped handling ───────────────────

it('preserves raw categories and the mapped category', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill([
                'id' => 'bill-category',
                'categorias' => [
                    ['id' => 'cat-1', 'nome' => 'SISTEMA'],
                    ['id' => 'cat-2', 'nome' => 'UNMAPPED CUSTOM'],
                ],
            ]),
        ]),
    ]);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-category')->sole();

    expect($bill->categories)->toBe([
        ['id' => 'cat-1', 'nome' => 'SISTEMA'],
        ['id' => 'cat-2', 'nome' => 'UNMAPPED CUSTOM'],
    ])->and($bill->mapped_category)->toBe('Sistema')
        ->and($bill->import_status)->toBe(ContaAzulImportedBill::IMPORT_STAGED);
});

it('stages bills with unmapped categories instead of skipping them', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill([
                'id' => 'bill-unmapped',
                'categorias' => [['id' => 'cat-x', 'nome' => 'CATEGORIA INEXISTENTE']],
            ]),
            contaAzulStagingBill(['id' => 'bill-no-categories', 'categorias' => []]),
        ]),
    ]);

    $unmapped = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-unmapped')->sole();
    $none = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-no-categories')->sole();

    expect($batch->bills_staged)->toBe(2)
        ->and($batch->bills_skipped)->toBe(0)
        ->and($unmapped->mapped_category)->toBeNull()
        ->and($unmapped->categories)->toBe([['id' => 'cat-x', 'nome' => 'CATEGORIA INEXISTENTE']])
        ->and($none->mapped_category)->toBeNull();
});

// ── Record-level validation ────────────────────────────────────────────────

it('skips records without identity and flags records with invalid totals', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    $batch = contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            ['total' => 100.00],
            contaAzulStagingBill(['id' => '   ']),
            'not-an-array',
            contaAzulStagingBill(['id' => 'bill-bad-total', 'total' => 'not-money']),
            contaAzulStagingBill(['id' => 'bill-negative-total', 'total' => -50.00]),
            contaAzulStagingBill(['id' => 'bill-good', 'total' => 100.00]),
        ], 6),
    ]);

    $error = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-bad-total')->sole();
    $negative = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-negative-total')->sole();

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and($batch->records_received)->toBe(6)
        ->and($batch->bills_staged)->toBe(1)
        ->and($batch->bills_skipped)->toBe(5)
        ->and($error->import_status)->toBe(ContaAzulImportedBill::IMPORT_ERROR)
        ->and($error->last_import_error)->toContain('Total do título')
        ->and($negative->import_status)->toBe(ContaAzulImportedBill::IMPORT_ERROR)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-good')->exists())->toBeTrue();
});

it('recovers an error bill when valid data arrives later', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-recover', 'total' => 'garbage'])]),
    ]);

    expect(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-recover')->sole()->import_status)
        ->toBe(ContaAzulImportedBill::IMPORT_ERROR);

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([contaAzulStagingBill(['id' => 'bill-recover', 'total' => 250.00])]),
    ], ['from' => '2026-02-01', 'to' => '2026-02-28']);

    $recovered = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-recover')->sole();

    expect($recovered->total_amount)->toBe('250.00')
        ->and($recovered->last_import_error)->toBeNull()
        ->and($recovered->import_status)->not->toBe(ContaAzulImportedBill::IMPORT_ERROR);
});

// ── Items 21–24: attribution preparation, no operational writes ─────────────

it('defaults every staged bill to pending attribution without emission', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $application = FundAllocationFixture::application();
    $account = $application->bankAccount()->firstOrFail();
    $account->update(['conta_azul_account_id' => 'ca-acc-pending']);
    FundAllocationFixture::linkedFund($application, Emission::factory()->create());

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill(['id' => 'bill-pending-1']),
            contaAzulStagingBill(['id' => 'bill-pending-2']),
        ]),
    ]);

    $bills = ContaAzulImportedBill::query()->get();

    expect($bills)->toHaveCount(2)
        ->and($bills->every(fn (ContaAzulImportedBill $bill): bool => $bill->isPendingAttribution()))->toBeTrue()
        ->and($bills->every(fn (ContaAzulImportedBill $bill): bool => $bill->attributed_emission_id === null))->toBeTrue();
});

it('keeps attribution evidence fields from the verified API payload', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount();

    contaAzulStagingIngest($admin, $account, [
        contaAzulStagingPage([
            contaAzulStagingBill([
                'id' => 'bill-evidence',
                'descricao' => 'CONDOMINIO EDIFICIO X',
                'centros_de_custo' => [['id' => 'cc-1', 'nome' => 'CC 01']],
                'fornecedor' => ['id' => 'sup-1', 'nome' => 'Fornecedor Y'],
            ]),
        ]),
    ]);

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-evidence')->sole();

    expect($bill->description)->toBe('CONDOMINIO EDIFICIO X')
        ->and($bill->cost_centers)->toBe([['id' => 'cc-1', 'nome' => 'CC 01']])
        ->and($bill->supplier_name)->toBe('Fornecedor Y')
        ->and($bill->supplier_external_id)->toBe('sup-1');
});

// ── Item 30: authorization ─────────────────────────────────────────────────

it('requires the manage permission to ingest', function () {
    $nobody = User::factory()->create();
    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view']);
    $account = contaAzulStagingAccount();

    foreach ([$nobody, $viewer] as $actor) {
        expect(fn () => contaAzulStagingIngest($actor, $account, [
            contaAzulStagingPage([contaAzulStagingBill()]),
        ]))->toThrow(ContaAzulBillIngestionException::class, 'permissão');
    }

    expect(ContaAzulImportBatch::query()->count())->toBe(0);
});

it('scopes staged-bill reads to the account behind the view permission', function () {
    $viewer = FundAllocationFixture::userWithPermissions(['conta-azul-imports.view']);
    $nobody = User::factory()->create();
    $account = contaAzulStagingAccount();
    $other = contaAzulStagingAccount();
    $service = contaAzulStagingService();

    $bill = ContaAzulImportedBill::factory()->create(['bank_account_id' => $account->id]);
    ContaAzulImportedBill::factory()->create(['bank_account_id' => $other->id]);

    expect($service->viewableBills($viewer, $account)->pluck('id')->all())->toBe([$bill->id])
        ->and(fn () => $service->viewableBills($nobody, $account)->get())->toThrow(ContaAzulBillIngestionException::class, 'permissão')
        ->and(fn () => $service->viewableBatches($nobody, $account)->get())->toThrow(ContaAzulBillIngestionException::class, 'permissão');
});

it('covers the new permissions in exactly one business area', function () {
    foreach (['conta-azul-imports.view', 'conta-azul-imports.manage'] as $permission) {
        $areas = array_filter(
            BusinessArea::cases(),
            fn (BusinessArea $area): bool => $area->coversPermission($permission),
        );

        expect($areas)->toHaveCount(1);
    }
});

it('grants the staging permissions through the seeder roles', function () {
    expect(Role::findByName('admin')->hasPermissionTo('conta-azul-imports.manage'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo('conta-azul-imports.view'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('conta-azul-imports.view'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('conta-azul-imports.manage'))->toBeFalse();
});

it('labels every new permission', function () {
    expect(AccessPermission::labelFor('conta-azul-imports.view'))->not->toBe('conta-azul-imports.view')
        ->and(AccessPermission::labelFor('conta-azul-imports.manage'))->not->toBe('conta-azul-imports.manage');
});

// ── Item 31: sensitive data masking ────────────────────────────────────────

it('masks identifiers in audit trails and encrypts raw payloads', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount(['conta_azul_account_id' => 'sensitive-account-id-abcdef']);

    $payload = contaAzulStagingBill([
        'id' => 'bill-sensitive-1',
        'descricao' => 'STAGING-SECRET-DESCRICAO-XYZ',
    ]);

    $batch = contaAzulStagingIngest($admin, $account, [contaAzulStagingPage([$payload])]);

    $activity = Activity::query()->where('log_name', 'conta_azul_imports')->sole();
    $properties = $activity->properties->toArray();

    expect($properties['conta_azul_account_id'])
        ->toBe(BankIdentifierMasker::maskContaAzulId('sensitive-account-id-abcdef'))
        ->and(json_encode($properties))->not->toContain('sensitive-account-id-abcdef')
        ->and($properties['event'])->toBe('import_completed');

    $bill = ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-sensitive-1')->sole();

    expect($bill->toArray())->not->toHaveKey('source_payload')
        // Value equality, not identity: the encrypted payload is JSON
        // backed, and a JSON round-trip normalizes whole-number floats
        // (1200.0) to ints (1200). Provenance values are preserved.
        ->and($bill->source_payload)->toEqual($payload);

    $storedRaw = DB::table('conta_azul_imported_bills')->where('id', $bill->id)->value('source_payload');

    expect($storedRaw)->not->toContain('STAGING-SECRET-DESCRICAO-XYZ')
        ->and($batch->maskedAccountId())->toBe(BankIdentifierMasker::maskContaAzulId('sensitive-account-id-abcdef'));
});

// ── Isolated pagination adapter (faked HTTP, never live) ───────────────────

it('fetches each page exactly once through the isolated adapter', function () {
    Http::preventStrayRequests();

    ContaAzulToken::query()->create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addDay(),
    ]);

    Http::fake([
        'https://api-v2.contaazul.com/*' => Http::sequence()
            ->push(['itens' => [['id' => 'bill-adapter-1']], 'itens_totais' => 2])
            ->push(['itens' => [['id' => 'bill-adapter-2']], 'itens_totais' => 2]),
    ]);

    $adapter = app(ContaAzulPaginatedBillAdapter::class);
    $pages = iterator_to_array($adapter->fetchPages('ca-acc-adapter', '2026-01-01', '2026-01-31'));

    expect($pages)->toHaveCount(2)
        ->and($pages[0]['itens'])->toBe([['id' => 'bill-adapter-1']])
        ->and($pages[1]['itens'])->toBe([['id' => 'bill-adapter-2']]);

    Http::assertSentCount(2);
});

it('surfaces adapter page failures instead of truncating silently', function () {
    Http::preventStrayRequests();

    ContaAzulToken::query()->create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addDay(),
    ]);

    Http::fake([
        'https://api-v2.contaazul.com/*' => Http::sequence()
            ->push(['itens' => [['id' => 'bill-adapter-ok']], 'itens_totais' => 3])
            ->push('server error', 500),
    ]);

    $adapter = app(ContaAzulPaginatedBillAdapter::class);

    try {
        iterator_to_array($adapter->fetchPages('ca-acc-adapter', '2026-01-01', '2026-01-31'));

        $this->fail('Expected the adapter to throw on HTTP failure.');
    } catch (ContaAzulBillIngestionException $exception) {
        expect($exception->context)->toBe(['page' => 2, 'records_received' => 1]);
    }

    Http::assertSentCount(2);
});

it('pipes adapter pages into staging in one account-level pass', function () {
    Http::preventStrayRequests();

    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulStagingAccount(['conta_azul_account_id' => 'ca-acc-pipe']);

    ContaAzulToken::query()->create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addDay(),
    ]);

    Http::fake([
        'https://api-v2.contaazul.com/*' => Http::response([
            'itens' => [contaAzulStagingBill(['id' => 'bill-piped'])],
            'itens_totais' => 1,
        ]),
    ]);

    $adapter = app(ContaAzulPaginatedBillAdapter::class);

    $batch = contaAzulStagingService()->ingest($admin, $account, $adapter->fetchPages('ca-acc-pipe', '2026-01-01', '2026-01-31'), [
        'from' => '2026-01-01', 'to' => '2026-01-31',
    ]);

    expect($batch->status)->toBe(ContaAzulImportBatch::STATUS_COMPLETED)
        ->and(ContaAzulImportedBill::query()->where('conta_azul_bill_id', 'bill-piped')->exists())->toBeTrue();

    Http::assertSentCount(1);
});
