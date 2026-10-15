<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundApplication;
use App\Models\FundName;
use App\Models\FundType;
use App\Services\Funds\FinancialIntegrityBaseline;
use App\Services\Funds\FundBankAccountDiagnosticService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Funds\BankDiagnosticFixture;

uses(RefreshDatabase::class);

function fundDiagnosticConnection(): string
{
    return (string) config('database.default');
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function fundDiagnosticJson(array $options = []): array
{
    $exitCode = Artisan::call('funds:bank-accounts:diagnose', [
        '--connection' => fundDiagnosticConnection(),
        '--confirm-connection' => fundDiagnosticConnection(),
        '--source' => 'synthetic',
        '--format' => 'json',
        ...$options,
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * @param  array<string, mixed>  $options
 */
function fundDiagnosticRawOutput(array $options = []): string
{
    $exitCode = Artisan::call('funds:bank-accounts:diagnose', [
        '--connection' => fundDiagnosticConnection(),
        '--confirm-connection' => fundDiagnosticConnection(),
        '--source' => 'synthetic',
        ...$options,
    ]);

    expect($exitCode)->toBe(0);

    return Artisan::output();
}

it('refuses to run without an explicit connection', function () {
    $this->artisan('funds:bank-accounts:diagnose', [
        '--source' => 'synthetic',
    ])
        ->expectsOutputToContain('explícita')
        ->assertFailed();
});

it('refuses an unknown connection and a mismatched confirmation', function () {
    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => 'does-not-exist',
        '--confirm-connection' => 'does-not-exist',
        '--source' => 'synthetic',
    ])->assertFailed();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => fundDiagnosticConnection(),
        '--confirm-connection' => 'another-connection',
        '--source' => 'synthetic',
    ])
        ->expectsOutputToContain('--confirm-connection')
        ->assertFailed();
});

it('requires a declared data source and an authorization note for authorized sources', function () {
    $connection = fundDiagnosticConnection();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => $connection,
        '--confirm-connection' => $connection,
    ])->assertFailed();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => $connection,
        '--confirm-connection' => $connection,
        '--source' => 'authorized',
    ])
        ->expectsOutputToContain('--source-note')
        ->assertFailed();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => $connection,
        '--confirm-connection' => $connection,
        '--source' => 'authorized',
        '--source-note' => 'CFO approval 2026-10-01, staging replica',
    ])->assertSuccessful();
});

it('refuses a file artifact with the human-readable table format', function () {
    $connection = fundDiagnosticConnection();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => $connection,
        '--confirm-connection' => $connection,
        '--source' => 'synthetic',
        '--output' => '/tmp/should-not-exist.json',
    ])
        ->expectsOutputToContain('--output exige')
        ->assertFailed();
});

it('exposes no execute option in phase 1B.1', function () {
    $command = Artisan::all()['funds:bank-accounts:diagnose'];

    expect($command->getDefinition()->hasOption('execute'))->toBeFalse();
});

it('classifies a valid fund as pending its unresolved application', function () {
    $fund = BankDiagnosticFixture::fund([
        'agency' => '1234-5',
        'account' => '12345-6',
        'conta_azul_account_id' => null,
    ]);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['total_funds'])->toBe(1)
        ->and($payload['totals']['parseable_funds'])->toBe(1)
        ->and($payload['totals']['distinct_strict_accounts'])->toBe(1)
        ->and($payload['preview'])->toHaveCount(1)
        ->and($payload['preview'][0]['fund_id'])->toBe($fund->id)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_APPLICATION_PENDING)
        ->and($payload['preview'][0]['reasons'])->toBe([])
        ->and($payload['preview'][0]['application_resolved'])->toBeFalse()
        ->and($payload['preview'][0]['eligible_for_auto_mapping'])->toBeTrue()
        ->and($payload['preview'][0]['must_skip'])->toBeFalse();
});

