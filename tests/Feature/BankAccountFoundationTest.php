<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankApplication;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundApplication as FundClassification;
use App\Models\FundBalanceHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates a bank account linked to its bank', function () {
    $bank = Bank::factory()->create();

    $account = BankAccount::factory()->create([
        'bank_id' => $bank->id,
        'agency_number' => '1234',
        'agency_digit' => '5',
        'account_number' => '12345',
        'account_digit' => '6',
    ]);

    expect($account->bank->is($bank))->toBeTrue()
        ->and($bank->bankAccounts)->toHaveCount(1)
        ->and($account->holder_context)->toBeNull()
        ->and($account->conta_azul_account_id)->toBeNull();
});

it('creates a bank application linked to its account with active status by default', function () {
    $application = BankApplication::factory()->create([
        'codigo_produto' => '00123',
        'nome_produto' => 'CDB DI',
    ]);

    expect($application->bankAccount)->toBeInstanceOf(BankAccount::class)
        ->and($application->status)->toBe(BankApplication::STATUS_ACTIVE)
        ->and($application->bankAccount->bankApplications)->toHaveCount(1);
});

it('rejects duplicate physical accounts within the same bank', function () {
    $bank = Bank::factory()->create();

    BankAccount::factory()->create([
        'bank_id' => $bank->id,
        'agency_number' => '1234',
        'agency_digit' => '5',
        'account_number' => '12345',
        'account_digit' => '6',
    ]);

    expect(fn () => BankAccount::factory()->create([
        'bank_id' => $bank->id,
        'agency_number' => '1234',
        'agency_digit' => '5',
        'account_number' => '12345',
        'account_digit' => '6',
    ]))->toThrow(QueryException::class);
});

it('rejects duplicate product codes within the same account', function () {
    $account = BankAccount::factory()->create();

    BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00123',
    ]);

    expect(fn () => BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00123',
    ]))->toThrow(QueryException::class);
});

it('allows the same product code in different accounts', function () {
    $firstAccount = BankAccount::factory()->create();
    $secondAccount = BankAccount::factory()->create();

    BankApplication::factory()->create([
        'bank_account_id' => $firstAccount->id,
        'codigo_produto' => '00123',
    ]);

    BankApplication::factory()->create([
        'bank_account_id' => $secondAccount->id,
        'codigo_produto' => '00123',
    ]);

    expect(BankApplication::query()->where('codigo_produto', '00123')->count())->toBe(2);
});

it('treats identical account numbers in different banks as different accounts', function () {
    $firstBank = Bank::factory()->create();
    $secondBank = Bank::factory()->create();

    $attributes = [
        'agency_number' => '1234',
        'agency_digit' => '5',
        'account_number' => '12345',
        'account_digit' => '6',
    ];

    BankAccount::factory()->create([...$attributes, 'bank_id' => $firstBank->id]);
    BankAccount::factory()->create([...$attributes, 'bank_id' => $secondBank->id]);

    expect(BankAccount::query()->count())->toBe(2);
});

it('treats identical account numbers in different agencies as different accounts', function () {
    $bank = Bank::factory()->create();

    BankAccount::factory()->create([
        'bank_id' => $bank->id,
        'agency_number' => '1234',
        'agency_digit' => '5',
        'account_number' => '12345',
        'account_digit' => '6',
    ]);

    BankAccount::factory()->create([
        'bank_id' => $bank->id,
        'agency_number' => '4321',
        'agency_digit' => '5',
        'account_number' => '12345',
        'account_digit' => '6',
    ]);

    expect(BankAccount::query()->count())->toBe(2);
});

it('preserves leading zeros on agency, account and product codes', function () {
    $account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => '4',
        'account_number' => '0012345',
        'account_digit' => '6',
    ]);

    $application = BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '00042',
    ]);

    expect($account->refresh()->agency_number)->toBe('0123')
        ->and($account->account_number)->toBe('0012345')
        ->and($application->refresh()->codigo_produto)->toBe('00042');
});

it('formats canonical parts for display and fills display columns when blank', function () {
    $account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => '4',
        'account_number' => '0012345',
        'account_digit' => '6',
        'display_agency' => null,
        'display_account' => null,
    ]);

    expect($account->refresh()->display_agency)->toBe('0123-4')
        ->and($account->display_account)->toBe('0012345-6')
        ->and($account->formattedAgency())->toBe('0123-4')
        ->and($account->formattedAccount())->toBe('0012345-6')
        ->and($account->canonicalKey())->toBe("{$account->bank_id}:0123:4:0012345:6");
});

