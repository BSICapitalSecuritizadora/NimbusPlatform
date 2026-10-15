<?php

use App\Exceptions\Funds\FundAllocationException;
use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Models\User;
use App\Services\Funds\FundAllocationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar locks, CHECKs e processos disputando a mesma aprovação.');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use um banco temporário descartável (nimbus_parity_check_*).',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array{action: string, revision_id?: int, actor_id?: int, key?: string, application_id?: int, competence?: string, amount?: string}  $instruction
 */
function fundAllocationMysqlTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        try {
            $service = app(FundAllocationService::class);

            match ($instruction['action']) {
                'approve' => $service->approve(
                    User::query()->findOrFail($instruction['actor_id']),
                    FundAllocationRevision::query()->findOrFail($instruction['revision_id']),
                    $instruction['key'],
                ),
                'record_reference' => $service->recordReferenceBalance(
                    User::query()->findOrFail($instruction['actor_id']),
                    BankApplication::query()->findOrFail($instruction['application_id']),
                    $instruction['competence'],
                    $instruction['amount'],
                ),
                'create_draft' => $service->createDraft(
                    User::query()->findOrFail($instruction['actor_id']),
                    BankApplication::query()->findOrFail($instruction['application_id']),
                    $instruction['competence'],
                    $instruction['snapshot_id'],
                    $instruction['lines'],
                ),
            };

            return ['success' => true, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage()];
        }
    };
}

/**
 * @return array{application: BankApplication, snapshot: BankApplicationBalanceSnapshot, revision: FundAllocationRevision, maker: User, checker_a: User, checker_b: User}
 */
function fundAllocationMysqlApprovalScenario(): array
{
    $maker = FundAllocationFixture::maker();
    $checkerA = FundAllocationFixture::checker();
    $checkerB = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '600.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);

    return [
        'application' => $application,
        'snapshot' => $snapshot,
        'revision' => $submitted,
        'maker' => $maker,
        'checker_a' => $checkerA,
        'checker_b' => $checkerB,
    ];
}

