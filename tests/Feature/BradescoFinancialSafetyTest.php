<?php

use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\FundAllocationLine;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Models\FundBalanceHistory;
use App\Models\Guarantee;
use App\Models\GuaranteeValuation;
use App\Services\Bradesco\BradescoApplicationMatcher;
use App\Services\Bradesco\BradescoBalanceInterpreter;
use App\Services\Bradesco\BradescoStatementInspector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function bradescoSafetyScenario(): array
{
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application, overrides: [
        'balance' => '12345.67',
        'minimum_balance' => '10000.00',
        'conta_azul_account_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    ]);
    FundBalanceHistory::factory()->create(['fund_id' => $fund->id, 'balance' => '12000.00']);

    $snapshot = FundAllocationFixture::service()->recordReferenceBalance(
        $maker, $application, '2026-09', '100000.00'
    );
    $draft = FundAllocationFixture::service()->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '60000.00'],
    ]);
    FundAllocationFixture::service()->approve(
        $checker, FundAllocationFixture::service()->submitForReview($maker, $draft), 'safety-1'
    );

    $guarantee = Guarantee::factory()->create();
    GuaranteeValuation::factory()->create(['guarantee_id' => $guarantee->id]);
    $expense = Expense::factory()->create();
    ExpenseHistory::query()->create([
        'expense_id' => $expense->id,
        'amount' => '500.00',
        'due_date' => '2026-09-10',
        'status' => 'pending',
    ]);
    $batch = ContaAzulImportBatch::factory()->create();
    ContaAzulImportedBill::factory()->create(['conta_azul_import_batch_id' => $batch->id]);

    $account = $application->bankAccount()->firstOrFail();
    $account->update([
        'agency_number' => '3995',
        'agency_digit' => '1',
        'account_number' => '75557',
        'account_digit' => '5',
    ]);

    return [$account->fresh(), $application->fresh(), $fund->fresh()];
}

function runBradescoDiagnostics($account): void
{
    $interpreter = app(BradescoBalanceInterpreter::class);

    $payload = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Bradesco/saldo_ok.json')),
        true
    );
    $report = $interpreter->interpret($payload, $account, 2, 't', 't');
    app(BradescoApplicationMatcher::class)->match($account, $report);

    $statement = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Bradesco/extrato_ok.json')),
        true
    );
    app(BradescoStatementInspector::class)->inspect($statement, $account, 't', 't');

    test()->artisan('bradesco:balances:diagnose', [
        '--bank-account' => $account->id,
        '--fixture' => base_path('tests/Fixtures/Bradesco/saldo_ok.json'),
    ])->assertSuccessful();
}

it('runs on the isolated sqlite test database', function () {
    expect(DB::getDriverName())->toBe('sqlite')
        ->and(DB::getConfig('database'))->toBe(':memory:');
});

it('leaves fund balances and histories untouched', function () {
    [$account] = bradescoSafetyScenario();

    runBradescoDiagnostics($account);

    $fund = Fund::query()->firstOrFail();

    expect($fund->balance)->toBe('12345.67')
        ->and($fund->minimum_balance)->toBe('10000.00')
        ->and(FundBalanceHistory::query()->count())->toBe(1)
        ->and(FundBalanceHistory::query()->value('balance'))->toBe('12000.00');
});

it('creates no snapshots, revisions or allocation lines', function () {
    [$account] = bradescoSafetyScenario();

    runBradescoDiagnostics($account);

    expect(BankApplicationBalanceSnapshot::query()->count())->toBe(1)
        ->and(FundAllocationPeriod::query()->count())->toBe(1)
        ->and(FundAllocationRevision::query()->count())->toBe(1)
        ->and(FundAllocationRevision::query()->value('status'))->toBe(FundAllocationRevision::STATUS_APPROVED)
        ->and(FundAllocationLine::query()->count())->toBe(1);
});

it('modifies no guarantee or expense records', function () {
    [$account] = bradescoSafetyScenario();

    $digest = fn (string $model): array => $model::query()->pluck('updated_at', 'id')
        ->map(fn ($date): string => (string) $date)->all();

    $guaranteeDigest = $digest(Guarantee::class);
    $valuationDigest = $digest(GuaranteeValuation::class);
    $expenseDigest = $digest(Expense::class);
    $historyDigest = $digest(ExpenseHistory::class);

    runBradescoDiagnostics($account);

    expect($digest(Guarantee::class))->toBe($guaranteeDigest)
        ->and($digest(GuaranteeValuation::class))->toBe($valuationDigest)
        ->and($digest(Expense::class))->toBe($expenseDigest)
        ->and($digest(ExpenseHistory::class))->toBe($historyDigest);
});

it('preserves Conta Azul operations, config and staging rows', function () {
    [$account] = bradescoSafetyScenario();

    runBradescoDiagnostics($account);

    expect(Fund::query()->value('conta_azul_account_id'))->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
        ->and(ContaAzulImportBatch::query()->count())->toBe(1)
        ->and(ContaAzulImportedBill::query()->count())->toBe(1)
        ->and(config('conta-azul.base_url'))->toBe('https://api-v2.contaazul.com')
        ->and(app(SyncContaAzulExpensesJob::class))->toBeInstanceOf(SyncContaAzulExpensesJob::class);
});

it('schedules no Bradesco synchronization', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->description)
        ->filter(fn (string $description) => stripos($description, 'bradesco') !== false);

    expect($events)->toBeEmpty();
});
