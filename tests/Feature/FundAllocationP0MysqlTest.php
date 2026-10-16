<?php

use App\Exceptions\Funds\FundAllocationException;
use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\Fund;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Models\User;
use App\Services\Funds\FundAllocationService;
use App\Support\Funds\FundAllocationMoney;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Laravel\SerializableClosure\SerializableClosure;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar locks entre aprovações, edições de fundo e registros de referência.');
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
 * @param  array{action: string, revision_id?: int, actor_id?: int, key?: string, fund_id?: int, application_id?: int, competence?: string, amount?: string, gate?: string}  $instruction
 */
function fundAllocationP0Task(array $instruction): Closure
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
                'unlink_fund' => Fund::query()->whereKey($instruction['fund_id'])->update([
                    'bank_account_id' => BankApplication::query()->findOrFail($instruction['application_id'])->bank_account_id,
                    'bank_application_id' => $instruction['application_id'],
                ]),
                'record_reference' => $service->recordReferenceBalance(
                    User::query()->findOrFail($instruction['actor_id']),
                    BankApplication::query()->findOrFail($instruction['application_id']),
                    $instruction['competence'],
                    $instruction['amount'],
                ),
                // Holds the fund row lock, signals the gate, sleeps, then
                // moves the fund — the deterministic concurrent editor.
                'hold_fund_and_move' => (function () use ($instruction): void {
                    $config = config('database.connections.mysql');
                    $pdo = new PDO(
                        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
                        $config['username'],
                        $config['password'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                    );
                    $pdo->exec('START TRANSACTION');

                    $statement = $pdo->prepare('SELECT id FROM funds WHERE id = ? FOR UPDATE');
                    $statement->execute([$instruction['fund_id']]);
                    $statement->fetchAll();

                    file_put_contents($instruction['gate'], 'locked');
                    sleep(4);

                    $target = BankApplication::query()->findOrFail($instruction['application_id']);
                    $update = $pdo->prepare('UPDATE funds SET bank_account_id = ?, bank_application_id = ? WHERE id = ?');
                    $update->execute([$target->bank_account_id, $target->getKey(), $instruction['fund_id']]);

                    $pdo->exec('COMMIT');
                })(),
            };

            return ['success' => true, 'exception' => null, 'message' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage()];
        }
    };
}

/**
 * @return array{application: BankApplication, other_application: BankApplication, fund: Fund, snapshot: BankApplicationBalanceSnapshot, revision: FundAllocationRevision, maker: User, checker: User, original_emission_id: int}
 */
function fundAllocationP0Scenario(string $competence = '2026-09'): array
{
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $otherApplication = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, $competence, '1000.00');
    $draft = $service->createDraft($maker, $application, $competence, $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '600.00'],
    ]);
    $submitted = $service->submitForReview($maker, $draft);

    return [
        'application' => $application,
        'other_application' => $otherApplication,
        'fund' => $fund,
        'snapshot' => $snapshot,
        'revision' => $submitted,
        'maker' => $maker,
        'checker' => $checker,
        'original_emission_id' => (int) $fund->emission_id,
    ];
}

it('observes a fund edit committed while the approval waited on the fund lock', function () {
    $scenario = fundAllocationP0Scenario();
    $gate = temporaryTestFilePath('fund-allocation-p0-gate', 'lock');
    file_put_contents($gate, '');

    try {
        // The background editor takes the fund row lock first (the gate file
        // proves it) and only commits the move 4s later. The approval below
        // must block on that lock and then validate against the MOVED fund —
        // rejecting it — instead of approving from a stale snapshot.
        $command = Application::formatCommandString('invoke-serialized-closure');
        $invoked = Process::path(base_path())->env([
            '__LARAVEL_CONTEXT' => json_encode(Context::dehydrate()),
            'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure(
                fundAllocationP0Task([
                    'action' => 'hold_fund_and_move',
                    'fund_id' => $scenario['fund']->id,
                    'application_id' => $scenario['other_application']->id,
                    'gate' => $gate,
                ])
            ))),
        ])->timeout(120)->start($command);

        $locked = false;
        for ($i = 0; $i < 300 && ! $locked; $i++) {
            usleep(100_000);
            $locked = file_get_contents($gate) === 'locked';
        }

        expect($locked)->toBeTrue('the concurrent editor never took the fund lock');

        $service = FundAllocationFixture::service();

        expect(fn () => $service->approve($scenario['checker'], $scenario['revision'], 'p0-deterministic-1'))
            ->toThrow(FundAllocationException::class, 'não está mais vinculado');

        $result = $invoked->wait();

        expect($result->successful())->toBeTrue('editor output: '.$result->errorOutput().$result->output());

        $decoded = json_decode($result->output(), true);
        $editorResult = unserialize($decoded['result']);

        expect($editorResult['success'])->toBeTrue();
        expect($scenario['revision']->refresh()->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW)
            ->and(FundAllocationPeriod::query()->firstOrFail()->effective_revision_id)->toBeNull()
            ->and((int) $scenario['fund']->refresh()->bank_application_id)->toBe($scenario['other_application']->id);
    } finally {
        @unlink($gate);
    }
})->group('mysql');