it('blocks funds with malformed identifiers', function (string $agency, string $account, string $reason) {
    BankDiagnosticFixture::fund(['agency' => $agency, 'account' => $account]);

    $payload = fundDiagnosticJson(['--conflicts' => true]);

    expect($payload['totals']['malformed_identifier_funds'])->toBe(1)
        ->and($payload['totals']['unmappable_funds'])->toBe(1)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_BLOCKED_INVALID_ACCOUNT)
        ->and($payload['preview'][0]['reasons'])->toBe([$reason])
        ->and($payload['conflicts']['blocked'][0]['reason'])->toBe($reason);
})->with([
    'agency without separator' => ['12345', '12345-6', FundBankAccountDiagnosticService::REASON_MALFORMED_AGENCY],
    'agency with letters' => ['12A4-5', '12345-6', FundBankAccountDiagnosticService::REASON_MALFORMED_AGENCY],
    'agency with two separators' => ['1.234-5', '12345-6', FundBankAccountDiagnosticService::REASON_MALFORMED_AGENCY],
    'account without separator' => ['1234-5', '123456', FundBankAccountDiagnosticService::REASON_MALFORMED_ACCOUNT],
    'account too short' => ['1234-5', '123-4', FundBankAccountDiagnosticService::REASON_MALFORMED_ACCOUNT],
    'account with bad digit' => ['1234-5', '12345-Z', FundBankAccountDiagnosticService::REASON_MALFORMED_ACCOUNT],
]);

it('blocks funds with missing identifiers', function (?string $agency, string $account, string $reason) {
    BankDiagnosticFixture::fund(['agency' => $agency, 'account' => $account]);

    $payload = fundDiagnosticJson();

    expect($payload['totals']['missing_identifier_funds'])->toBe(1)
        ->and($payload['totals']['unmappable_funds'])->toBe(1)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_BLOCKED_INVALID_ACCOUNT)
        ->and($payload['preview'][0]['reasons'])->toBe([$reason]);
})->with([
    'null agency' => [null, '12345-6', FundBankAccountDiagnosticService::REASON_MISSING_AGENCY],
    'blank agency' => ['   ', '12345-6', FundBankAccountDiagnosticService::REASON_MISSING_AGENCY],
    'blank account' => ['1234-5', '', FundBankAccountDiagnosticService::REASON_MISSING_ACCOUNT],
]);

it('groups accounts written with different separators into one strict account', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234/5', 'account' => '12345.6']);

    $payload = fundDiagnosticJson(['--conflicts' => true]);

    expect($payload['totals']['distinct_strict_accounts'])->toBe(1)
        ->and($payload['totals']['multi_fund_accounts'])->toBe(1)
        ->and($payload['totals']['spelling_variant_groups'])->toBe(1)
        ->and($payload['preview'][0]['canonical_group'])->toBe($payload['preview'][1]['canonical_group']);
});

it('preserves leading zeros in the strict canonical grouping', function () {
    $bank = Bank::factory()->create();

    $padded = BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '0123-4', 'account' => '0012345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '0123-4', 'account' => '0012345-6']);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['parseable_funds'])->toBe(2)
        ->and($payload['totals']['distinct_strict_accounts'])->toBe(1)
        ->and($payload['preview'][0]['masked_account'])->toBe('*****45-6')
        ->and($payload['preview'][0]['masked_agency'])->toBe('**23-4')
        ->and($payload['preview'][0]['fund_id'])->toBe($padded->id);
});

