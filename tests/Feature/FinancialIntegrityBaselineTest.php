<?php

use App\Models\BankApplication;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Models\FundBalanceHistory;
use App\Models\Guarantee;
use App\Models\GuaranteeEvent;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\GuaranteeValuation;
use App\Services\Funds\FinancialIntegrityBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Funds\BankDiagnosticFixture;

uses(RefreshDatabase::class);

function baselineConnection(): string
{
    return (string) config('database.default');
}

function seedFinancialScenario(): Fund
{
    $fund = BankDiagnosticFixture::fund([
        'agency' => '1234-5',
        'account' => '12345-6',
        'balance' => '120000.00',
        'minimum_balance' => '50000.00',
        'conta_azul_account_id' => 'ca-acct-001',
    ]);

    FundBalanceHistory::factory()->create([
        'fund_id' => $fund->id,
        'date' => '2026-04-30',
        'balance' => '120000.00',
    ]);

    $guarantee = Guarantee::factory()->create([
        'emission_id' => $fund->emission_id,
        'fund_id' => $fund->id,
    ]);

    GuaranteeEvent::factory()->create(['guarantee_id' => $guarantee->id]);
    GuaranteeValuation::factory()->create(['guarantee_id' => $guarantee->id]);
    GuaranteeMonthlyPosition::factory()->create([
        'guarantee_id' => $guarantee->id,
        'emission_id' => $fund->emission_id,
    ]);

    $expense = Expense::factory()->create(['emission_id' => $fund->emission_id]);

    ExpenseHistory::create([
        'expense_id' => $expense->id,
        'amount' => '1500.00',
        'paid_amount' => '1500.00',
        'due_date' => '2026-05-10',
        'payment_date' => '2026-05-09',
        'status' => 'paid',
        'conta_azul_bill_id' => 'bill-001',
    ]);

    return $fund;
}

it('captures every covered table with a row count and a digest', function () {
    seedFinancialScenario();

    $snapshot = app(FinancialIntegrityBaseline::class)->snapshot(baselineConnection());

    expect($snapshot->tables)->toHaveKeys(FinancialIntegrityBaseline::TABLES);

    foreach (FinancialIntegrityBaseline::TABLES as $table) {
        expect($snapshot->tables[$table]['columns'])->not->toBe([])
            ->and($snapshot->tables[$table]['row_count'])->toBeGreaterThan(0)
            ->and($snapshot->tables[$table]['digest'])->toMatch('/^[0-9a-f]{64}$/')
            ->and($snapshot->tables[$table]['rows'])->toHaveCount($snapshot->tables[$table]['row_count']);
    }

    expect($snapshot->tables['funds']['row_count'])->toBe(1)
        ->and($snapshot->tables['funds']['columns'])->toContain('conta_azul_account_id', 'bank_account_id', 'balance');
});

it('produces deterministic snapshots across repeated runs', function () {
    seedFinancialScenario();

    $baseline = app(FinancialIntegrityBaseline::class);

    $first = $baseline->snapshot(baselineConnection())->toArray(withRowDigests: true);
    $second = $baseline->snapshot(baselineConnection())->toArray(withRowDigests: true);

    unset($first['generated_at'], $second['generated_at']);

    expect($second)->toBe($first);
});

it('matches two snapshots of an untouched database', function () {
    seedFinancialScenario();

    $baseline = app(FinancialIntegrityBaseline::class);

    $comparison = $baseline->compare(
        $baseline->snapshot(baselineConnection()),
        $baseline->snapshot(baselineConnection())
    );

    expect($comparison->matches)->toBeTrue();

    foreach ($comparison->tables as $table) {
        expect($table['matches'])->toBeTrue()
            ->and($table['added_ids'])->toBe([])
            ->and($table['removed_ids'])->toBe([])
            ->and($table['changed_ids'])->toBe([]);
    }
});

