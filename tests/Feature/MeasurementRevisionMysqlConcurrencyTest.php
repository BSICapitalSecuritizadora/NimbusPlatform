<?php

use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementRevisionDifference;
use App\Models\User;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * As revisões de medição contra o MySQL de verdade, com transações em
 * processos próprios. Toda ação de revisão trava a Operation como primeira
 * instrução da transação e depois a família inteira, pela chave primária; no
 * REPEATABLE READ a fotografia de quem esperou nasce depois desse lock e
 * enxerga o que o outro acabou de commitar -- a revisão criada, a revisão que
 * virou vigente, a ocupação transferida, o pagamento registrado --, e recusa
 * em vez de decidir sobre o estado anterior. O SQLite serializa escritores e
 * nunca mostra nada disso.
 */
pest()->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para validar locks concorrentes. Execute: ./vendor/bin/sail composer test:measurements:mysql');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de composer test:measurements:mysql.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
    Notification::fake();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Uma ação num processo próprio. Quem recebe `lock_marker` segura o lock da
 * Operation por 750 ms depois de obtê-lo; quem recebe `wait_for_marker` só
 * começa depois que o outro o tem. `lock_wait_ms` é quanto a própria
 * transação esperou pelo lock da Operation.
 *
 * @param  array<string, mixed>  $instruction
 */
function revisionRaceTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        Storage::forgetDisk('local');
        Notification::fake();
        $lockWait = ['ms' => null];

        DB::listen(static function (QueryExecuted $query) use ($instruction, &$lockWait): void {
            static $marked = false;
            $sql = strtolower($query->sql);

            if (! str_contains($sql, '`operations`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $lockWait['ms'] ??= $query->time;

            if ($marked || ! isset($instruction['lock_marker'])) {
                return;
            }

            $marked = true;
            file_put_contents($instruction['lock_marker'], 'locked');
            usleep(750_000);
        });

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou o lock da Operation.');
                }
            }

            $actor = User::query()->findOrFail($instruction['actor_id']);
            $measurement = isset($instruction['measurement_id']) ? Measurement::query()->findOrFail($instruction['measurement_id']) : null;

            $result = match ($instruction['action']) {
                'create_revision' => app(MeasurementRevisionService::class)->create($measurement, $actor, 'Correção concorrente.')->getKey(),
                'submit_revision' => app(MeasurementRevisionService::class)->submit($measurement, $actor),
                'approve' => app(MeasurementWorkflow::class)->approve(
                    $measurement,
                    $actor,
                    $instruction['notes'] ?? null,
                    $instruction['engineering_progress'] ?? [],
                    expectedStage: $instruction['stage'],
                    expectedRevision: $instruction['revision'],
                ),
                'reject' => app(MeasurementWorkflow::class)->reject(
                    $measurement,
                    $actor,
                    'Recusa concorrente.',
                    expectedStage: $instruction['stage'],
                    expectedRevision: $instruction['revision'],
                ),
                'payment' => app(MeasurementWorkflow::class)->registerPayment(
                    $measurement,
                    $actor,
                    $instruction['payment'],
                    expectedRevision: $instruction['revision'],
                )->getKey(),
                'submit_measurement' => Scenario::submitUnderOperationLock($instruction, $actor),
            };

            return ['success' => true, 'result' => is_int($result) ? $result : null, 'exception' => null, 'message' => null, 'lock_wait_ms' => $lockWait['ms']];
        } catch (ValidationException $exception) {
            return ['success' => false, 'result' => null, 'exception' => $exception::class, 'message' => collect($exception->errors())->flatten()->implode(' '), 'lock_wait_ms' => $lockWait['ms']];
        } catch (Throwable $exception) {
            return ['success' => false, 'result' => null, 'exception' => $exception::class, 'message' => $exception->getMessage(), 'lock_wait_ms' => $lockWait['ms']];
        }
    };
}

/**
 * Roda as duas ações em processos próprios: a primeira segura o lock da
 * Operation, a segunda só começa depois disso.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function revisionRace(array $scenario, array $first, array $second): array
{
    $marker = temporaryTestFilePath('revision-race-lock', 'lock');
    @unlink($marker);
    $base = [
        'actor_id' => $scenario['actor']->id,
        'storage_root' => Storage::disk('local')->path(''),
    ];

    $results = Concurrency::driver('process')->run([
        revisionRaceTask(['lock_marker' => $marker] + $first + $base),
        revisionRaceTask(['wait_for_marker' => $marker] + $second + $base),
    ]);
    @unlink($marker);

    return $results;
}

/**
 * @return array<string, mixed>
 */
function revisionRaceApproval(Measurement $measurement, array $engineeringProgress = [], ?string $notes = null): array
{
    $measurement = $measurement->fresh();

    return [
        'action' => 'approve',
        'measurement_id' => $measurement->id,
        'stage' => app(MeasurementWorkflow::class)->unifiedStage($measurement),
        'revision' => (int) $measurement->workflow_revision,
        'engineering_progress' => $engineeringProgress,
        'notes' => $notes,
    ];
}

/**
 * @return array<string, mixed>
 */
function revisionRacePayment(array $scenario, Measurement $measurement, string $amount): array
{
    return [
        'action' => 'payment',
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->fresh()->workflow_revision,
        'payment' => [
            'plan_set_id' => $scenario['planSet']->id,
            'pay_date' => '2026-07-20',
            'amount' => $amount,
            'method' => 'TED',
        ],
    ];
}

it('creates a single R1 when two people revise the same measurement at the same time on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $create = ['action' => 'create_revision', 'measurement_id' => $may->id];

    $results = revisionRace($scenario, $create, $create);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($results[1]['message'])->toContain('já tem a revisão R1 em andamento')
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(Measurement::query()->where('revision_family_id', $may->id)->orderBy('revision_number')->pluck('revision_number')->all())->toBe([0, 1])
        ->and(Measurement::query()->whereKey($results[0]['result'])->value('revision_status'))->toBe(MeasurementRevisionStatus::Draft);
});