it('reports zero-padding lookalikes as candidates without merging them', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '0012345-6']);

    $payload = fundDiagnosticJson(['--conflicts' => true, '--preview' => true]);

    expect($payload['totals']['distinct_strict_accounts'])->toBe(2)
        ->and($payload['totals']['zero_padding_candidate_groups'])->toBe(2)
        ->and($payload['totals']['zero_padding_collapsible_groups'])->toBe(1)
        ->and($payload['totals']['review_required_funds'])->toBe(2)
        ->and($payload['conflicts']['numeric_candidates'])->toHaveCount(1)
        ->and($payload['conflicts']['numeric_candidates'][0]['strict_groups'])->toHaveCount(2)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_MAPPING_CONFLICT)
        ->and($payload['preview'][0]['reasons'])->toBe([FundBankAccountDiagnosticService::REASON_NUMERIC_MERGE_CANDIDATE])
        ->and($payload['preview'][1]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_MAPPING_CONFLICT);
});

it('never suggests a numeric merge across different banks', function () {
    $firstBank = Bank::factory()->create();
    $secondBank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $firstBank->id, 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $secondBank->id, 'agency' => '1234-5', 'account' => '0012345-6']);

    $payload = fundDiagnosticJson();

    expect($payload['totals']['distinct_strict_accounts'])->toBe(2)
        ->and($payload['totals']['zero_padding_candidate_groups'])->toBe(0)
        ->and($payload['totals']['status_application_pending'])->toBe(2);
});

it('keeps identical account numbers in different banks as different accounts', function () {
    $firstBank = Bank::factory()->create();
    $secondBank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $firstBank->id, 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $secondBank->id, 'agency' => '1234-5', 'account' => '12345-6']);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['distinct_strict_accounts'])->toBe(2)
        ->and($payload['preview'][0]['canonical_group'])->not->toBe($payload['preview'][1]['canonical_group'])
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_APPLICATION_PENDING)
        ->and($payload['preview'][1]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_APPLICATION_PENDING);
});

it('keeps identical account numbers in different agencies as different accounts', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '4321-5', 'account' => '12345-6']);

    $payload = fundDiagnosticJson();

    expect($payload['totals']['distinct_strict_accounts'])->toBe(2)
        ->and($payload['totals']['zero_padding_candidate_groups'])->toBe(0);
});

it('groups one physical account shared by several funds', function () {
    $bank = Bank::factory()->create();
    $emission = Emission::factory()->create();

    $first = BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'emission_id' => $emission->id, 'agency' => '1234-5', 'account' => '12345-6']);
    $second = BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'emission_id' => $emission->id, 'agency' => '1234-5', 'account' => '12345-6']);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['distinct_strict_accounts'])->toBe(1)
        ->and($payload['totals']['multi_fund_accounts'])->toBe(1)
        ->and($payload['totals']['multi_emission_accounts'])->toBe(0)
        ->and($payload['preview'][0]['fund_id'])->toBe($first->id)
        ->and($payload['preview'][1]['fund_id'])->toBe($second->id)
        ->and($payload['preview'][0]['group_fund_count'])->toBe(2)
        ->and($payload['preview'][0]['shared_by_funds'])->toBeTrue()
        ->and($payload['preview'][0]['shared_by_emissions'])->toBeFalse();
});

it('flags one physical account shared by several emissions', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'emission_id' => Emission::factory(), 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'emission_id' => Emission::factory(), 'agency' => '1234-5', 'account' => '12345-6']);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['multi_emission_accounts'])->toBe(1)
        ->and($payload['preview'][0]['group_emission_count'])->toBe(2)
        ->and($payload['preview'][0]['shared_by_emissions'])->toBeTrue()
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_APPLICATION_PENDING);
});

it('accepts one conta azul id per account group', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6', 'conta_azul_account_id' => 'ca-acct-001']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => ' 1234-5 ', 'account' => '12345-6', 'conta_azul_account_id' => null]);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['conta_azul_conflict_groups'])->toBe(0)
        ->and($payload['preview'][0]['conta_azul_status'])->toBe(FundBankAccountDiagnosticService::CONTA_SINGLE)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_APPLICATION_PENDING);
});