it('keeps every effective allocation consistent when approval races a fund edit', function () {
    // Three fresh scenarios vary the interleaving: the approval may run
    // before or after the concurrent unlink. Every outcome must be one of
    // the two consistent states — never a committed approval that skipped
    // validation, and never a lock-ordering deadlock.
    for ($round = 1; $round <= 3; $round++) {
        $competence = sprintf('2026-%02d', 3 + $round);
        $scenario = fundAllocationP0Scenario($competence);

        $results = Concurrency::driver('process')->run([
            'approve' => fundAllocationP0Task([
                'action' => 'approve',
                'actor_id' => $scenario['checker']->id,
                'revision_id' => $scenario['revision']->id,
                'key' => "p0-race-{$round}",
            ]),
            'unlink' => fundAllocationP0Task([
                'action' => 'unlink_fund',
                'fund_id' => $scenario['fund']->id,
                'application_id' => $scenario['other_application']->id,
            ]),
        ]);

        expect($results['unlink']['success'])->toBeTrue();

        $revision = $scenario['revision']->refresh();
        $period = FundAllocationPeriod::query()
            ->where('bank_application_id', $scenario['application']->id)
            ->firstOrFail();

        if ($results['approve']['success']) {
            // The approval validated and committed first; the fund moved
            // after. The effective lines keep the linkage frozen at commit
            // time, and the fund's new linkage only affects future drafts.
            $line = $revision->lines()->firstOrFail();

            expect($revision->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
                ->and((int) $period->effective_revision_id)->toBe($revision->id)
                ->and((int) $line->emission_id)->toBe($scenario['original_emission_id'])
                ->and(FundAllocationMoney::toCents((string) $revision->total_amount))
                ->toBeLessThanOrEqual(FundAllocationMoney::toCents((string) $revision->reference_amount));

            expect(Activity::query()
                ->where('log_name', FundAllocationService::AUDIT_LOG)
                ->where('properties->event', 'approved')
                ->where('properties->revision_id', $revision->id)
                ->count())->toBe(1);
        } else {
            // The unlink committed first; the approval observed it under its
            // fund locks and refused the stale revision.
            expect($results['approve']['exception'])->toBe(FundAllocationException::class)
                ->and($results['approve']['message'])->toContain('não está mais vinculado')
                ->and($revision->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW)
                ->and($period->effective_revision_id)->toBeNull();

            expect(Activity::query()
                ->where('log_name', FundAllocationService::AUDIT_LOG)
                ->where('properties->event', 'approval_rejected')
                ->where('properties->revision_id', $revision->id)
                ->count())->toBe(1);
        }

        expect(FundAllocationRevision::query()
            ->where('fund_allocation_period_id', $period->id)
            ->where('status', FundAllocationRevision::STATUS_APPROVED)
            ->count())->toBeLessThanOrEqual(1);
    }
})->group('mysql');

it('serializes reference recording against approval without losing versions', function () {
    for ($round = 1; $round <= 2; $round++) {
        $competence = sprintf('2026-%02d', 6 + $round);
        $scenario = fundAllocationP0Scenario($competence);

        $results = Concurrency::driver('process')->run([
            'approve' => fundAllocationP0Task([
                'action' => 'approve',
                'actor_id' => $scenario['checker']->id,
                'revision_id' => $scenario['revision']->id,
                'key' => "p0-ref-{$round}",
            ]),
            'record_reference' => fundAllocationP0Task([
                'action' => 'record_reference',
                'actor_id' => $scenario['maker']->id,
                'application_id' => $scenario['application']->id,
                'competence' => $competence,
                'amount' => '1200.00',
            ]),
        ]);

        expect($results['record_reference']['success'])->toBeTrue();

        $versions = BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $scenario['application']->id)
            ->orderBy('version')
            ->pluck('version')
            ->all();

        expect($versions)->toBe([1, 2]);

        $revision = $scenario['revision']->refresh();

        if ($results['approve']['success']) {
            // The approval committed against v1; v2 exists separately and a
            // governed correction is the only way to allocate against it.
            expect($revision->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
                ->and((int) $revision->bank_application_balance_snapshot_id)->toBe($scenario['snapshot']->id)
                ->and((string) $revision->reference_amount)->toBe('1000.00');
        } else {
            // The new version committed first; the approval observed it under
            // the application lock and refused the stale revision.
            expect($results['approve']['exception'])->toBe(FundAllocationException::class)
                ->and($revision->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW);
        }
    }
})->group('mysql');