it('pinpoints a changed balance down to the row', function () {
    $fund = seedFinancialScenario();

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    DB::table('funds')->where('id', $fund->id)->update(['balance' => '130000.00']);

    $comparison = $baseline->compare($before, $baseline->snapshot(baselineConnection()));

    expect($comparison->matches)->toBeFalse()
        ->and($comparison->tables['funds']['matches'])->toBeFalse()
        ->and($comparison->tables['funds']['changed_ids'])->toBe([$fund->id])
        ->and($comparison->tables['expenses']['matches'])->toBeTrue();
});

it('detects a changed conta azul identifier', function () {
    $fund = seedFinancialScenario();

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    DB::table('funds')->where('id', $fund->id)->update(['conta_azul_account_id' => 'ca-acct-002']);

    $comparison = $baseline->compare($before, $baseline->snapshot(baselineConnection()));

    expect($comparison->matches)->toBeFalse()
        ->and($comparison->tables['funds']['changed_ids'])->toBe([$fund->id]);
});

it('detects added and removed history rows', function () {
    $fund = seedFinancialScenario();

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    $added = FundBalanceHistory::factory()->create(['fund_id' => $fund->id]);

    $comparison = $baseline->compare($before, $baseline->snapshot(baselineConnection()));

    expect($comparison->tables['fund_balance_histories']['added_ids'])->toBe([$added->id]);

    $middle = $baseline->snapshot(baselineConnection());

    $added->delete();

    $comparison = $baseline->compare($middle, $baseline->snapshot(baselineConnection()));

    expect($comparison->tables['fund_balance_histories']['removed_ids'])->toBe([$added->id]);
});

it('rejects unknown baseline tables', function () {
    expect(fn () => app(FinancialIntegrityBaseline::class)->snapshot(baselineConnection(), ['funds', 'nope']))
        ->toThrow(InvalidArgumentException::class);
});

it('snapshots a subset of tables when asked', function () {
    seedFinancialScenario();

    $snapshot = app(FinancialIntegrityBaseline::class)->snapshot(baselineConnection(), ['funds']);

    expect($snapshot->tables)->toHaveKeys(['funds'])
        ->and($snapshot->tables)->toHaveCount(1);
});

it('issues only read queries while snapshotting', function () {
    seedFinancialScenario();

    $verbs = [];

    DB::listen(function ($query) use (&$verbs): void {
        $verbs[] = strtolower((string) strtok(ltrim($query->sql), " \t\n\r\0\x0B("));
    });

    app(FinancialIntegrityBaseline::class)->snapshot(baselineConnection());

    expect($verbs)->not->toBe([])
        ->and($verbs)->each->toBeIn(['select', 'show', 'describe', 'desc', 'explain', 'pragma']);
});

function linkFundToApplication(int $fundId, int $accountId, int $applicationId): void
{
    DB::table('funds')->where('id', $fundId)->update([
        'bank_account_id' => $accountId,
        'bank_application_id' => $applicationId,
    ]);
}

function backfillTargetsFor(Fund $fund): array
{
    $account = BankDiagnosticFixture::matchingAccount($fund);

    $application = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
    ]);

    return [$account->id, $application->id];
}

it('classifies an FK-only backfill as allowed when declared', function () {
    $fund = seedFinancialScenario();

    [$accountId, $applicationId] = backfillTargetsFor($fund);

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    linkFundToApplication($fund->id, $accountId, $applicationId);

    $comparison = $baseline->compare(
        $before,
        $baseline->snapshot(baselineConnection()),
        FinancialIntegrityBaseline::ALLOWABLE_BACKFILL_CHANGES
    );

    expect($comparison->tables['funds']['matches'])->toBeFalse()
        ->and($comparison->tables['funds']['changed_ids'])->toBe([])
        ->and($comparison->tables['funds']['allowed_only_ids'])->toBe([$fund->id])
        ->and($comparison->tables['funds']['allowed_columns'])->toBe(['bank_account_id', 'bank_application_id', 'bank_application_identity']);

    foreach ($comparison->tables as $table) {
        expect($table['added_ids'])->toBe([])
            ->and($table['removed_ids'])->toBe([])
            ->and($table['changed_ids'])->toBe([]);
    }
});