it('conflicts on multiple conta azul ids without picking a winner', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6', 'conta_azul_account_id' => 'ca-acct-001']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6', 'conta_azul_account_id' => 'ca-acct-002']);

    $payload = fundDiagnosticJson(['--conflicts' => true, '--preview' => true]);

    expect($payload['totals']['conta_azul_conflict_groups'])->toBe(1)
        ->and($payload['totals']['review_required_funds'])->toBe(2)
        ->and($payload['conflicts']['conta_azul'])->toHaveCount(1)
        ->and($payload['conflicts']['conta_azul'][0]['masked_conta_azul_ids'])->toHaveCount(2)
        ->and($payload['preview'][0]['conta_azul_status'])->toBe(FundBankAccountDiagnosticService::CONTA_CONFLICT)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_MAPPING_CONFLICT)
        ->and($payload['preview'][0]['reasons'])->toBe([FundBankAccountDiagnosticService::REASON_CONTA_AZUL_CONFLICT]);
});

it('treats null conta azul identifiers as no mapping', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6', 'conta_azul_account_id' => null]);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6', 'conta_azul_account_id' => null]);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['conta_azul_conflict_groups'])->toBe(0)
        ->and($payload['preview'][0]['conta_azul_status'])->toBe(FundBankAccountDiagnosticService::CONTA_NONE)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_APPLICATION_PENDING);
});

it('identifies already-linked funds without touching their references', function () {
    $fund = BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12345-6']);
    $account = BankDiagnosticFixture::matchingAccount($fund);

    $fund->update(['bank_account_id' => $account->id]);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['already_linked_funds'])->toBe(1)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_ACCOUNT_IDENTIFIED)
        ->and($payload['preview'][0]['linked_bank_account_id'])->toBe($account->id)
        ->and($payload['preview'][0]['eligible_for_auto_mapping'])->toBeTrue()
        ->and($fund->refresh()->bank_account_id)->toBe($account->id)
        ->and(BankAccount::query()->count())->toBe(1);
});

it('matches existing bank accounts without modifying them', function () {
    $fund = BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12345-6']);

    $account = BankDiagnosticFixture::matchingAccount($fund);

    $payload = fundDiagnosticJson(['--preview' => true]);

    expect($payload['totals']['existing_match_funds'])->toBe(1)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_ACCOUNT_IDENTIFIED)
        ->and($payload['preview'][0]['existing_bank_account_id'])->toBe($account->id)
        ->and($payload['preview'][0]['linked_bank_account_id'])->toBeNull()
        ->and($fund->refresh()->bank_account_id)->toBeNull()
        ->and(BankAccount::query()->count())->toBe(1);
});

it('flags an existing account whose conta azul id disagrees with the legacy group', function () {
    $fund = BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12345-6', 'conta_azul_account_id' => 'ca-acct-001']);

    BankDiagnosticFixture::matchingAccount($fund, ['conta_azul_account_id' => 'ca-acct-999']);

    $payload = fundDiagnosticJson(['--conflicts' => true, '--preview' => true]);

    expect($payload['totals']['existing_mismatch_groups'])->toBe(1)
        ->and($payload['conflicts']['existing_mismatches'])->toHaveCount(1)
        ->and($payload['preview'][0]['status'])->toBe(FundBankAccountDiagnosticService::STATUS_MAPPING_CONFLICT)
        ->and($payload['preview'][0]['reasons'])->toBe([FundBankAccountDiagnosticService::REASON_EXISTING_ACCOUNT_MISMATCH]);
});