it('rejects malformed canonical identifiers instead of guessing', function () {
    expect(fn () => BankAccount::factory()->create(['agency_number' => '12A4']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => BankAccount::factory()->create(['account_number' => '123']))
        ->toThrow(InvalidArgumentException::class);

    expect(BankAccount::query()->count())->toBe(0);

    $account = BankAccount::factory()->create();

    expect(fn () => BankApplication::factory()->create([
        'bank_account_id' => $account->id,
        'codigo_produto' => '   ',
    ]))->toThrow(InvalidArgumentException::class);

    expect(BankApplication::query()->count())->toBe(0)
        ->and(BankAccount::query()->count())->toBe(1);
});

it('links one account to multiple applications', function () {
    $account = BankAccount::factory()->create();

    BankApplication::factory()->count(3)->create(['bank_account_id' => $account->id]);

    expect($account->bankApplications()->count())->toBe(3);
});

it('links one application to multiple funds', function () {
    $application = BankApplication::factory()->create();

    Fund::factory()->count(2)->create([
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    expect($application->funds()->count())->toBe(2);
});

it('links one application to funds from multiple emissions and reports sharing', function () {
    $application = BankApplication::factory()->create();
    $firstEmission = Emission::factory()->create();
    $secondEmission = Emission::factory()->create();

    Fund::factory()->create([
        'emission_id' => $firstEmission->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    Fund::factory()->create([
        'emission_id' => $secondEmission->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    expect($application->funds()->count())->toBe(2)
        ->and($application->isShared())->toBeTrue();
});

it('links multiple funds in the same emission to one application without sharing', function () {
    $application = BankApplication::factory()->create();
    $emission = Emission::factory()->create();

    Fund::factory()->count(2)->create([
        'emission_id' => $emission->id,
        'bank_account_id' => $application->bank_account_id,
        'bank_application_id' => $application->id,
    ]);

    expect($application->funds()->count())->toBe(2)
        ->and($application->isShared())->toBeFalse();
});

it('keeps new relationships null on existing style fund records', function () {
    $fund = Fund::factory()->create();

    expect($fund->bank_account_id)->toBeNull()
        ->and($fund->bank_application_id)->toBeNull()
        ->and($fund->bankAccount)->toBeNull()
        ->and($fund->bankApplication)->toBeNull();
});

it('links a fund directly to an account without an application', function () {
    $account = BankAccount::factory()->create();

    $fund = Fund::factory()->create([
        'bank_account_id' => $account->id,
        'bank_application_id' => null,
    ]);

    expect($fund->bankAccount->is($account))->toBeTrue()
        ->and($account->funds()->count())->toBe(1);
});

it('rejects a fund pairing an application with another account', function () {
    $account = BankAccount::factory()->create();
    $otherAccount = BankAccount::factory()->create();
    $otherApplication = BankApplication::factory()->create(['bank_account_id' => $otherAccount->id]);

    expect(fn () => Fund::factory()->create([
        'bank_account_id' => $account->id,
        'bank_application_id' => $otherApplication->id,
    ]))->toThrow(InvalidArgumentException::class);

    $fund = Fund::factory()->create([
        'bank_account_id' => $otherAccount->id,
        'bank_application_id' => $otherApplication->id,
    ]);

    expect(fn () => $fund->update(['bank_account_id' => $account->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects inconsistent account and application pairs at the database level', function () {
    $account = BankAccount::factory()->create();
    $otherAccount = BankAccount::factory()->create();
    $otherApplication = BankApplication::factory()->create(['bank_account_id' => $otherAccount->id]);
    $fund = Fund::factory()->create();

    expect(fn () => DB::table('funds')->where('id', $fund->id)->update([
        'bank_account_id' => $account->id,
        'bank_application_id' => $otherApplication->id,
    ]))->toThrow(QueryException::class);
});

it('protects referenced accounts and applications against deletion', function () {
    $accountWithApplication = BankAccount::factory()->create();
    BankApplication::factory()->create(['bank_account_id' => $accountWithApplication->id]);

    $accountWithFund = BankAccount::factory()->create();
    Fund::factory()->create(['bank_account_id' => $accountWithFund->id]);

    $applicationWithFund = BankApplication::factory()->create();
    Fund::factory()->create([
        'bank_account_id' => $applicationWithFund->bank_account_id,
        'bank_application_id' => $applicationWithFund->id,
    ]);

    expect(fn () => $accountWithApplication->delete())->toThrow(QueryException::class)
        ->and(fn () => $accountWithFund->delete())->toThrow(QueryException::class)
        ->and(fn () => $applicationWithFund->delete())->toThrow(QueryException::class);

    $orphanAccount = BankAccount::factory()->create();
    $orphanApplication = BankApplication::factory()->create();

    expect($orphanApplication->delete())->toBeTrue()
        ->and($orphanAccount->delete())->toBeTrue();
});

it('keeps the existing fund uniqueness behavior unchanged', function () {
    $emission = Emission::factory()->create();
    $classification = FundClassification::factory()->create();

    Fund::factory()->create([
        'emission_id' => $emission->id,
        'fund_application_id' => $classification->id,
        'account' => '98765-4',
    ]);

    expect(fn () => Fund::factory()->create([
        'emission_id' => $emission->id,
        'fund_application_id' => $classification->id,
        'account' => '98765-4',
    ]))->toThrow(QueryException::class);

    Fund::factory()->create([
        'emission_id' => $emission->id,
        'fund_application_id' => FundClassification::factory()->create()->id,
        'account' => '98765-4',
    ]);

    expect(Fund::query()->count())->toBe(2);
});

it('keeps existing fund history relationships working', function () {
    $fund = Fund::factory()->create();

    $history = FundBalanceHistory::factory()->create([
        'fund_id' => $fund->id,
        'date' => '2026-04-30',
        'balance' => 120000.00,
    ]);

    expect($fund->balanceHistories()->count())->toBe(1)
        ->and($fund->balanceHistories()->first()->is($history))->toBeTrue()
        ->and($history->fund->is($fund))->toBeTrue()
        ->and($fund->bank_account_id)->toBeNull();
});

it('keeps existing fund balance calculations unchanged', function () {
    $belowMinimum = Fund::factory()->create([
        'balance' => 49000.00,
        'minimum_balance' => 50000.00,
        'balance_updated_at' => now(),
    ]);

    $aboveMinimum = Fund::factory()->create([
        'balance' => 60000.00,
        'minimum_balance' => 50000.00,
        'balance_updated_at' => now(),
    ]);

    $stale = Fund::factory()->create([
        'balance' => 60000.00,
        'balance_updated_at' => now()->subMonthNoOverflow()->startOfMonth(),
    ]);

    expect($belowMinimum->isBalanceBelowMinimum())->toBeTrue()
        ->and($aboveMinimum->isBalanceBelowMinimum())->toBeFalse()
        ->and($belowMinimum->balance)->toBe('49000.00')
        ->and($belowMinimum->requiresMonthlyBalanceUpdate(now()))->toBeFalse()
        ->and($stale->requiresMonthlyBalanceUpdate(now()))->toBeTrue();
});

it('exposes the new tables and columns while preserving every legacy fund column', function () {
    expect(Schema::hasTable('bank_accounts'))->toBeTrue()
        ->and(Schema::hasTable('bank_applications'))->toBeTrue();

    foreach (['emission_id', 'fund_type_id', 'fund_name_id', 'fund_application_id', 'trade_name', 'bank_id', 'agency', 'account', 'balance', 'minimum_balance', 'balance_updated_at', 'conta_azul_account_id', 'bank_account_id', 'bank_application_id'] as $column) {
        expect(Schema::hasColumn('funds', $column))->toBeTrue();
    }

    $accountIndexes = Schema::getIndexes('bank_accounts');

    $hasCanonicalUnique = collect($accountIndexes)->contains(fn (array $index): bool => ($index['unique'] ?? false)
        && ($index['columns'] ?? []) === ['bank_id', 'agency_number', 'agency_digit', 'account_number', 'account_digit']);

    expect($hasCanonicalUnique)->toBeTrue();

    $applicationIndexes = Schema::getIndexes('bank_applications');

    $hasProductUnique = collect($applicationIndexes)->contains(fn (array $index): bool => ($index['unique'] ?? false)
        && ($index['columns'] ?? []) === ['bank_account_id', 'codigo_produto']);

    expect($hasProductUnique)->toBeTrue();
});