it('lets either the revision submission or the payment of the unpaid measurement happen, never both, on MySQL', function (bool $submissionFirst) {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $submit = ['action' => 'submit_revision', 'measurement_id' => $draft->id];
    $payment = revisionRacePayment($scenario, $may, Scenario::expected(10));

    $results = $submissionFirst
        ? revisionRace($scenario, $submit, $payment)
        : revisionRace($scenario, $payment, $submit);
    [$winner, $loser] = $results;

    expect($winner['success'])->toBeTrue()
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250);

    if ($submissionFirst) {
        expect($loser['message'])->toContain('revisão R1 em análise')
            ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
            ->and($may->payments()->count())->toBe(0);
    } else {
        expect($loser['message'])->toContain('não pode mais ser revisada')
            ->and($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
            ->and($may->payments()->count())->toBe(1);
    }
})->with([
    'submission first' => [true],
    'payment first' => [false],
]);

it('refuses the payment of the replaced measurement while its revision becomes effective on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::engineeringApproved($scenario, $may, 8);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);

    $results = revisionRace($scenario, revisionRaceApproval($revision), revisionRacePayment($scenario, $may, Scenario::expected(10)));

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->fresh()->status)->toBe('superseded')
        ->and($may->payments()->count())->toBe(0);
});

it('never lets a new submission take the planned measurement while the claim moves to the revision on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::engineeringApproved($scenario, $may, 12);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);
    $line = $scenario['lines']['2026-05'];

    $results = revisionRace($scenario, revisionRaceApproval($revision), [
        'action' => 'submit_measurement',
        'operation_id' => $scenario['operation']->id,
        'reference_month' => '2026-05-01',
        'plan_set_id' => $scenario['planSet']->id,
        'plan_line_id' => $line->id,
    ]);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        // Quem esperou o lock já viu a ocupação com a revisão, nunca a linha livre.
        ->and($results[1]['message'])->toContain("já está ocupada pela medição #{$revision->id}")
        ->and(MeasurementAsset::query()->where('line_claim_key', $line->lineage_key)->value('measurement_id'))->toBe($revision->id)
        ->and(Measurement::query()->where('operation_id', $scenario['operation']->id)->where('reference_month', '2026-05-01')->count())->toBe(2);
});

it('lets only one of the revision activation and another approval fit under 100% on MySQL', function (bool $activationFirst) {
    $scenario = Scenario::plan(initialPercent: '80.00');
    $may = Scenario::finalized($scenario, '2026-05', 10);
    // Sozinha, a revisão cabe: 80 − 10 + 15 = 95.
    $revision = Scenario::engineeringApproved($scenario, $may, 15);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);
    // Sozinho, junho cabe: 80 + 10 + 6 = 96. Juntos: 80 + 15 + 6 = 101.
    $june = MeasurementPhysicalProgressScenario::measurement($scenario, '2026-06');
    $activation = revisionRaceApproval($revision);
    $juneApproval = revisionRaceApproval($june, [$scenario['planSet']->id => 6]);

    $results = $activationFirst
        ? revisionRace($scenario, $activation, $juneApproval)
        : revisionRace($scenario, $juneApproval, $activation);
    [$winner, $loser] = $results;

    expect($winner['success'])->toBeTrue()
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(ValidationException::class)
        ->and($loser['message'])->toContain('100%')
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(MeasurementPhysicalProgressScenario::progress($scenario)->currentPercent())->toBe($activationFirst ? '95.00' : '96.00')
        ->and($revision->fresh()->revisionStatus())->toBe($activationFirst ? MeasurementRevisionStatus::Effective : MeasurementRevisionStatus::UnderReview)
        ->and($may->fresh()->revisionStatus())->toBe($activationFirst ? MeasurementRevisionStatus::Superseded : MeasurementRevisionStatus::Effective);
})->with([
    'activation first' => [true],
    'other approval first' => [false],
]);

it('supersedes the effective revision once when two Compliance approvals race on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::engineeringApproved($scenario, $may, 12);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']);
    $approval = revisionRaceApproval($revision);

    $results = revisionRace($scenario, $approval, $approval);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(Activity::query()->where('description', 'measurement_revision_became_effective')->count())->toBe(1)
        ->and(Activity::query()->where('description', 'measurement_revision_superseded')->count())->toBe(1)
        ->and(MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->count())->toBe(1)
        ->and(MeasurementAsset::query()->whereNotNull('line_claim_key')->where('measurement_id', $revision->id)->count())->toBe(1)
        ->and(MeasurementAsset::query()->whereNotNull('line_claim_key')->where('measurement_id', $may->id)->count())->toBe(0);
});

it('keeps the effective revision and its claim when Engineering rejects the revision while another reviewer approves it on MySQL', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
    $fresh = $revision->fresh();
    $reject = ['action' => 'reject', 'measurement_id' => $revision->id, 'stage' => 1, 'revision' => (int) $fresh->workflow_revision];

    $results = revisionRace($scenario, $reject, revisionRaceApproval($revision, [$scenario['planSet']->id => 12]));

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['success'])->toBeFalse()
        ->and($results[1]['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($results[1]['lock_wait_ms'])->toBeGreaterThan(250)
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Rejected)
        ->and($revision->fresh()->engineering_snapshot)->toBeNull()
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($may->assets()->sole()->line_claim_key)->toBe($scenario['lines']['2026-05']->lineage_key)
        ->and(MeasurementPhysicalProgressScenario::progress($scenario)->currentPercent())->toBe('10.00');
});