it('processes large datasets in small chunks', function () {
    $bank = Bank::factory()->create();
    $emission = Emission::factory()->create();
    $type = FundType::factory()->create();
    $name = FundName::factory()->create(['fund_type_id' => $type->id]);
    $application = FundApplication::factory()->create();

    Fund::factory()->count(120)->sequence(fn ($sequence) => [
        'emission_id' => $emission->id,
        'bank_id' => $bank->id,
        'fund_type_id' => $type->id,
        'fund_name_id' => $name->id,
        'fund_application_id' => $application->id,
        'agency' => '1234-5',
        'account' => sprintf('%05d-%d', 20000 + $sequence->index, $sequence->index % 10),
    ])->create();

    $service = app(FundBankAccountDiagnosticService::class);

    $report = $service->diagnose(fundDiagnosticConnection(), 7);

    expect($report->totals['total_funds'])->toBe(120)
        ->and($report->totals['parseable_funds'])->toBe(120)
        ->and($report->totals['distinct_strict_accounts'])->toBe(120)
        ->and($report->preview)->toHaveCount(120)
        ->and($report->chunkSize)->toBe(7);

    $payload = fundDiagnosticJson(['--chunk' => 7]);

    expect($payload['totals']['total_funds'])->toBe(120)
        ->and($payload['chunk_size'])->toBe(7);
});

it('honors the safety limit and reports truncation', function () {
    BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12346-6']);
    BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12347-6']);

    $payload = fundDiagnosticJson(['--limit' => 2, '--preview' => true]);

    expect($payload['totals']['total_funds'])->toBe(2)
        ->and($payload['limit'])->toBe(2)
        ->and($payload['truncated'])->toBeTrue()
        ->and($payload['preview'])->toHaveCount(2);
});

it('returns deterministic results across repeated runs', function () {
    $bank = Bank::factory()->create();

    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '0012345-6']);
    BankDiagnosticFixture::fund(['agency' => 'nope', 'account' => '12345-6']);

    $service = app(FundBankAccountDiagnosticService::class);

    $first = $service->diagnose(fundDiagnosticConnection())->toArray();
    $second = $service->diagnose(fundDiagnosticConnection())->toArray();

    unset($first['generated_at'], $second['generated_at']);

    expect($second)->toBe($first);
});