it('keeps FK changes strict when no allowance is declared', function () {
    $fund = seedFinancialScenario();

    [$accountId, $applicationId] = backfillTargetsFor($fund);

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    linkFundToApplication($fund->id, $accountId, $applicationId);

    $comparison = $baseline->compare($before, $baseline->snapshot(baselineConnection()));

    expect($comparison->matches)->toBeFalse()
        ->and($comparison->tables['funds']['changed_ids'])->toBe([$fund->id])
        ->and($comparison->tables['funds']['allowed_only_ids'])->toBe([])
        ->and($comparison->tables['funds']['allowed_columns'])->toBe([]);
});

it('flags a mixed FK and balance change as a real change', function () {
    $fund = seedFinancialScenario();

    [$accountId, $applicationId] = backfillTargetsFor($fund);

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    linkFundToApplication($fund->id, $accountId, $applicationId);
    DB::table('funds')->where('id', $fund->id)->update(['balance' => '130000.00']);

    $comparison = $baseline->compare(
        $before,
        $baseline->snapshot(baselineConnection()),
        FinancialIntegrityBaseline::ALLOWABLE_BACKFILL_CHANGES
    );

    expect($comparison->tables['funds']['changed_ids'])->toBe([$fund->id])
        ->and($comparison->tables['funds']['allowed_only_ids'])->toBe([]);
});

it('flags an emission change riding along a backfill as a real change', function () {
    $fund = seedFinancialScenario();

    [$accountId, $applicationId] = backfillTargetsFor($fund);

    $otherEmissionId = BankDiagnosticFixture::fund()->emission_id;

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    linkFundToApplication($fund->id, $accountId, $applicationId);
    DB::table('funds')->where('id', $fund->id)->update(['emission_id' => $otherEmissionId]);

    $comparison = $baseline->compare(
        $before,
        $baseline->snapshot(baselineConnection()),
        FinancialIntegrityBaseline::ALLOWABLE_BACKFILL_CHANGES
    );

    expect($comparison->tables['funds']['changed_ids'])->toBe([$fund->id])
        ->and($comparison->tables['funds']['allowed_only_ids'])->toBe([]);
});

it('flags a conta azul change riding along a backfill as a real change', function () {
    $fund = seedFinancialScenario();

    [$accountId, $applicationId] = backfillTargetsFor($fund);

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());

    linkFundToApplication($fund->id, $accountId, $applicationId);
    DB::table('funds')->where('id', $fund->id)->update(['conta_azul_account_id' => 'ca-acct-002']);

    $comparison = $baseline->compare(
        $before,
        $baseline->snapshot(baselineConnection()),
        FinancialIntegrityBaseline::ALLOWABLE_BACKFILL_CHANGES
    );

    expect($comparison->tables['funds']['changed_ids'])->toBe([$fund->id])
        ->and($comparison->tables['funds']['allowed_only_ids'])->toBe([]);
});

it('refuses to allow financial columns outside the backfill targets', function () {
    seedFinancialScenario();

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(baselineConnection());
    $after = $baseline->snapshot(baselineConnection());

    $attempts = [
        ['funds' => ['balance']],
        ['funds' => ['emission_id']],
        ['funds' => ['conta_azul_account_id']],
        ['funds' => ['agency']],
        ['funds' => ['bank_id']],
        ['expenses' => ['bank_account_id']],
        ['nope' => ['bank_account_id']],
    ];

    foreach ($attempts as $allowed) {
        expect(fn () => $baseline->compare($before, $after, $allowed))
            ->toThrow(InvalidArgumentException::class);
    }
});
