<?php

use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\BankAccount;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\ContaAzulToken;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Services\ContaAzulClient;
use App\Services\Funds\ContaAzulBillIngestionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

/*
 * Phase 3A compatibility: the staging pipeline coexists with the legacy
 * Conta Azul synchronization without touching it. The legacy job keeps its
 * exact behavior, and ingestion writes staging rows only — no Expense, no
 * history, no balance, no allocation and no Guarantee input is created,
 * updated or deleted.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function contaAzulCompatAccount(): BankAccount
{
    return BankAccount::factory()->create([
        'conta_azul_account_id' => 'ca-acc-compat-'.fake()->uuid(),
    ]);
}

function contaAzulCompatBill(array $overrides = []): array
{
    return array_merge([
        'id' => 'bill-compat-'.fake()->uuid(),
        'status' => 'PENDING',
        'total' => 1200.00,
        'pago' => 0,
        'nao_pago' => 1200.00,
        'descricao' => 'DESPESA OPERACIONAL',
        'data_vencimento' => '2026-10-20',
        'categorias' => [['id' => 'cat-uuid-sistema', 'nome' => 'SISTEMA']],
    ], $overrides);
}

/**
 * @return array<string, int>
 */
function contaAzulCompatSnapshot(): array
{
    $tables = [
        'expenses',
        'expense_histories',
        'funds',
        'fund_balance_histories',
        'bank_application_balance_snapshots',
        'fund_allocation_periods',
        'fund_allocation_revisions',
        'fund_allocation_lines',
        'guarantees',
        'guarantee_monthly_positions',
        'extracted_guarantees',
    ];

    $snapshot = [];

    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->count();
    }

    return $snapshot;
}

// ── Item 25: legacy sync behavior unchanged ─────────────────────────────────

it('keeps the legacy upsert behavior byte for byte', function () {
    Http::preventStrayRequests();

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'fund-acc-compat-sistema',
    ]);

    (new SyncContaAzulExpensesJob)->upsertExpense($fund, array_merge(contaAzulCompatBill(), [
        'id' => 'bill-legacy-sistema',
    ]), app(ContaAzulClient::class));

    $expense = Expense::query()->where('emission_id', $emission->id)->sole();

    expect($expense->category)->toBe('Sistema')
        ->and($expense->histories()->where('conta_azul_bill_id', 'bill-legacy-sistema')->exists())->toBeTrue()
        // The legacy path writes operational rows only: staging stays empty.
        ->and(ContaAzulImportBatch::query()->count())->toBe(0)
        ->and(ContaAzulImportedBill::query()->count())->toBe(0);
});

it('keeps the legacy scheduled job flow intact from API to histories', function () {
    ContaAzulToken::query()->create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addDay(),
    ]);

    $emission = Emission::factory()->create();
    Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'fund-acc-compat-job',
    ]);

    Http::fake([
        'https://api-v2.contaazul.com/*' => Http::response([
            'itens' => [contaAzulCompatBill(['id' => 'bill-legacy-job'])],
            'itens_totais' => 1,
        ]),
    ]);

    (new SyncContaAzulExpensesJob)->handle(app(ContaAzulClient::class));

    expect(ExpenseHistory::query()->where('conta_azul_bill_id', 'bill-legacy-job')->exists())->toBeTrue()
        ->and(ContaAzulImportBatch::query()->count())->toBe(0)
        ->and(ContaAzulImportedBill::query()->count())->toBe(0);
});

it('keeps the legacy fund account identifier and its unique constraint', function () {
    Fund::factory()->create(['conta_azul_account_id' => 'fund-acc-unique-kept']);

    expect(fn () => Fund::factory()->create(['conta_azul_account_id' => 'fund-acc-unique-kept']))
        ->toThrow(QueryException::class);
});

// ── Items 23–24, 26–29: ingestion has no operational side effects ───────────

it('leaves every existing financial row untouched during staging', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulCompatAccount();

    $fund = Fund::factory()->create([
        'balance' => '77777.77',
        'minimum_balance' => '1000.00',
        'balance_updated_at' => '2026-09-01 10:00:00',
        'conta_azul_account_id' => 'fund-acc-compat-intact',
    ]);
    $history = FundBalanceHistory::query()->create([
        'fund_id' => $fund->id,
        'date' => '2026-08-31',
        'balance' => '70000.00',
    ]);
    $expense = Expense::factory()->create([
        'emission_id' => $fund->emission_id,
        'category' => 'Sistema',
        'amount' => '1200.00',
    ]);
    $expenseHistory = $expense->histories()->create([
        'conta_azul_bill_id' => 'bill-legacy-intact',
        'amount' => '1200.00',
        'paid_amount' => null,
        'due_date' => '2026-10-20',
        'payment_date' => null,
        'status' => 'pending',
    ]);
    $snapshot = BankApplicationBalanceSnapshot::factory()->create();

    $before = contaAzulCompatSnapshot();

    app(ContaAzulBillIngestionService::class)->ingest($admin, $account, [
        [
            'itens' => [
                contaAzulCompatBill(['id' => 'bill-staged-no-side-effects']),
                // Same external id the legacy history already carries: the
                // staged row must not rewrite the operational history.
                contaAzulCompatBill(['id' => 'bill-legacy-intact', 'total' => 9999.99]),
            ],
            'itens_totais' => 2,
        ],
    ], ['from' => '2026-01-01', 'to' => '2026-01-31']);

    expect(contaAzulCompatSnapshot())->toBe($before)
        ->and($fund->refresh()->balance)->toBe('77777.77')
        ->and($fund->minimum_balance)->toBe('1000.00')
        ->and($fund->balance_updated_at->toDateTimeString())->toBe('2026-09-01 10:00:00')
        ->and($history->refresh()->balance)->toBe('70000.00')
        ->and($expense->refresh()->amount)->toBe('1200.00')
        ->and($expenseHistory->refresh()->amount)->toBe('1200.00')
        ->and($expenseHistory->paid_amount)->toBeNull()
        ->and($expenseHistory->due_date->toDateString())->toBe('2026-10-20')
        ->and($expenseHistory->status)->toBe('pending')
        ->and($snapshot->refresh()->exists())->toBeTrue();
});

it('never stages into operational tables even across retries and failures', function () {
    $admin = FundAllocationFixture::userWithRole('admin');
    $account = contaAzulCompatAccount();

    $before = contaAzulCompatSnapshot();

    $service = app(ContaAzulBillIngestionService::class);

    $partial = $service->ingest($admin, $account, [
        ['itens' => [contaAzulCompatBill(['id' => 'bill-compat-retry'])], 'itens_totais' => 3],
    ], ['from' => '2026-01-01', 'to' => '2026-01-31']);

    $service->retry($admin, $partial->fresh(), [
        ['itens' => [contaAzulCompatBill(['id' => 'bill-compat-retry'])], 'itens_totais' => 1],
    ]);

    $failing = (function () {
        throw new RuntimeException('boom');
        yield ['itens' => [], 'itens_totais' => 0];
    })();

    $service->ingest($admin, $account, $failing, ['from' => '2026-02-01', 'to' => '2026-02-28']);

    expect(contaAzulCompatSnapshot())->toBe($before)
        ->and(Expense::query()->count())->toBe(0)
        ->and(ExpenseHistory::query()->count())->toBe(0);
});