it('approves exactly once when two processes race with different keys', function () {
    $scenario = fundAllocationMysqlApprovalScenario();

    $results = Concurrency::driver('process')->run([
        fundAllocationMysqlTask([
            'action' => 'approve',
            'actor_id' => $scenario['checker_a']->id,
            'revision_id' => $scenario['revision']->id,
            'key' => 'race-key-a',
        ]),
        fundAllocationMysqlTask([
            'action' => 'approve',
            'actor_id' => $scenario['checker_b']->id,
            'revision_id' => $scenario['revision']->id,
            'key' => 'race-key-b',
        ]),
    ]);

    $successes = collect($results)->where('success', true)->count();
    $failures = collect($results)->where('success', false)->values();

    expect($successes)->toBe(1)
        ->and($failures)->toHaveCount(1)
        ->and($failures->first()['exception'])->toBe(FundAllocationException::class);

    $period = FundAllocationPeriod::query()->firstOrFail();
    $revision = $scenario['revision']->refresh();

    expect($revision->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
        ->and((int) $period->effective_revision_id)->toBe($revision->id)
        ->and($period->open_revision_id)->toBeNull()
        ->and(FundAllocationRevision::query()->where('status', FundAllocationRevision::STATUS_APPROVED)->count())->toBe(1);
})->group('mysql');

it('replays safely when two processes race with the same key', function () {
    $scenario = fundAllocationMysqlApprovalScenario();

    $results = Concurrency::driver('process')->run([
        fundAllocationMysqlTask([
            'action' => 'approve',
            'actor_id' => $scenario['checker_a']->id,
            'revision_id' => $scenario['revision']->id,
            'key' => 'race-same-key',
        ]),
        fundAllocationMysqlTask([
            'action' => 'approve',
            'actor_id' => $scenario['checker_b']->id,
            'revision_id' => $scenario['revision']->id,
            'key' => 'race-same-key',
        ]),
    ]);

    // Both callers observe success, but a single approval happened: the loser
    // replayed the winner's approved revision under the same key.
    expect(collect($results)->where('success', true)->count())->toBe(2)
        ->and($scenario['revision']->refresh()->approval_idempotency_key)->toBe('race-same-key')
        ->and(FundAllocationRevision::query()->where('status', FundAllocationRevision::STATUS_APPROVED)->count())->toBe(1)
        ->and((int) FundAllocationPeriod::query()->firstOrFail()->effective_revision_id)->toBe($scenario['revision']->id);
})->group('mysql');

it('versions concurrent reference recordings without duplicates', function () {
    $recorderA = FundAllocationFixture::maker();
    $recorderB = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();

    $results = Concurrency::driver('process')->run([
        fundAllocationMysqlTask([
            'action' => 'record_reference',
            'actor_id' => $recorderA->id,
            'application_id' => $application->id,
            'competence' => '2026-09',
            'amount' => '1000.00',
        ]),
        fundAllocationMysqlTask([
            'action' => 'record_reference',
            'actor_id' => $recorderB->id,
            'application_id' => $application->id,
            'competence' => '2026-09',
            'amount' => '1000.00',
        ]),
    ]);

    expect(collect($results)->where('success', true)->count())->toBe(2);

    $versions = BankApplicationBalanceSnapshot::query()
        ->where('bank_application_id', $application->id)
        ->orderBy('version')
        ->pluck('version')
        ->all();

    expect($versions)->toBe([1, 2]);
})->group('mysql');

it('keeps a single open revision when two processes draft concurrently', function () {
    $makerA = FundAllocationFixture::maker();
    $makerB = FundAllocationFixture::maker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($makerA, $application, '2026-09', '1000.00');
    $lines = [['fund_id' => $fund->id, 'amount' => '10.00']];

    $results = Concurrency::driver('process')->run([
        fundAllocationMysqlTask([
            'action' => 'create_draft',
            'actor_id' => $makerA->id,
            'application_id' => $application->id,
            'competence' => '2026-09',
            'snapshot_id' => $snapshot->id,
            'lines' => $lines,
        ]),
        fundAllocationMysqlTask([
            'action' => 'create_draft',
            'actor_id' => $makerB->id,
            'application_id' => $application->id,
            'competence' => '2026-09',
            'snapshot_id' => $snapshot->id,
            'lines' => $lines,
        ]),
    ]);

    expect(collect($results)->where('success', true)->count())->toBe(1)
        ->and(FundAllocationRevision::query()->count())->toBe(1)
        ->and(FundAllocationPeriod::query()->count())->toBe(1);
})->group('mysql');

it('refuses negative amounts at the database level', function () {
    $application = FundAllocationFixture::application();
    $maker = FundAllocationFixture::maker();

    // Raw inserts bypass the service: the MySQL CHECK constraints are the
    // last line of defense for hand-written SQL.
    expect(fn () => DB::table('bank_application_balance_snapshots')->insert([
        'bank_application_id' => $application->id,
        'competence' => '2026-09-01',
        'amount' => '-0.01',
        'source' => 'manual',
        'captured_at' => now(),
        'recorded_by_user_id' => $maker->id,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('fund_allocation_lines')->insert([
        'fund_allocation_revision_id' => 1,
        'fund_id' => 1,
        'emission_id' => 1,
        'amount' => '-1.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('mysql');

it('refuses duplicate fund lines at the database level', function () {
    $scenario = fundAllocationMysqlApprovalScenario();

    $line = $scenario['revision']->lines()->firstOrFail();

    expect(fn () => DB::table('fund_allocation_lines')->insert([
        'fund_allocation_revision_id' => $line->fund_allocation_revision_id,
        'fund_id' => $line->fund_id,
        'emission_id' => $line->emission_id,
        'amount' => '1.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('mysql');