it('rejects invalid chunk sizes and limits', function () {
    $service = app(FundBankAccountDiagnosticService::class);

    expect(fn () => $service->diagnose(fundDiagnosticConnection(), 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->diagnose(fundDiagnosticConnection(), 10, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('performs zero database mutations during the diagnostic', function () {
    $bank = Bank::factory()->create();

    $fund = BankDiagnosticFixture::fund(['bank_id' => $bank->id, 'agency' => '1234-5', 'account' => '12345-6']);
    BankDiagnosticFixture::matchingAccount($fund);

    $baseline = app(FinancialIntegrityBaseline::class);
    $before = $baseline->snapshot(fundDiagnosticConnection());

    $connection = fundDiagnosticConnection();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => $connection,
        '--confirm-connection' => $connection,
        '--source' => 'synthetic',
        '--conflicts' => true,
        '--preview' => true,
        '--baseline' => true,
    ])->assertSuccessful();

    $after = $baseline->snapshot($connection);
    $comparison = $baseline->compare($before, $after);

    expect($comparison->matches)->toBeTrue()
        ->and(Fund::query()->count())->toBe(1)
        ->and(BankAccount::query()->count())->toBe(1)
        ->and($fund->refresh()->bank_account_id)->toBeNull();
});

it('issues only read queries while diagnosing', function () {
    BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12345-6']);

    $verbs = [];

    DB::listen(function ($query) use (&$verbs): void {
        $verbs[] = strtolower((string) strtok(ltrim($query->sql), " \t\n\r\0\x0B("));
    });

    app(FundBankAccountDiagnosticService::class)->diagnose(fundDiagnosticConnection());
    app(FinancialIntegrityBaseline::class)->snapshot(fundDiagnosticConnection());

    expect($verbs)->not->toBe([])
        ->and($verbs)->each->toBeIn(['select', 'show', 'describe', 'desc', 'explain', 'pragma']);
});

it('masks bank identifiers in table, json and csv output', function () {
    BankDiagnosticFixture::fund([
        'agency' => '0123-4',
        'account' => '0012345-6',
        'conta_azul_account_id' => 'ca-secret-account-9',
    ]);

    $tableOutput = fundDiagnosticRawOutput(['--conflicts' => true, '--preview' => true]);

    expect($tableOutput)->toContain('*****45-6')
        ->and($tableOutput)->not->toContain('0012345')
        ->and($tableOutput)->not->toContain('ca-secret-account-9');

    $json = fundDiagnosticJson(['--conflicts' => true, '--preview' => true]);

    expect(json_encode($json))->not->toContain('0012345')
        ->and(json_encode($json))->not->toContain('ca-secret-account-9')
        ->and($json['preview'][0]['masked_account'])->toBe('*****45-6');

    $csv = fundDiagnosticRawOutput(['--format' => 'csv']);

    expect($csv)->toContain('fund_id,')
        ->and($csv)->toContain('*****45-6')
        ->and($csv)->not->toContain('0012345');
});

it('writes the structured artifact only to an explicitly requested path', function () {
    BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12345-6']);

    Storage::disk('local')->makeDirectory('tests-tmp');

    $path = Storage::disk('local')->path('tests-tmp/bank-diagnostic.json');

    $connection = fundDiagnosticConnection();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => $connection,
        '--confirm-connection' => $connection,
        '--source' => 'synthetic',
        '--format' => 'json',
        '--output' => $path,
    ])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $artifact = json_decode((string) file_get_contents($path), true);

    expect($artifact['totals']['total_funds'])->toBe(1)
        ->and($artifact['read_only'])->toBeTrue();
});

it('includes the integrity baseline when asked', function () {
    BankDiagnosticFixture::fund(['agency' => '1234-5', 'account' => '12345-6']);

    $payload = fundDiagnosticJson(['--baseline' => true]);

    expect($payload)->toHaveKey('baseline')
        ->and($payload['baseline']['tables']['funds']['row_count'])->toBe(1)
        ->and($payload['baseline']['tables']['funds']['digest'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($payload['baseline']['tables'])->toHaveKeys([
            'funds',
            'fund_balance_histories',
            'guarantees',
            'guarantee_events',
            'guarantee_valuations',
            'guarantee_monthly_positions',
            'expenses',
            'expense_histories',
        ]);
});

it('declares the data source on every run', function () {
    $payload = fundDiagnosticJson();

    expect($payload['connection'])->toBe(fundDiagnosticConnection())
        ->and($payload['source'])->toBe('synthetic')
        ->and($payload['read_only'])->toBeTrue();

    $connection = fundDiagnosticConnection();

    $this->artisan('funds:bank-accounts:diagnose', [
        '--connection' => $connection,
        '--confirm-connection' => $connection,
        '--source' => 'staging',
    ])
        ->expectsOutputToContain('staging')
        ->expectsOutputToContain('SOMENTE LEITURA')
        ->assertSuccessful();
});

it('documents the unique-index collation difference between mysql and sqlite', function () {
    $bank = Bank::factory()->create();

    $attributes = [
        'bank_id' => $bank->id,
        'agency_number' => '1234',
        'account_number' => '12345',
        'account_digit' => '6',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Bypasses the model (which uppercases digits on save) to show what each
    // driver considers a duplicate: MySQL utf8mb4_unicode_ci folds 'x'/'X',
    // SQLite compares bytes. The diagnostic groups in PHP on normalized
    // parts, so its result is identical on both drivers either way.
    DB::table('bank_accounts')->insert([...$attributes, 'agency_digit' => 'X']);

    if (DB::getDriverName() === 'mysql') {
        expect(fn () => DB::table('bank_accounts')->insert([...$attributes, 'agency_digit' => 'x']))
            ->toThrow(QueryException::class);
    } else {
        DB::table('bank_accounts')->insert([...$attributes, 'agency_digit' => 'x']);

        expect(BankAccount::query()->count())->toBe(2);
    }
});
